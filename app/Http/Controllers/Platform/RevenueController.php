<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\TenantRevenueRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RevenueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TenantRevenueRecord::with(['tenant:id,uuid,name,slug', 'game:id,uuid,name']);

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
            ->paginate($request->input('per_page', 25));

        return response()->json($records);
    }

    public function summary(Request $request): JsonResponse
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());

        $totals = TenantRevenueRecord::where('period_start', '>=', $from)
            ->where('period_end', '<=', $to)
            ->selectRaw('
                SUM(total_bets) as total_bets,
                SUM(total_wins) as total_wins,
                SUM(gross_gaming_revenue) as gross_gaming_revenue,
                SUM(tax_amount) as tax_amount,
                SUM(net_gaming_revenue) as net_gaming_revenue,
                SUM(chinga_share) as chinga_share,
                SUM(tenant_share) as tenant_share
            ')
            ->first();

        $perTenant = TenantRevenueRecord::with('tenant:id,uuid,name,business_model')
            ->where('period_start', '>=', $from)
            ->where('period_end', '<=', $to)
            ->selectRaw('
                tenant_id,
                MAX(business_model) as business_model,
                SUM(total_bets) as total_bets,
                SUM(gross_gaming_revenue) as gross_gaming_revenue,
                SUM(tax_amount) as tax_amount,
                SUM(net_gaming_revenue) as net_gaming_revenue,
                SUM(chinga_share) as chinga_share,
                SUM(tenant_share) as tenant_share
            ')
            ->groupBy('tenant_id')
            ->get();

        return response()->json([
            'totals' => $totals,
            'per_tenant' => $perTenant,
        ]);
    }
}
