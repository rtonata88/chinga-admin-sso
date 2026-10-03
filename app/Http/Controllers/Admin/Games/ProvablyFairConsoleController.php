<?php

namespace App\Http\Controllers\Admin\Games;

use App\Contracts\ProvablyFairAdminClient;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Provably-fair consoles for platform admins (Vrrr Pha PRD §8.3, §8.5;
 * Fantasy v2 PRD §5, §6): round history with the seed audit, live
 * exposure, and realised RTP. Every
 * figure comes from the engine's admin API; nothing here is computed
 * from wallet rows. Tenants are filtered by uuid only — the engine
 * refuses a slug, and we never turn one into the other here.
 */
abstract class ProvablyFairConsoleController extends Controller
{
    private const REVEALED = ['CRASHED', 'SETTLING', 'SETTLED'];

    private const PER_PAGE = 25;

    protected ProvablyFairAdminClient $client;

    /** The game's display name, e.g. "Vrrr Pha". */
    abstract protected function gameName(): string;

    /** The URL prefix the console pages live under, e.g. "/vrrr-pha". */
    abstract protected function base(): string;

    /**
     * The noun a person reads for one wager: a crash game takes bets, an
     * accumulator sells tickets (four picks on one stake). "Bet" stays the verb.
     *
     * @return array{one: string, many: string, paid_out_meta: string}
     */
    protected function terms(): array
    {
        return ['one' => 'bet', 'many' => 'bets', 'paid_out_meta' => 'to cashed-out bets'];
    }

    /**
     * Round states whose seed audit can run. A game that verifies something
     * other than a round (Kulipi Kuna verifies ladders) returns [].
     *
     * @return list<string>
     */
    protected function revealedStates(): array
    {
        return self::REVEALED;
    }

    /**
     * What a round looks like on the shared rounds pages: 'crash' rounds carry a
     * crash point, growth rate and pulling/crashed times; 'ladder' rounds do not,
     * and the pages leave those fields out.
     *
     * @return 'crash'|'ladder'
     */
    protected function kind(): string
    {
        return 'crash';
    }

    /** Props every console page reads to label and link itself. */
    protected function gameProps(): array
    {
        return ['game' => ['name' => $this->gameName(), 'base' => $this->base(), 'terms' => $this->terms(), 'kind' => $this->kind()]];
    }

    public function rounds(Request $request): Response
    {
        $tenantUuid = $this->tenantUuid($request);
        $page = max(1, (int) $request->query('page', 1));

        $rounds = [];
        $total = null;
        $error = null;
        try {
            $response = $this->client->listRounds($tenantUuid, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
            $rounds = $response['data'] ?? [];
            $total = $response['meta']['total'] ?? null;
        } catch (\Throwable $e) {
            Log::warning($this->gameName().' rounds failed', ['error' => $e->getMessage()]);
            $error = sprintf('Could not load rounds. Is the %s engine reachable?', $this->gameName());
        }

        return Inertia::render('vrrr-pha/rounds', $this->gameProps() + [
            'rounds' => $rounds,
            'tenants' => $this->tenants(),
            'tenantNames' => $this->tenantNames(),
            'filters' => [
                'tenant_uuid' => $tenantUuid,
                'page' => $page,
                'per_page' => self::PER_PAGE,
                'total' => $total,
            ],
            'error' => $error,
        ]);
    }

    public function round(Request $request, int $id): Response
    {
        $round = null;
        $bets = [];
        $verify = null;
        $error = null;
        try {
            $round = $this->client->getRound($id);
            $bets = $this->client->listRoundBets($id, 500, 0)['data'] ?? [];
        } catch (\Throwable $e) {
            Log::warning($this->gameName().' round failed', ['id' => $id, 'error' => $e->getMessage()]);
            $error = sprintf('Could not load the round. Is the %s engine reachable?', $this->gameName());
        }

        // The audit only exists once the seeds are revealed; a failure here
        // must not take the round page down with it.
        if ($round !== null && in_array($round['state'] ?? null, $this->revealedStates(), true)) {
            try {
                $verify = $this->client->verifyRound($id);
            } catch (\Throwable $e) {
                Log::warning($this->gameName().' verify failed', ['id' => $id, 'error' => $e->getMessage()]);
                $verify = ['error' => 'The seed audit could not be run.'];
            }
        }

        return Inertia::render('vrrr-pha/round-detail', $this->gameProps() + [
            'round' => $round,
            'bets' => $bets,
            'verify' => $verify,
            'tenantNames' => $this->tenantNames(),
            'error' => $error,
            'backHref' => $this->base().'/rounds',
        ]);
    }

    public function exposure(Request $request): Response
    {
        $tenantUuid = $this->tenantUuid($request);

        $rows = [];
        $error = null;
        try {
            $rows = $this->client->exposure($tenantUuid)['data'] ?? [];
        } catch (\Throwable $e) {
            Log::warning($this->gameName().' exposure failed', ['error' => $e->getMessage()]);
            $error = sprintf('Could not load exposure. Is the %s engine reachable?', $this->gameName());
        }

        return Inertia::render('vrrr-pha/exposure', $this->gameProps() + [
            'rows' => $rows,
            'tenants' => $this->tenants(),
            'tenantNames' => $this->tenantNames(),
            'filters' => ['tenant_uuid' => $tenantUuid],
            'fetchedAt' => now()->toIso8601String(),
            'error' => $error,
        ]);
    }

    public function rtp(Request $request): Response
    {
        $tenantUuid = $this->tenantUuid($request);
        [$from, $to] = $this->period($request);

        $rtp = null;
        $days = [];
        $error = null;
        try {
            $rtp = $this->client->rtp($tenantUuid, $from->toIso8601String(), $to->toIso8601String());
            $days = $this->client->statsByDay($tenantUuid, $from->toIso8601String(), $to->toIso8601String())['days'] ?? [];
        } catch (\Throwable $e) {
            Log::warning($this->gameName().' rtp failed', ['error' => $e->getMessage()]);
            $error = sprintf('Could not load RTP. Is the %s engine reachable?', $this->gameName());
        }

        return Inertia::render('vrrr-pha/rtp', $this->gameProps() + [
            'rtp' => $rtp,
            'days' => $days,
            'tenants' => $this->tenants(),
            'filters' => [
                'tenant_uuid' => $tenantUuid,
                'from' => $from->toDateString(),
                'to' => $to->subDay()->toDateString(),
            ],
            'error' => $error,
        ]);
    }

    /** A tenant filter is a uuid or nothing; anything else is dropped rather than forwarded. */
    protected function tenantUuid(Request $request): ?string
    {
        $raw = (string) $request->query('tenant_uuid', '');

        return Str::isUuid($raw) ? strtolower($raw) : null;
    }

    /**
     * Inclusive calendar dates from the query, default the last 30 days;
     * the engine takes a half-open [from, to) window, so `to` is the day after.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(Request $request): array
    {
        $today = CarbonImmutable::today();
        $to = $this->date($request->query('to')) ?? $today;
        $from = $this->date($request->query('from')) ?? $to->subDays(29);
        if ($from->gt($to)) {
            $from = $to;
        }

        return [$from, $to->addDay()];
    }

    private function date(mixed $raw): ?CarbonImmutable
    {
        if (! is_string($raw) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $raw)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function tenants(): array
    {
        return Tenant::query()->where('status', 'active')->orderBy('name')->get(['uuid', 'name', 'slug'])->all();
    }

    /** @return array<string, string> uuid → name, for labelling engine rows that only carry the uuid */
    protected function tenantNames(): array
    {
        return Tenant::query()->pluck('name', 'uuid')->all();
    }
}
