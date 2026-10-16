<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Currencies;
use App\Support\RevenueSplit;
use App\Support\ScopedTotals;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The trading-desk "Live activity" feed on /dashboard, across every game
 * backend in the catalogue (PRD §4 P6): today's and yesterday's KPIs
 * summed per game, a seven-day handle sparkline summed by day, and the
 * most recent bets from every game merged newest-first into one shape.
 *
 * Each game's rows keep their own vocabulary (Fantasy picks and combined
 * odds, Vrrr Pha stakes and cash-out multipliers); normalise() maps them
 * onto one row the page can render. A backend that is down contributes
 * nothing and is named in `errors` rather than failing the page.
 *
 * Money is never added across currencies. `today`/`yesterday` carry counts
 * only; the money is in `money_today`/`money_yesterday`, one entry per
 * currency, and `per_game` has one row per (game, currency). A tenant's feed
 * is in its currency. A platform feed whose tenants are all in one currency
 * reads the backends' cross-tenant summary; once tenants hold different
 * currencies it reads per-tenant rows (statsByTenant) and folds them by each
 * tenant's currency, and the handle sparkline (a cross-tenant sum by day)
 * is left out rather than drawn from mixed currencies.
 */
class LiveActivity
{
    /** Counts summed across games and tenants; money is never in these buckets (see money_today). */
    private const COUNTS = ['bets_placed', 'active_players', 'wins', 'losses', 'pending', 'cashed', 'busted'];

    private const MONEY = ['total_wagered', 'total_paid_out', 'ggr'];

    public function __construct(
        private GameAdminClientFactory $factory,
        private LiveGameStats $stats,
    ) {}

    /**
     * @param  Collection<int, Game>  $games
     * @return array{today: array, yesterday: array, money_today: list<array>, money_yesterday: list<array>, spark: list<float>, recent_bets: list<array>, per_game: list<array>, errors: list<string>}
     */
    public function feed(Collection $games, ?Tenant $tenant, Carbon $now, int $recentLimit = 10): array
    {
        $today = $now->copy()->startOfDay();
        $yesterday = $today->copy()->subDay();
        $weekStart = $now->copy()->subDays(7)->startOfDay();

        // The one currency every figure is in, or null when the platform view spans several.
        $single = $tenant ? ScopedTotals::currencyOf($tenant) : $this->onlyCurrency();

        $todayTotal = [];
        $yesterdayTotal = [];
        $todayMoney = [];
        $yesterdayMoney = [];
        $sparkByDay = [];
        $recent = [];
        $perGame = [];
        $errors = [];

        foreach ($games as $game) {
            $key = $tenant ? $this->stats->tenantKey($game, $tenant) : null;
            try {
                $client = $this->factory->forGame($game);
                $t = $client->statsSummary($key, $today->toIso8601String(), $now->toIso8601String());
                $y = $client->statsSummary($key, $yesterday->toIso8601String(), $today->toIso8601String());
                $days = $client->statsByDay($key, $weekStart->toIso8601String(), $now->toIso8601String());
                $bets = $client->recentBets($key, $recentLimit)['data'] ?? [];
                if ($single === null) {
                    $tRows = $this->byCurrency($client->statsByTenant($today->toIso8601String(), $now->toIso8601String())['tenants'] ?? []);
                    $yRows = $this->byCurrency($client->statsByTenant($yesterday->toIso8601String(), $today->toIso8601String())['tenants'] ?? []);
                } else {
                    $tRows = [$this->moneyRow($single, $t)];
                    $yRows = [$this->moneyRow($single, $y)];
                }
            } catch (\Throwable $e) {
                Log::warning('Live activity fetch failed', ['game' => $game->slug, 'error' => $e->getMessage()]);
                $errors[] = $game->name;

                continue;
            }

            $todayTotal = $this->add($todayTotal, $t);
            $yesterdayTotal = $this->add($yesterdayTotal, $y);
            array_push($todayMoney, ...$tRows);
            array_push($yesterdayMoney, ...$yRows);
            foreach (array_values($days['days'] ?? $days['rows'] ?? []) as $i => $row) {
                $day = (string) ($row['day'] ?? $row['date'] ?? $i);
                $sparkByDay[$day] = ($sparkByDay[$day] ?? 0.0) + (float) ($row['total_wagered'] ?? 0);
            }
            foreach ($bets as $b) {
                $recent[] = $this->normalize($game, $b);
            }
            // One row per currency the game was played in today (always one for a single-currency feed).
            $gameCurrencies = Currencies::totalsByCurrency($tRows, [...self::MONEY, 'bets_placed', 'active_players']);
            foreach ($gameCurrencies as $c) {
                $perGame[] = [
                    'game_uuid' => $game->uuid,
                    'game_name' => $game->name,
                    'currency' => $c['currency'],
                    'bets_placed' => (int) $c['bets_placed'],
                    'active_players' => (int) $c['active_players'],
                    'total_wagered' => $c['total_wagered'],
                    'total_paid_out' => $c['total_paid_out'],
                    'ggr' => $c['ggr'],
                ];
            }
        }

        ksort($sparkByDay);
        usort($recent, fn ($a, $b) => strcmp($b['placed_at'] ?? '', $a['placed_at'] ?? ''));
        $recent = array_slice($recent, 0, $recentLimit);

        return [
            'today' => $todayTotal,
            'yesterday' => $yesterdayTotal,
            'money_today' => ScopedTotals::of($todayMoney, self::MONEY, $tenant),
            'money_yesterday' => ScopedTotals::of($yesterdayMoney, self::MONEY, $tenant),
            'spark' => $single === null ? [] : array_slice(array_values($sparkByDay), -8),
            'recent_bets' => $this->withNames($recent, $tenant),
            'per_game' => $perGame,
            'errors' => $errors,
        ];
    }

    /** Sum the count keys of two stats buckets. Money is summed per currency elsewhere. */
    private function add(array $total, array $bucket): array
    {
        foreach (self::COUNTS as $k) {
            if (! array_key_exists($k, $bucket) && ! array_key_exists($k, $total)) {
                continue;
            }
            $total[$k] = (int) ($total[$k] ?? 0) + (int) ($bucket[$k] ?? 0);
        }

        return $total;
    }

    /** A stats bucket's money (and the counts per_game shows) in one currency, exactly. */
    private function moneyRow(string $currency, array $s): array
    {
        $wagered = bcadd(RevenueSplit::decimal($s['total_wagered'] ?? 0), '0', 2);
        $paidOut = bcadd(RevenueSplit::decimal($s['total_paid_out'] ?? 0), '0', 2);

        return [
            'currency' => $currency,
            'total_wagered' => $wagered,
            'total_paid_out' => $paidOut,
            'ggr' => bcsub($wagered, $paidOut, 2),
            'bets_placed' => (string) (int) ($s['bets_placed'] ?? 0),
            'active_players' => (string) (int) ($s['active_players'] ?? 0),
        ];
    }

    /** statsByTenant rows tagged with each tenant's currency; a key no SSO tenant owns has none and is left out. */
    private function byCurrency(array $rows): array
    {
        $resolve = $this->stats->tenantResolver(collect($rows)->pluck('tenant_uuid')->filter()->values()->all());
        $out = [];
        foreach ($rows as $r) {
            $currency = ScopedTotals::currencyOf($resolve($r['tenant_uuid'] ?? null));
            if ($currency !== null) {
                $out[] = $this->moneyRow($currency, $r);
            }
        }

        return $out;
    }

    /** The platform's currency when every tenant is in the same one, else null. */
    private function onlyCurrency(): ?string
    {
        $codes = Tenant::query()->get(['id', 'currency', 'settings'])
            ->map(fn (Tenant $t) => ScopedTotals::currencyOf($t))
            ->unique()
            ->values();

        return $codes->count() === 1 ? $codes->first() : null;
    }

    /** One row shape for every game; see the class docblock. */
    private function normalize(Game $game, array $b): array
    {
        $isCrash = $game->slug === VrrrPhaAdminClient::GAME_SLUG;
        $outcome = (string) ($b['outcome'] ?? 'pending');
        $mapped = match ($outcome) {
            'cashed', 'win', 'won' => 'win',
            'busted', 'lost' => 'lost',
            'cancelled' => 'void',
            default => 'pending',
        };
        $mult = fn ($v) => number_format((float) $v, 2).'×';
        if (! $isCrash && isset($b['combined_odds'], $b['positions'])) {
            // Fantasy v2: four picks at an exact combined price; a win pays stake × odds (+ bonus).
            $names = $b['team_names'] ?? [];
            $detail = ($names ? implode(', ', array_slice($names, 0, 2)).(count($names) > 2 ? ' +'.(count($names) - 2) : '') : count($b['positions']).' picks')
                .' · '.$mult($b['combined_odds']);
            $odds = $b['combined_odds'];
            $potential = $mapped === 'win' ? ($b['winning_amount'] ?? $b['payout'] ?? 0) : ($b['potential_payout'] ?? self::times($b['stake'] ?? 0, $odds));
        } elseif ($isCrash) {
            $detail = match ($outcome) {
                'cashed' => 'Cashed out at '.$mult($b['cashout_multiplier'] ?? 0),
                'busted' => 'Crashed at '.$mult($b['crash_point'] ?? 0),
                'cancelled' => 'Cancelled, refunded',
                default => 'In play'.(isset($b['auto_cashout_target']) && $b['auto_cashout_target'] !== null ? ' · auto '.$mult($b['auto_cashout_target']) : ''),
            };
            $odds = $b['cashout_multiplier'] ?? $b['auto_cashout_target'] ?? null;
            $potential = $outcome === 'cashed' ? ($b['payout'] ?? 0) : ($odds !== null ? self::times($b['stake'] ?? 0, $odds) : 0);
        } else {
            $names = $b['team_names'] ?? [];
            $detail = $names ? implode(', ', array_slice($names, 0, 2)).(count($names) > 2 ? ' +'.(count($names) - 2) : '') : '—';
            $odds = $b['combined_odds'] ?? 0;
            $potential = $b['potential_payout'] ?? 0;
        }

        return [
            'id' => isset($b['id']) ? $game->slug.'-'.$b['id'] : null,
            'placed_at' => $b['placed_at'] ?? null,
            'game_name' => $game->name,
            'game_slug' => $game->slug,
            'user_uuid' => $b['user_uuid'] ?? null,
            'tenant_uuid' => $b['tenant_uuid'] ?? null,
            'round_number' => $b['round_number'] ?? $b['sequence'] ?? null,
            'team_names' => $b['team_names'] ?? [],
            'detail' => $detail,
            'bet_amount' => self::money($b['bet_amount'] ?? $b['stake'] ?? 0),
            'combined_odds' => (float) ($odds ?? 0),
            'potential_payout' => self::money($potential),
            'outcome' => $mapped,
            'winning_amount' => self::money($b['winning_amount'] ?? ($outcome === 'cashed' ? ($b['payout'] ?? 0) : 0)),
        ];
    }

    /** A backend amount as a 2dp decimal string (half-up past the cent), never a float. */
    private static function money(mixed $v): string
    {
        $d = RevenueSplit::decimal($v);

        return bcadd($d, str_starts_with($d, '-') ? '-0.005' : '0.005', 2);
    }

    /** stake × multiplier for a potential payout, exact before the cent. */
    private static function times(mixed $stake, mixed $odds): string
    {
        // Odds keep every decimal they arrive with (7.425), unlike money.
        $o = is_float($odds) ? rtrim(rtrim(number_format($odds, 6, '.', ''), '0'), '.') : RevenueSplit::decimal($odds);

        return bcmul(RevenueSplit::decimal($stake), $o, 6);
    }

    /** Player and tenant labels from the SSO, one lookup each; each bet carries its tenant's currency. */
    private function withNames(array $rows, ?Tenant $scope = null): array
    {
        $userUuids = collect($rows)->pluck('user_uuid')->filter()->unique()->values()->all();
        $users = $userUuids ? User::whereIn('uuid', $userUuids)->get()->keyBy('uuid') : collect();
        $resolve = $this->stats->tenantResolver(collect($rows)->pluck('tenant_uuid')->filter()->unique()->values()->all());

        return array_map(function (array $r) use ($users, $resolve, $scope) {
            $uuid = $r['user_uuid'];
            $u = $uuid ? $users->get($uuid) : null;
            $name = $u?->display_name ?? $u?->name ?? ($uuid ? 'Player '.substr($uuid, 0, 6) : '—');
            // Multibyte-safe: a byte of "—" (no player) would make the whole page unencodable.
            $initials = mb_strtoupper(collect(explode(' ', trim($name)))->filter()->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode('')) ?: '??';
            $tenant = $resolve($r['tenant_uuid']);
            unset($r['user_uuid']);

            return $r + [
                'player' => ['name' => $name, 'uuid_short' => $uuid ? strtoupper(substr($uuid, 0, 6)) : null, 'initials' => $initials],
                'tenant_name' => $tenant?->name,
                'currency' => ScopedTotals::currencyOf($tenant ?? $scope),
            ];
        }, $rows);
    }
}
