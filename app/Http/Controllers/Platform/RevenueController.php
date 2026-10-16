<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\TenantRevenueRecord;
use App\Support\Currencies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform revenue. A revenue record is in its tenant's currency (tenants.currency,
 * immutable once wallets exist), so every figure here carries a currency and money
 * in different currencies is never added together: no exchange rate is applied.
 */
class RevenueController extends Controller
{
    private const MONEY = ['total_bets', 'total_wins', 'gross_gaming_revenue', 'tax_amount', 'net_gaming_revenue', 'chinga_share', 'tenant_share'];

    public function index(Request $request): JsonResponse
    {
        $query = TenantRevenueRecord::with(['tenant:id,uuid,name,slug,currency', 'game:id,uuid,name']);

        if ($tenantUuid = $request->input('tenant_uuid')) {
            $query->whereHas('tenant', fn ($q) => $q->where('uuid', $tenantUuid));
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
            ->paginate($request->input('per_page', 25))
            ->through(fn (TenantRevenueRecord $r) => $r->setAttribute('currency', $r->tenant?->currency));

        return response()->json($records);
    }

    public function summary(Request $request): JsonResponse
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());

        $sums = collect(self::MONEY)
            ->map(fn (string $f) => "SUM(tenant_revenue_records.{$f}) as {$f}")
            ->implode(', ');

        $inPeriod = fn () => TenantRevenueRecord::query()
            ->join('tenants', 'tenants.id', '=', 'tenant_revenue_records.tenant_id')
            ->where('tenant_revenue_records.period_start', '>=', $from)
            ->where('tenant_revenue_records.period_end', '<=', $to);

        // One row per tenant, in that tenant's currency.
        $perTenant = $inPeriod()
            ->with('tenant:id,uuid,name,business_model,currency')
            ->selectRaw("tenant_revenue_records.tenant_id, tenants.currency as currency,
                MAX(tenant_revenue_records.business_model) as business_model, {$sums}")
            ->groupBy('tenant_revenue_records.tenant_id', 'tenants.currency')
            ->get();

        // One row per (game, currency): a game played on a NAD and a BWP tenant has two rows.
        $perGame = $inPeriod()
            ->with('game:id,uuid,name')
            ->selectRaw("tenant_revenue_records.game_id, tenants.currency as currency, {$sums}")
            ->groupBy('tenant_revenue_records.game_id', 'tenants.currency')
            ->orderBy('tenant_revenue_records.game_id')
            ->orderBy('tenants.currency')
            ->get();

        return response()->json([
            // [{currency, total_bets, ..., tenant_share}], one entry per currency; empty with no records.
            'totals' => Currencies::totalsByCurrency($perTenant->map(fn ($r) => $r->only(['currency', ...self::MONEY])), self::MONEY),
            'per_tenant' => $perTenant,
            'by_game' => $perGame,
        ]);
    }
}
