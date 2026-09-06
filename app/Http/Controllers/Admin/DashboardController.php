<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
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
     *
     * Month-to-date scope: KPI strip is cross-tenant aggregates for
     * the current calendar month; the breakdown table shows per-tenant
     * rows over the same window.
     *
     * Headline (industry-standard) figures: total_wagered and
     * total_paid_out are full bet/payout amounts. GGR = wagered −
     * paid_out, regardless of funding source. Deposit-aware accounting
     * is used only by the jackpot accrual service — it doesn't fit a
     * per-period operator P&L view because winnings never re-enter the
     * deposit pool, so deposit-aware GGR collapses to zero for any
     * long-running account.
     */
    public function index(): Response
    {
        $from = now()->startOfMonth()->toIso8601String();
        $to = now()->toIso8601String();

        $user = auth()->user();
        $isPlatformAdmin = $user?->isPlatformAdmin();

        $tenantRows = $this->fetchTenantBreakdown($from, $to);
        $visibleRows = $this->visibleRows($tenantRows);

        // KPI scope follows the visible rows: a platform admin sees
        // every row so the totals are cross-tenant; a tenant admin's
        // visibleRows is just their own, so the totals are
        // tenant-scoped — no leak.
        if ($isPlatformAdmin) {
            $scope = 'platform';
            $kpis = [
                'total_tenants' => Tenant::count(),
                'total_wagered' => array_sum(array_column($visibleRows, 'total_wagered')),
                'total_wins' => array_sum(array_column($visibleRows, 'total_paid_out')),
                'platform_profit' => array_sum(array_column($visibleRows, 'platform_profit')),
            ];
        } else {
            // Tenant admin: replace the platform-wide cards with
            // their-tenant-specific ones. "Total tenants" and
            // "Platform profit" don't apply.
            $row = $visibleRows[0] ?? null;
            $scope = 'tenant';
            $kpis = [
                'bets_placed' => (int) ($row['bets_placed'] ?? 0),
                'total_wagered' => (float) ($row['total_wagered'] ?? 0),
                'total_wins' => (float) ($row['total_paid_out'] ?? 0),
                'tenant_profit' => (float) ($row['tenant_profit'] ?? 0),
            ];
        }

        return Inertia::render('admin/dashboard', [
            'period' => ['from' => $from, 'to' => $to],
            'kpis' => $kpis,
            'scope' => $scope,
            'tenant_name' => $isPlatformAdmin ? null : ($user?->tenant?->name),
            'tenants' => $visibleRows,
        ]);
    }

    /**
     * Cross-tenant aggregates from chinga-fantasy over [from, to],
     * with each row's tenant_uuid resolved back to a local Tenant
     * model so we can show a real name and apply the commercial
     * split. Headline figures throughout — see the index() docblock.
     *
     *   GGR  = total_wagered - total_paid_out
     *   tax  = max(GGR, 0) * tax_pct / 100        (no tax on losses)
     *   NGR  = GGR - tax
     *   reseller: tenant_profit  = max(NGR, 0) * revenue_share_pct / 100
     *             platform_profit = NGR - tenant_profit
     *   direct:   tenant_profit  = 0
     *             platform_profit = NGR
     *
     * The reseller share is floored at zero — losing periods don't
     * carry to the tenant. This matches standard rev-share contracts:
     * the platform absorbs the downside.
     *
     * Returns the full unfiltered set so the controller can both
     * aggregate cross-tenant KPIs and apply per-user visibility for
     * the breakdown table. Returns [] on upstream failure.
     */
    private function fetchTenantBreakdown(string $from, string $to): array
    {
        $user = auth()->user();
        if (!$user->isPlatformAdmin() && !$user->isTenantAdmin()) {
            return [];
        }

        try {
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
        $tenantsBySlug = Tenant::whereIn('slug', $keys)->get()->keyBy('slug');
        $tenantsByUuid = Tenant::whereIn('uuid', $keys)->get()->keyBy('uuid');

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
                    $tenantProfit = $ngr > 0 ? $ngr * ($sharePct / 100) : 0.0;
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
            ->values()
            ->all();
    }

    /**
     * Tenant admins only see their own tenant's row in the table;
     * platform admins see every tenant.
     */
    private function visibleRows(array $rows): array
    {
        $user = auth()->user();
        if ($user->isPlatformAdmin()) {
            return $rows;
        }
        $myTenantId = $user->tenant_id;
        return array_values(array_filter($rows, fn ($r) => $r['tenant_id'] === $myTenantId));
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
