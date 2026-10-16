<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\LiveGameStats;
use App\Support\Currencies;
use App\Support\GameCatalogue;
use App\Support\RevenueSplit;
use App\Support\ScopedTotals;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(protected LiveGameStats $liveStats) {}

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

        // One row per (game, tenant) from every backend in the catalogue,
        // then folded two ways: per tenant for the breakdown table and per
        // game for the by-game table (PRD §4 P6).
        $visibleFlat = $this->visibleRows($this->fetchTenantBreakdown($from, $to));
        $visibleRows = $this->foldByTenant($visibleFlat);
        $byGame = $this->foldByGame($visibleFlat);

        // KPI scope follows the visible rows: a platform admin sees
        // every row so the totals are cross-tenant; a tenant admin's
        // visibleRows is just their own, so the totals are
        // tenant-scoped — no leak. Money is totalled per currency and
        // never across currencies (no exchange rate): a cross-tenant
        // total is one figure per currency.
        if ($isPlatformAdmin) {
            $scope = 'platform';
            $kpis = [
                'total_tenants' => Tenant::count(),
                'totals' => array_map(fn ($t) => [
                    'currency' => $t['currency'],
                    'total_wagered' => $t['total_wagered'],
                    'total_wins' => $t['total_paid_out'],
                    'platform_profit' => $t['platform_profit'],
                ], Currencies::totalsByCurrency(
                    // A row whose key no SSO tenant owns has no known currency, so it
                    // is listed but cannot join any currency's total.
                    array_filter($visibleRows, fn ($r) => $r['currency'] !== null),
                    ['total_wagered', 'total_paid_out', 'platform_profit'],
                )),
            ];
        } else {
            // Tenant admin: replace the platform-wide cards with
            // their-tenant-specific ones. "Total tenants" and
            // "Platform profit" don't apply. One tenant, one currency:
            // zeros in that currency when there is no play yet.
            $row = $visibleRows[0] ?? null;
            $scope = 'tenant';
            $kpis = [
                'currency' => ScopedTotals::currencyOf($user?->tenant),
                'bets_placed' => (int) ($row['bets_placed'] ?? 0),
                'total_wagered' => $row['total_wagered'] ?? '0.00',
                'total_wins' => $row['total_paid_out'] ?? '0.00',
                'tenant_profit' => $row['tenant_profit'] ?? '0.00',
            ];
        }

        return Inertia::render('admin/dashboard', [
            'period' => ['from' => $from, 'to' => $to],
            'kpis' => $kpis,
            'scope' => $scope,
            'tenant_name' => $isPlatformAdmin ? null : ($user?->tenant?->name),
            'tenants' => $visibleRows,
            'by_game' => $byGame,
        ]);
    }

    /**
     * Cross-tenant aggregates from chinga-fantasy over [from, to],
     * with each row's tenant_uuid resolved back to a local Tenant
     * model so we can show a real name and apply the commercial
     * split. Headline figures throughout — see the index() docblock.
     * The split is RevenueSplit (exact decimals), applied per row, and
     * every row carries its tenant's currency.
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
        if (! $user->isPlatformAdmin() && ! $user->isTenantAdmin()) {
            return [];
        }

        $games = GameCatalogue::withBackendForUser($user);
        $byGame = $this->liveStats->byTenant($games, $from, $to);

        // chinga-fantasy stores the SSO tenant slug in its tenant_uuid
        // columns; other games store real UUIDs. The resolver handles both.
        $resolve = $this->liveStats->tenantResolver(
            collect($byGame)->flatMap(fn ($e) => collect($e['rows'])->pluck('tenant_uuid'))->all()
        );

        return collect($byGame)
            ->flatMap(fn ($entry) => collect($entry['rows'])->map(fn ($r) => ['game' => $entry['game'], 'row' => $r]))
            ->map(function ($item) use ($resolve) {
                $r = $item['row'];
                $game = $item['game'];
                $key = $r['tenant_uuid'] ?? null;
                $tenant = $resolve($key);

                $wagered = bcadd(RevenueSplit::decimal($r['total_wagered'] ?? 0), '0', 2);
                $paidOut = bcadd(RevenueSplit::decimal($r['total_paid_out'] ?? 0), '0', 2);
                $split = RevenueSplit::compute($tenant, $wagered, $paidOut);

                return [
                    'game_uuid' => $game->uuid,
                    'game_name' => $game->name,
                    'tenant_id' => $tenant?->id,
                    'tenant_uuid' => $tenant?->uuid ?? $key,
                    'tenant_name' => $tenant?->name ?? $key ?? '—',
                    // The row's money is in its tenant's currency; null only for a key no SSO tenant owns.
                    'currency' => ScopedTotals::currencyOf($tenant),
                    'business_model' => $tenant->business_model ?? 'reseller',
                    'revenue_share_pct' => $tenant ? (float) ($tenant->revenue_share_pct ?? 0) : 0.0,
                    'bets_placed' => (int) ($r['bets_placed'] ?? 0),
                    'active_players' => (int) ($r['active_players'] ?? 0),
                    'total_wagered' => $wagered,
                    'total_paid_out' => $paidOut,
                    'ggr' => $split['ggr'],
                    'ngr' => $split['ngr'],
                    'tenant_profit' => $split['tenant_profit'],
                    'platform_profit' => $split['platform_profit'],
                ];
            })
            ->values()
            ->all();
    }

    private const COUNTS = ['bets_placed', 'active_players'];

    private const MONEY = ['total_wagered', 'total_paid_out', 'ggr', 'ngr', 'tenant_profit', 'platform_profit'];

    /** One row per tenant, columns summed across games (one tenant, one currency). */
    private function foldByTenant(array $flat): array
    {
        $out = [];
        foreach ($flat as $r) {
            $key = $r['tenant_uuid'] ?? $r['tenant_name'];
            if (! isset($out[$key])) {
                $out[$key] = array_diff_key($r, array_flip(['game_uuid', 'game_name']));

                continue;
            }
            $out[$key] = $this->addInto($out[$key], $r);
        }

        return array_values($out);
    }

    /**
     * One row per (game, currency), summed across the visible tenants.
     * Tenants in different currencies never share a row: a game played
     * in NAD and BWP shows twice, once per currency.
     */
    private function foldByGame(array $flat): array
    {
        $out = [];
        foreach ($flat as $r) {
            $key = $r['game_uuid'].'|'.($r['currency'] ?? '');
            if (! isset($out[$key])) {
                $out[$key] = ['game_uuid' => $r['game_uuid'], 'game_name' => $r['game_name'], 'currency' => $r['currency']]
                    + array_fill_keys(self::COUNTS, 0) + array_fill_keys(self::MONEY, '0.00');
            }
            $out[$key] = $this->addInto($out[$key], $r);
        }

        return array_values($out);
    }

    /** Counts as ints, money exactly (bcmath). Callers only add rows of one currency. */
    private function addInto(array $acc, array $r): array
    {
        foreach (self::COUNTS as $col) {
            $acc[$col] += $r[$col];
        }
        foreach (self::MONEY as $col) {
            $acc[$col] = bcadd($acc[$col], $r[$col], 2);
        }

        return $acc;
    }

    /**
     * Tenant admins only see their own tenant's rows in the table;
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
