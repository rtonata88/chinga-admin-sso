<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Models\Venue;
use App\Models\VoucherCode;
use App\Services\FantasyAdminClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(protected FantasyAdminClient $fantasyAdminClient) {}

    /**
     * Display the admin dashboard.
     */
    public function index(): Response
    {
        $today = today();
        $thisWeek = now()->startOfWeek();

        $stats = [
            'users' => [
                'total' => User::count(),
                'today' => User::whereDate('created_at', $today)->count(),
                'this_week' => User::where('created_at', '>=', $thisWeek)->count(),
                'active' => User::where('status', 'active')->count(),
            ],
            'venues' => [
                'total' => Venue::count(),
                'active' => Venue::where('status', 'active')->count(),
            ],
            'vouchers' => [
                'active' => VoucherCode::whereIn('status', ['active', 'in_use'])->count(),
                'total_balance' => VoucherCode::whereIn('status', ['active', 'in_use'])->sum('balance'),
            ],
            'security' => [
                'failed_logins_today' => LoginAttempt::whereDate('created_at', $today)
                    ->where('successful', false)->count(),
                'locked_accounts' => User::where('locked_until', '>', now())->count(),
            ],
        ];

        return Inertia::render('admin/dashboard', [
            'stats' => $stats,
            'tenants' => $this->fetchTenantBreakdown(),
        ]);
    }

    /**
     * Per-tenant breakdown for the admin dashboard tenants table.
     *
     * Pulls the last-30-day cross-tenant aggregates from chinga-fantasy
     * (statsByTenant), resolves each row's tenant_uuid back to a local
     * Tenant model so we can show a real name and apply the commercial
     * split:
     *
     *   GGR  = total_wagered - total_paid_out
     *   tax  = GGR * tax_pct / 100               (jurisdictional)
     *   NGR  = GGR - tax
     *   reseller: tenant_profit = NGR * revenue_share_pct / 100
     *             platform_profit = NGR - tenant_profit
     *   direct:   tenant_profit = 0
     *             platform_profit = NGR
     *
     * Tenant admins only see their own tenant's row; platform admins
     * see every tenant. Returns [] on upstream failure.
     */
    private function fetchTenantBreakdown(): array
    {
        $user = auth()->user();
        if (!$user->isPlatformAdmin() && !$user->isTenantAdmin()) {
            return [];
        }

        try {
            $from = now()->subDays(30)->startOfDay()->toIso8601String();
            $to = now()->toIso8601String();
            $resp = $this->fantasyAdminClient->statsByTenant($from, $to);
        } catch (\Throwable $e) {
            Log::warning('statsByTenant fetch failed', ['error' => $e->getMessage()]);
            return [];
        }

        $rows = $resp['tenants'] ?? [];
        if (empty($rows)) {
            return [];
        }

        // chinga-fantasy stores SSO tenant slug in its tenant_uuid columns
        // (TODO: backfill to use UUID). Try slug first, fall back to uuid.
        $keys = collect($rows)->pluck('tenant_uuid')->filter()->unique()->values()->all();
        $tenantsBySlug = \App\Models\Tenant::whereIn('slug', $keys)->get()->keyBy('slug');
        $tenantsByUuid = \App\Models\Tenant::whereIn('uuid', $keys)->get()->keyBy('uuid');

        $isPlatformAdmin = $user->isPlatformAdmin();
        $myTenantId = $user->tenant_id;

        return collect($rows)
            ->map(function ($r) use ($tenantsBySlug, $tenantsByUuid) {
                $key = $r['tenant_uuid'] ?? null;
                $tenant = $key ? ($tenantsBySlug->get($key) ?? $tenantsByUuid->get($key)) : null;

                $wagered = (float) ($r['total_wagered'] ?? 0);
                $paidOut = (float) ($r['total_paid_out'] ?? 0);
                $ggr = $wagered - $paidOut;

                $taxPct = $tenant ? (float) ($tenant->tax_pct ?? 0) : 0.0;
                $tax = $ggr > 0 ? $ggr * ($taxPct / 100) : 0;
                $ngr = $ggr - $tax;

                $businessModel = $tenant->business_model ?? 'reseller';
                $sharePct = $tenant ? (float) ($tenant->revenue_share_pct ?? 0) : 0.0;

                if ($businessModel === 'direct') {
                    $tenantProfit = 0.0;
                    $platformProfit = $ngr;
                } else {
                    $tenantProfit = $ngr * ($sharePct / 100);
                    $platformProfit = $ngr - $tenantProfit;
                }

                return [
                    'tenant_id' => $tenant?->id,
                    'tenant_uuid' => $tenant?->uuid ?? $key,
                    'tenant_name' => $tenant?->name ?? $key ?? '—',
                    'business_model' => $businessModel,
                    'revenue_share_pct' => $sharePct,
                    'bets_placed' => (int) ($r['bets_placed'] ?? 0),
                    'active_players' => (int) ($r['active_players'] ?? 0),
                    'total_wagered' => $wagered,
                    'total_paid_out' => $paidOut,
                    'ggr' => $ggr,
                    'ngr' => $ngr,
                    'tenant_profit' => $tenantProfit,
                    'platform_profit' => $platformProfit,
                ];
            })
            ->when(!$isPlatformAdmin, fn ($c) => $c->filter(fn ($r) => $r['tenant_id'] === $myTenantId))
            ->values()
            ->all();
    }

    /**
     * Display user management page.
     */
    public function users(): Response
    {
        return Inertia::render('admin/users');
    }

    /**
     * Display voucher codes page.
     */
    public function voucherCodes(): Response
    {
        return Inertia::render('admin/voucher-codes');
    }

    /**
     * Display reports page.
     */
    public function reports(): Response
    {
        return Inertia::render('admin/reports');
    }

    /**
     * Display audit logs page.
     */
    public function auditLogs(): Response
    {
        return Inertia::render('admin/audit-logs');
    }

    /**
     * Display wallet management page.
     */
    public function wallets(): Response
    {
        return Inertia::render('admin/wallets');
    }

    /**
     * Display wallet transactions page.
     */
    public function walletTransactions(): Response
    {
        return Inertia::render('admin/wallet-transactions');
    }

    /**
     * Display withdrawal queue page.
     */
    public function withdrawals(): Response
    {
        return Inertia::render('admin/withdrawals');
    }
}
