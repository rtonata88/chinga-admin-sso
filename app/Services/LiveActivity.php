<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Tenant;
use App\Models\User;
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
 */
class LiveActivity
{
    private const SUMMED = ['bets_placed', 'active_players', 'total_wagered', 'total_paid_out', 'ggr', 'deposit_wagered', 'deposit_paid_out', 'real_ggr', 'wins', 'losses', 'pending', 'cashed', 'busted'];

    public function __construct(
        private GameAdminClientFactory $factory,
        private LiveGameStats $stats,
    ) {}

    /**
     * @param  Collection<int, Game>  $games
     * @return array{today: array, yesterday: array, spark: list<float>, recent_bets: list<array>, per_game: list<array>, errors: list<string>}
     */
    public function feed(Collection $games, ?Tenant $tenant, Carbon $now, int $recentLimit = 10): array
    {
        $today = $now->copy()->startOfDay();
        $yesterday = $today->copy()->subDay();
        $weekStart = $now->copy()->subDays(7)->startOfDay();

        $todayTotal = [];
        $yesterdayTotal = [];
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
            } catch (\Throwable $e) {
                Log::warning('Live activity fetch failed', ['game' => $game->slug, 'error' => $e->getMessage()]);
                $errors[] = $game->name;

                continue;
            }

            $todayTotal = $this->add($todayTotal, $t);
            $yesterdayTotal = $this->add($yesterdayTotal, $y);
            foreach (array_values($days['days'] ?? $days['rows'] ?? []) as $i => $row) {
                $day = (string) ($row['day'] ?? $row['date'] ?? $i);
                $sparkByDay[$day] = ($sparkByDay[$day] ?? 0.0) + (float) ($row['total_wagered'] ?? 0);
            }
            foreach ($bets as $b) {
                $recent[] = $this->normalize($game, $b);
            }
            $perGame[] = [
                'game_uuid' => $game->uuid,
                'game_name' => $game->name,
                'bets_placed' => (int) ($t['bets_placed'] ?? 0),
                'active_players' => (int) ($t['active_players'] ?? 0),
                'total_wagered' => (float) ($t['total_wagered'] ?? 0),
                'total_paid_out' => (float) ($t['total_paid_out'] ?? 0),
                'ggr' => (float) ($t['total_wagered'] ?? 0) - (float) ($t['total_paid_out'] ?? 0),
            ];
        }

        ksort($sparkByDay);
        usort($recent, fn ($a, $b) => strcmp($b['placed_at'] ?? '', $a['placed_at'] ?? ''));
        $recent = array_slice($recent, 0, $recentLimit);

        return [
            'today' => $todayTotal,
            'yesterday' => $yesterdayTotal,
            'spark' => array_slice(array_values($sparkByDay), -8),
            'recent_bets' => $this->withNames($recent),
            'per_game' => $perGame,
            'errors' => $errors,
        ];
    }

    /** Sum the numeric keys of two stats buckets; money stays a 2dp string. */
    private function add(array $total, array $bucket): array
    {
        foreach (self::SUMMED as $k) {
            if (! array_key_exists($k, $bucket) && ! array_key_exists($k, $total)) {
                continue;
            }
            $sum = (float) ($total[$k] ?? 0) + (float) ($bucket[$k] ?? 0);
            $total[$k] = in_array($k, ['bets_placed', 'active_players', 'wins', 'losses', 'pending', 'cashed', 'busted'], true)
                ? (int) $sum
                : number_format($sum, 2, '.', '');
        }

        return $total;
    }

    /** One row shape for every game; see the class docblock. */
    private function normalize(Game $game, array $b): array
    {
        $isCrash = array_key_exists('stake', $b);
        $outcome = (string) ($b['outcome'] ?? 'pending');
        $mapped = match ($outcome) {
            'cashed', 'win' => 'win',
            'busted', 'lost' => 'lost',
            'cancelled' => 'void',
            default => 'pending',
        };
        $mult = fn ($v) => number_format((float) $v, 2).'×';
        if ($isCrash) {
            $detail = match ($outcome) {
                'cashed' => 'Cashed out at '.$mult($b['cashout_multiplier'] ?? 0),
                'busted' => 'Crashed at '.$mult($b['crash_point'] ?? 0),
                'cancelled' => 'Cancelled, refunded',
                default => 'In play'.(isset($b['auto_cashout_target']) && $b['auto_cashout_target'] !== null ? ' · auto '.$mult($b['auto_cashout_target']) : ''),
            };
            $odds = $b['cashout_multiplier'] ?? $b['auto_cashout_target'] ?? null;
            $potential = $outcome === 'cashed' ? ($b['payout'] ?? 0) : ($odds !== null ? (float) $b['stake'] * (float) $odds : 0);
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
            'bet_amount' => (float) ($b['bet_amount'] ?? $b['stake'] ?? 0),
            'combined_odds' => (float) ($odds ?? 0),
            'potential_payout' => (float) $potential,
            'outcome' => $mapped,
            'winning_amount' => (float) ($b['winning_amount'] ?? ($outcome === 'cashed' ? ($b['payout'] ?? 0) : 0)),
        ];
    }

    /** Player and tenant labels from the SSO, one lookup each. Fantasy tenant keys may be slugs. */
    private function withNames(array $rows): array
    {
        $userUuids = collect($rows)->pluck('user_uuid')->filter()->unique()->values()->all();
        $users = $userUuids ? User::whereIn('uuid', $userUuids)->get()->keyBy('uuid') : collect();
        $resolve = $this->stats->tenantResolver(collect($rows)->pluck('tenant_uuid')->filter()->unique()->values()->all());

        return array_map(function (array $r) use ($users, $resolve) {
            $uuid = $r['user_uuid'];
            $u = $uuid ? $users->get($uuid) : null;
            $name = $u?->display_name ?? $u?->name ?? ($uuid ? 'Player '.substr($uuid, 0, 6) : '—');
            $initials = strtoupper(collect(explode(' ', trim($name)))->filter()->take(2)->map(fn ($p) => $p[0] ?? '')->implode('')) ?: '??';
            $tenant = $resolve($r['tenant_uuid']);
            unset($r['user_uuid']);

            return $r + [
                'player' => ['name' => $name, 'uuid_short' => $uuid ? strtoupper(substr($uuid, 0, 6)) : null, 'initials' => $initials],
                'tenant_name' => $tenant?->name,
            ];
        }, $rows);
    }
}
