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
use App\Services\FantasyAdminClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
                    'total_balance' => VoucherCode::whereIn('status', ['active', 'in_use'])->sum('balance'),
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
            ->get(['uuid', 'name', 'city']);

        // Transaction totals
        $transactions = VoucherTransaction::selectRaw('type, SUM(ABS(amount)) as total')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('type')
            ->pluck('total', 'type');

        return response()->json([
            'success' => true,
            'data' => [
                'overview' => [
                    'total_venues' => Venue::count(),
                    'active_venues' => Venue::where('status', 'active')->count(),
                    'codes_created' => VoucherCode::whereBetween('created_at', [$startDate, $endDate])->count(),
                    'active_codes' => VoucherCode::whereIn('status', ['active', 'in_use'])->count(),
                    'total_active_balance' => VoucherCode::whereIn('status', ['active', 'in_use'])->sum('balance'),
                ],
                'transactions' => $transactions,
                'top_venues' => $topVenues->map(fn ($v) => [
                    'uuid' => $v->uuid,
                    'name' => $v->name,
                    'city' => $v->city,
                    'transaction_volume' => $v->transaction_volume ?? 0,
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

        $query = TenantRevenueRecord::with('game:id,uuid,name', 'tenant:id,slug,name');
        if (!empty($tenantIds)) {
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
            'data' => $records->items(),
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
        if (! $user) return null;

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
     * demand from chinga-fantasy bet data.
     *
     * Records (the table on this page) stay snapshot-driven: they're
     * the closed-period billing artifact. The summary KPIs, on the
     * other hand, should reflect what's actually in the bets table
     * right now — otherwise the page reads as empty until the daily
     * `revenue:calculate` job runs.
     *
     * Math mirrors DashboardController::fetchTenantBreakdown:
     *   GGR  = total_wagered - total_paid_out
     *   tax  = max(GGR, 0) * tax_pct / 100
     *   NGR  = GGR - tax
     *   reseller: tenant_share  = max(NGR, 0) * revenue_share_pct/100
     *             chinga_share  = NGR - tenant_share
     *   direct:   tenant_share  = 0
     *             chinga_share  = NGR
     */
    public function revenueSummary(Request $request, FantasyAdminClient $fantasyAdmin): JsonResponse
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

        // Platform admin viewing the whole platform: aggregate across
        // all tenants via statsByTenant, applying each tenant's
        // commercial split (matches DashboardController). Tenant admin:
        // straight statsSummary call for one tenant.
        if (empty($tenantIds)) {
            [$totals, $perGame] = $this->aggregateAllTenants($fantasyAdmin, $fromIso, $toIso);
        } else {
            $tenant = Tenant::find($tenantIds[0]);
            [$totals, $perGame] = $this->aggregateOneTenant($fantasyAdmin, $tenant, $fromIso, $toIso);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'totals' => $totals,
                'per_game' => $perGame,
                'live' => true,
            ],
        ]);
    }

    /**
     * Live summary for a single tenant. chinga-fantasy stores SSO
     * tenant slug in its `tenant_uuid` column (TODO: backfill to UUID
     * — see DashboardController). Slug-first, UUID-fallback.
     */
    private function aggregateOneTenant(FantasyAdminClient $client, Tenant $tenant, string $fromIso, string $toIso): array
    {
        $stats = null;
        try {
            $stats = $client->statsSummary($tenant->slug, $fromIso, $toIso);
            if ((int) ($stats['bets_placed'] ?? 0) === 0 && $tenant->uuid) {
                $stats = $client->statsSummary($tenant->uuid, $fromIso, $toIso);
            }
        } catch (\Throwable $e) {
            Log::warning('revenueSummary single-tenant fetch failed', ['error' => $e->getMessage()]);
        }

        $wagered = (float) ($stats['total_wagered'] ?? 0);
        $paidOut = (float) ($stats['total_paid_out'] ?? 0);
        [$ggr, $tenantShare, $chingaShare] = $this->computeShares($tenant, $wagered, $paidOut);

        $totals = [
            'total_bets' => $wagered,
            'total_wins' => $paidOut,
            'gross_gaming_revenue' => $ggr,
            'chinga_share' => $chingaShare,
            'tenant_share' => $tenantShare,
        ];

        $perGame = [];
        $game = Game::where('slug', 'chinga-fantasy')->first();
        if ($game && $wagered > 0) {
            $perGame[] = [
                'game_id' => $game->id,
                'game' => ['uuid' => $game->uuid, 'name' => $game->name],
                'total_bets' => $wagered,
                'total_wins' => $paidOut,
                'gross_gaming_revenue' => $ggr,
                'tenant_share' => $tenantShare,
            ];
        }

        return [$totals, $perGame];
    }

    /**
     * Cross-tenant live aggregate for platform admins. Calls
     * statsByTenant once and applies each row's tenant-specific
     * commercial split, then sums.
     */
    private function aggregateAllTenants(FantasyAdminClient $client, string $fromIso, string $toIso): array
    {
        $rows = [];
        try {
            $resp = $client->statsByTenant($fromIso, $toIso);
            $rows = $resp['tenants'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('revenueSummary all-tenants fetch failed', ['error' => $e->getMessage()]);
        }

        // Resolve each fantasy-side tenant_uuid (which is actually the
        // slug — see comment on aggregateOneTenant) back to a Tenant.
        $keys = collect($rows)->pluck('tenant_uuid')->filter()->unique()->values()->all();
        $tenantsBySlug = Tenant::whereIn('slug', $keys)->get()->keyBy('slug');
        $tenantsByUuid = Tenant::whereIn('uuid', $keys)->get()->keyBy('uuid');

        $totalBets = 0.0;
        $totalWins = 0.0;
        $totalGgr = 0.0;
        $totalTenantShare = 0.0;
        $totalChingaShare = 0.0;

        foreach ($rows as $r) {
            $key = $r['tenant_uuid'] ?? null;
            $tenant = $key ? ($tenantsBySlug->get($key) ?? $tenantsByUuid->get($key)) : null;

            $wagered = (float) ($r['total_wagered'] ?? 0);
            $paidOut = (float) ($r['total_paid_out'] ?? 0);
            [$ggr, $tenantShare, $chingaShare] = $this->computeShares($tenant, $wagered, $paidOut);

            $totalBets += $wagered;
            $totalWins += $paidOut;
            $totalGgr += $ggr;
            $totalTenantShare += $tenantShare;
            $totalChingaShare += $chingaShare;
        }

        $totals = [
            'total_bets' => $totalBets,
            'total_wins' => $totalWins,
            'gross_gaming_revenue' => $totalGgr,
            'chinga_share' => $totalChingaShare,
            'tenant_share' => $totalTenantShare,
        ];

        $perGame = [];
        $game = Game::where('slug', 'chinga-fantasy')->first();
        if ($game && $totalBets > 0) {
            $perGame[] = [
                'game_id' => $game->id,
                'game' => ['uuid' => $game->uuid, 'name' => $game->name],
                'total_bets' => $totalBets,
                'total_wins' => $totalWins,
                'gross_gaming_revenue' => $totalGgr,
                'tenant_share' => $totalTenantShare,
            ];
        }

        return [$totals, $perGame];
    }

    /**
     * Same revenue split DashboardController uses:
     *   GGR  = wagered - paid_out
     *   tax  = max(GGR, 0) * tax_pct/100
     *   NGR  = GGR - tax
     *   reseller: tenant_share = max(NGR, 0) * share_pct/100
     *             chinga_share = NGR - tenant_share
     *   direct:   tenant_share = 0
     *             chinga_share = NGR
     *
     * Returns [ggr, tenant_share, chinga_share].
     */
    private function computeShares(?Tenant $tenant, float $wagered, float $paidOut): array
    {
        $ggr = $wagered - $paidOut;
        $taxPct = $tenant ? (float) ($tenant->tax_pct ?? 0) : 0.0;
        $tax = $ggr > 0 ? $ggr * ($taxPct / 100) : 0.0;
        $ngr = $ggr - $tax;

        $businessModel = $tenant->business_model ?? 'reseller';
        $sharePct = $tenant ? (float) ($tenant->revenue_share_pct ?? 0) : 0.0;

        if ($businessModel === 'direct') {
            $tenantShare = 0.0;
            $chingaShare = $ngr;
        } else {
            $tenantShare = $ngr > 0 ? $ngr * ($sharePct / 100) : 0.0;
            $chingaShare = $ngr - $tenantShare;
        }

        return [$ggr, $tenantShare, $chingaShare];
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
                    ?? trim(($log->event_type ?? '') . ($log->severity ? " ({$log->severity})" : ''))
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
