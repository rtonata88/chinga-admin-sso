<?php

namespace App\Http\Controllers\Admin\Games;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\VrrrPhaAdminClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vrrr Pha consoles for platform admins (PRD §8.3, §8.5, M5): round
 * history with the seed audit, live exposure, and realised RTP. Every
 * figure comes from the engine's admin API; nothing here is computed
 * from wallet rows. Tenants are filtered by uuid only — the engine
 * refuses a slug, and we never turn one into the other here.
 */
class VrrrPhaConsoleController extends Controller
{
    private const REVEALED = ['CRASHED', 'SETTLING', 'SETTLED'];

    private const PER_PAGE = 25;

    public function __construct(protected VrrrPhaAdminClient $client) {}

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
            Log::warning('VrrrPha rounds failed', ['error' => $e->getMessage()]);
            $error = 'Could not load rounds. Is the Vrrr Pha engine reachable?';
        }

        return Inertia::render('vrrr-pha/rounds', [
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
            Log::warning('VrrrPha round failed', ['id' => $id, 'error' => $e->getMessage()]);
            $error = 'Could not load the round. Is the Vrrr Pha engine reachable?';
        }

        // The audit only exists once the seeds are revealed; a failure here
        // must not take the round page down with it.
        if ($round !== null && in_array($round['state'] ?? null, self::REVEALED, true)) {
            try {
                $verify = $this->client->verifyRound($id);
            } catch (\Throwable $e) {
                Log::warning('VrrrPha verify failed', ['id' => $id, 'error' => $e->getMessage()]);
                $verify = ['error' => 'The seed audit could not be run.'];
            }
        }

        return Inertia::render('vrrr-pha/round-detail', [
            'round' => $round,
            'bets' => $bets,
            'verify' => $verify,
            'tenantNames' => $this->tenantNames(),
            'error' => $error,
            'backHref' => '/vrrr-pha/rounds',
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
            Log::warning('VrrrPha exposure failed', ['error' => $e->getMessage()]);
            $error = 'Could not load exposure. Is the Vrrr Pha engine reachable?';
        }

        return Inertia::render('vrrr-pha/exposure', [
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
            Log::warning('VrrrPha rtp failed', ['error' => $e->getMessage()]);
            $error = 'Could not load RTP. Is the Vrrr Pha engine reachable?';
        }

        return Inertia::render('vrrr-pha/rtp', [
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
    private function tenantUuid(Request $request): ?string
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

    private function tenants(): array
    {
        return Tenant::query()->where('status', 'active')->orderBy('name')->get(['uuid', 'name', 'slug'])->all();
    }

    /** @return array<string, string> uuid → name, for labelling engine rows that only carry the uuid */
    private function tenantNames(): array
    {
        return Tenant::query()->pluck('name', 'uuid')->all();
    }
}
