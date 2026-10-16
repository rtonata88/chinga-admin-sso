<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\LoginAttempt;
use App\Models\SecurityAuditLog;
use App\Models\Tenant;
use App\Models\TenantRevenueRecord;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Venue;
use App\Models\VoucherCode;
use App\Models\VoucherTransaction;
use App\Services\LiveGameStats;
use App\Support\Currencies;
use App\Support\GameCatalogue;
use App\Support\RevenueSplit;
use App\Support\ScopedTotals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ReportController extends Controller
{
    /**
     * Dashboard overview.
     */
    public function dashboard(): JsonResponse
    {
        $today = today();
        $thisWeek = now()->startOfWeek();
        $thisMonth = now()->startOfMonth();

        return response()->json([
            'success' => true,
            'data' => [
                'users' => [
                    'total' => User::count(),
                    'today' => User::whereDate('created_at', $today)->count(),
                    'this_week' => User::where('created_at', '>=', $thisWeek)->count(),
                    'this_month' => User::where('created_at', '>=', $thisMonth)->count(),
                    'active' => User::where('status', 'active')->count(),
                ],
                'venues' => [
                    'total' => Venue::count(),
                    'active' => Venue::where('status', 'active')->count(),
                ],
                'vouchers' => [
                    'active_codes' => VoucherCode::whereIn('status', ['active', 'in_use'])->count(),
                    // One figure per currency: codes of different tenants are never added together.
                    'balances' => $this->activeVoucherBalances('total_balance'),
                ],
                'security' => [
                    'failed_logins_today' => LoginAttempt::whereDate('created_at', $today)
                        ->where('success', false)->count(),
                    'locked_accounts' => User::where('locked_until', '>', now())->count(),
                ],
            ],
        ]);
    }

    /**
     * Registration statistics.
     */
    public function registrations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['in:day,week,month,year'],
            'start_date' => ['date'],
            'end_date' => ['date', 'after_or_equal:start_date'],
        ]);

        $period = $validated['period'] ?? 'month';
        $startDate = isset($validated['start_date'])
            ? \Carbon\Carbon::parse($validated['start_date'])
            : now()->subDays(30);
        $endDate = isset($validated['end_date'])
            ? \Carbon\Carbon::parse($validated['end_date'])
            : now();

        // Group by period
        $groupBy = match ($period) {
            'day' => 'DATE(created_at)',
            'week' => 'YEARWEEK(created_at)',
            'month' => 'DATE_FORMAT(created_at, "%Y-%m")',
            'year' => 'YEAR(created_at)',
        };

        $registrations = User::selectRaw("{$groupBy} as period, COUNT(*) as count")
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        // By country
        $byCountry = User::selectRaw('country_code, COUNT(*) as count')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('country_code')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => User::whereBetween('created_at', [$startDate, $endDate])->count(),
                'by_period' => $registrations,
                'by_country' => $byCountry,
                'email_verified_rate' => User::whereBetween('created_at', [$startDate, $endDate])
                    ->whereNotNull('email_verified_at')
                    ->count() / max(1, User::whereBetween('created_at', [$startDate, $endDate])->count()) * 100,
            ],
        ]);
    }

    /**
     * Login statistics.
     */
    public function logins(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['date'],
            'end_date' => ['date', 'after_or_equal:start_date'],
        ]);

        $startDate = isset($validated['start_date'])
            ? \Carbon\Carbon::parse($validated['start_date'])
            : now()->subDays(30);
        $endDate = isset($validated['end_date'])
            ? \Carbon\Carbon::parse($validated['end_date'])
            : now();

        // Login attempts by day
        $attempts = LoginAttempt::selectRaw('DATE(created_at) as date, success, COUNT(*) as count')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('date', 'success')
            ->orderBy('date')
            ->get()
            ->groupBy('date')
            ->map(fn ($items) => [
                'successful' => $items->where('success', true)->sum('count'),
                'failed' => $items->where('success', false)->sum('count'),
            ]);

        // Top failure reasons
        $failureReasons = LoginAttempt::selectRaw('failure_reason, COUNT(*) as count')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('success', false)
            ->whereNotNull('failure_reason')
            ->groupBy('failure_reason')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        // Active sessions — UserSession has no tenant column, so scope
        // by the current tenant's users via subquery. User has the
        // BelongsToTenant global scope, so this filters automatically
        // for tenant admins and stays platform-wide for platform admins.
        $activeSessions = UserSession::where('last_activity_at', '>=', now()->subHours(24))
            ->whereIn('user_id', User::query()->select('id'))
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total_attempts' => LoginAttempt::whereBetween('created_at', [$startDate, $endDate])->count(),
                'successful' => LoginAttempt::whereBetween('created_at', [$startDate, $endDate])
                    ->where('success', true)->count(),
                'failed' => LoginAttempt::whereBetween('created_at', [$startDate, $endDate])
                    ->where('success', false)->count(),
                'by_day' => $attempts,
                'failure_reasons' => $failureReasons,
                'active_sessions_24h' => $activeSessions,
                'unique_users_logged_in' => User::whereBetween('last_login_at', [$startDate, $endDate])
                    ->count(),
            ],
        ]);
    }

    /**
     * Venue statistics.
     */
    public function venues(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['date'],
            'end_date' => ['date', 'after_or_equal:start_date'],
        ]);

        $startDate = isset($validated['start_date'])
            ? \Carbon\Carbon::parse($validated['start_date'])
            : now()->subDays(30);
        $endDate = isset($validated['end_date'])
            ? \Carbon\Carbon::parse($validated['end_date'])
            : now();

        // Top venues by transaction volume
        $topVenues = Venue::withSum(['voucherTransactions as transaction_volume' => function ($query) use ($startDate, $endDate) {
            $query->whereBetween('created_at', [$startDate, $endDate])
                ->where('type', 'load');
        }], 'amount')
            ->orderByDesc('transaction_volume')
            ->limit(10)
            ->get(['uuid', 'name', 'city', 'currency']);

        // Transaction totals per type, one figure per currency each:
        // { load: [{currency: 'NAD', total: '120.00'}, ...], ... }.
        $transactions = VoucherTransaction::query()
            ->join('voucher_codes', 'voucher_transactions.voucher_code_id', '=', 'voucher_codes.id')
            ->selectRaw('voucher_transactions.type as type, voucher_codes.currency as currency, SUM(ABS(voucher_transactions.amount)) as total')
            ->whereBetween('voucher_transactions.created_at', [$startDate, $endDate])
            ->groupBy('voucher_transactions.type', 'voucher_codes.currency')
            ->get()
            ->groupBy('type')
            ->map(fn ($rows) => Currencies::totalsByCurrency($rows->map(fn ($r) => ['currency' => $r->currency, 'total' => $r->total]), ['total']));

        return response()->json([
            'success' => true,
            'data' => [
                'overview' => [
                    'total_venues' => Venue::count(),
                    'active_venues' => Venue::where('status', 'active')->count(),
                    'codes_created' => VoucherCode::whereBetween('created_at', [$startDate, $endDate])->count(),
                    'active_codes' => VoucherCode::whereIn('status', ['active', 'in_use'])->count(),
                    'active_balances' => $this->activeVoucherBalances('total_active_balance'),
                ],
                'transactions' => $transactions,
                'top_venues' => $topVenues->map(fn ($v) => [
                    'uuid' => $v->uuid,
                    'name' => $v->name,
                    'city' => $v->city,
                    'currency' => $v->currency,
                    'transaction_volume' => (string) ($v->transaction_volume ?? '0.00'),
                ]),
            ],
        ]);
    }

    /**
     * Revenue records.
     *
     * Tenant admins see only their own tenant's rows; platform admins
     * see every tenant's records (or filter to one with ?tenant=).
     * Falls through to current_tenant if no role is platform_admin.
     */
    public function revenue(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantIds = $this->resolveTenantScope($request, $user);

        if ($tenantIds === null) {
            return response()->json(['success' => false, 'message' => 'No tenant context.'], 403);
        }

        $query = TenantRevenueRecord::with('game:id,uuid,name', 'tenant:id,slug,name,currency,settings');
        if (! empty($tenantIds)) {
            $query->whereIn('tenant_id', $tenantIds);
        }

        if ($periodType = $request->input('period_type')) {
            $query->where('period_type', $periodType);
        }

        if ($from = $request->input('from')) {
            $query->where('period_start', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $query->where('period_end', '<=', $to);
        }

        $records = $query->orderByDesc('period_start')
            ->paginate($request->input('per_page', 25));

        return response()->json([
            'success' => true,
            // A record's figures are in its tenant's currency.
            'data' => collect($records->items())->map(fn (TenantRevenueRecord $r) => [
                ...$r->toArray(),
                'currency' => ScopedTotals::currencyOf($r->tenant),
            ])->all(),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    /**
     * Resolve which tenant IDs the caller can see on the revenue
     * page. Returns:
     *   - [tenant_id] for a tenant admin
     *   - [tenant_id] when ?tenant=<slug|uuid> is provided by a platform admin
     *   - []          for a platform admin viewing all tenants (no filter)
     *   - null        when the caller has no business viewing this page
     */
    private function resolveTenantScope(Request $request, ?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        if ($user->isPlatformAdmin()) {
            $param = $request->input('tenant') ?: $request->input('tenant_uuid');
            if ($param) {
                $tenant = Tenant::where('slug', $param)->orWhere('uuid', $param)->first();

                return $tenant ? [$tenant->id] : [];
            }

            // Empty array = no filter, see everything.
            return [];
        }

        $tenant = app('current_tenant') ?? $user->tenant;

        return $tenant ? [$tenant->id] : null;
    }

    /**
     * Revenue summary for the current tenant — live, computed on
     * demand from every game backend.
     *
     * Records (the table on this page) stay snapshot-driven: they're
     * the closed-period billing artifact. The summary KPIs, on the
     * other hand, should reflect what's actually in the bets table
     * right now — otherwise the page reads as empty until the daily
     * `revenue:calculate` job runs.
     *
     * The split is RevenueSplit (as DashboardController), applied per
     * tenant. Money is never added across currencies: `totals` is one
     * entry per currency, and `per_game` one row per (game, currency).
     * A tenant's summary is one currency, with zeros when nothing was
     * played.
     */
    public function revenueSummary(Request $request, LiveGameStats $stats): JsonResponse
    {
        $user = $request->user();
        $tenantIds = $this->resolveTenantScope($request, $user);

        if ($tenantIds === null) {
            return response()->json(['success' => false, 'message' => 'No tenant context.'], 403);
        }

        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());
        $fromIso = \Carbon\Carbon::parse($from)->startOfDay()->toIso8601String();
        $toIso = \Carbon\Carbon::parse($to)->endOfDay()->toIso8601String();

        // One call per game in the caller's catalogue (PRD §4 P6).
        // Platform admin viewing the whole platform: per-tenant rows via
        // statsByTenant, each run through its own tenant's split.
        // Tenant admin (or ?tenant=): straight statsSummary for one tenant.
        $games = GameCatalogue::withBackendForUser($user);
        $tenant = empty($tenantIds) ? null : Tenant::find($tenantIds[0]);
        $rows = $tenant
            ? $this->rowsForOneTenant($stats, $games, $tenant, $fromIso, $toIso)
            : $this->rowsForAllTenants($stats, $games, $fromIso, $toIso);

        $priced = array_values(array_filter($rows, fn ($r) => $r['currency'] !== null));
        $totals = ScopedTotals::of($priced, self::SUMMARY_MONEY, $tenant);

        // Per (game, currency); a game that is down keeps one row carrying its error.
        $perGame = [];
        foreach ($rows as $r) {
            if ($r['error'] !== null) {
                $perGame[$r['game']->uuid.'|error'] = self::perGameRow($r['game'], $tenant ? $r['currency'] : null, $r['error']);

                continue;
            }
            if ($r['currency'] === null || bccomp($r['total_bets'], '0', 2) <= 0) {
                continue;
            }
            $key = $r['game']->uuid.'|'.$r['currency'];
            $perGame[$key] ??= self::perGameRow($r['game'], $r['currency'], null);
            foreach (self::SUMMARY_MONEY as $f) {
                if ($f !== 'chinga_share') {
                    $perGame[$key][$f] = bcadd($perGame[$key][$f], $r[$f], 2);
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'totals' => $totals,
                'per_game' => array_values($perGame),
                'live' => true,
            ],
        ]);
    }

    private const SUMMARY_MONEY = ['total_bets', 'total_wins', 'gross_gaming_revenue', 'chinga_share', 'tenant_share'];

    /** One priced row per game for a single tenant, all in that tenant's currency. */
    private function rowsForOneTenant(LiveGameStats $stats, Collection $games, Tenant $tenant, string $fromIso, string $toIso): array
    {
        $rows = [];
        foreach ($stats->summaryForTenant($games, $tenant, $fromIso, $toIso) as $entry) {
            $rows[] = $this->pricedRow($entry['game'], $tenant, $entry['stats'], $entry['error']);
        }

        return $rows;
    }

    /**
     * Cross-tenant rows for platform admins: statsByTenant once per game,
     * each row priced with its own tenant's split and tagged with that
     * tenant's currency. A game that is down contributes one error row.
     */
    private function rowsForAllTenants(LiveGameStats $stats, Collection $games, string $fromIso, string $toIso): array
    {
        $byGame = $stats->byTenant($games, $fromIso, $toIso);
        $resolve = $stats->tenantResolver(collect($byGame)->flatMap(fn ($e) => collect($e['rows'])->pluck('tenant_uuid'))->all());

        $rows = [];
        foreach ($byGame as $entry) {
            if ($entry['error'] !== null) {
                $rows[] = $this->pricedRow($entry['game'], null, [], $entry['error']);

                continue;
            }
            foreach ($entry['rows'] as $r) {
                $rows[] = $this->pricedRow($entry['game'], $resolve($r['tenant_uuid'] ?? null), $r, null);
            }
        }

        return $rows;
    }

    private function pricedRow(Game $game, ?Tenant $tenant, array $s, ?string $error): array
    {
        $wagered = bcadd(RevenueSplit::decimal($s['total_wagered'] ?? 0), '0', 2);
        $paidOut = bcadd(RevenueSplit::decimal($s['total_paid_out'] ?? 0), '0', 2);
        $split = RevenueSplit::compute($tenant, $wagered, $paidOut);

        return [
            'game' => $game,
            'currency' => ScopedTotals::currencyOf($tenant),
            'error' => $error,
            'total_bets' => $wagered,
            'total_wins' => $paidOut,
            'gross_gaming_revenue' => $split['ggr'],
            'chinga_share' => $split['platform_profit'],
            'tenant_share' => $split['tenant_profit'],
        ];
    }

    private static function perGameRow(Game $game, ?string $currency, ?string $error): array
    {
        return [
            'game_id' => $game->id,
            'game' => ['uuid' => $game->uuid, 'name' => $game->name],
            'currency' => $currency,
            'total_bets' => '0.00',
            'total_wins' => '0.00',
            'gross_gaming_revenue' => '0.00',
            'tenant_share' => '0.00',
            'error' => $error,
        ];
    }

    /**
     * Active voucher balances, one entry per currency ({currency, $field}).
     * VoucherCode is tenant-scoped, so a tenant admin gets their own
     * currency (zero when they hold none).
     */
    private function activeVoucherBalances(string $field): array
    {
        $rows = VoucherCode::whereIn('status', ['active', 'in_use'])
            ->selectRaw('currency, SUM(balance) as total')
            ->groupBy('currency')
            ->get()
            ->map(fn ($r) => ['currency' => $r->currency, $field => $r->total]);

        return ScopedTotals::of($rows, [$field], ScopedTotals::scopeTenant());
    }

    /**
     * Audit log viewer.
     */
    public function auditLogs(Request $request): JsonResponse
    {
        // The `security_audit_logs` table has no `action` or `description`
        // columns — those used to come from a removed shape. Action lives
        // inside `metadata.action` (granular, e.g. "admin.user.suspend"),
        // with `event_type` as the coarse fallback. Description is
        // synthesized from event/severity/actor here so the frontend
        // doesn't have to recompute it per row.
        $query = SecurityAuditLog::with([
            'user:id,uuid,name,email',
            'admin:id,uuid,name,email',
        ]);

        // Filter by action — search both event_type and the JSON
        // `metadata->action` path so chip filters like "auth" still hit
        // event_type='user_login' and the free-text input still hits
        // metadata.action='auth.login.failed'.
        if ($action = $request->input('action')) {
            $like = "%{$action}%";
            $query->where(function ($q) use ($like) {
                $q->where('event_type', 'like', $like)
                    ->orWhere('metadata->action', 'like', $like);
            });
        }

        if ($userUuid = $request->input('user_uuid')) {
            $user = User::where('uuid', $userUuid)->first();
            if ($user) {
                $query->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)->orWhere('admin_id', $user->id);
                });
            }
        }

        if ($startDate = $request->input('start_date')) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate = $request->input('end_date')) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $logs = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 50));

        return response()->json([
            'success' => true,
            'data' => $logs->map(function ($log) {
                $metadata = is_array($log->metadata) ? $log->metadata : [];
                $action = $metadata['action'] ?? $log->event_type ?? 'unknown';

                // Show the actor when there is one (admin who performed
                // the action), otherwise fall back to the user the
                // event is *about* (e.g. user_login).
                $actor = $log->admin ?: $log->user;

                // Synthesize a human description: action + reason, or
                // event_type + severity, or whatever metadata provides.
                $description = $metadata['reason']
                    ?? $metadata['description']
                    ?? trim(($log->event_type ?? '').($log->severity ? " ({$log->severity})" : ''))
                    ?: '—';

                return [
                    'id' => $log->id,
                    'user' => $actor ? [
                        'uuid' => $actor->uuid,
                        'name' => $actor->name,
                        'email' => $actor->email,
                    ] : null,
                    'action' => $action,
                    'event_type' => $log->event_type,
                    'severity' => $log->severity,
                    'description' => $description,
                    'ip_address' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                    'old_values' => $log->old_values,
                    'new_values' => $log->new_values,
                    'metadata' => $metadata,
                    'created_at' => optional($log->created_at)->toIso8601String(),
                ];
            }),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
