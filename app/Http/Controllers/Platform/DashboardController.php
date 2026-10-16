<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantRevenueRecord;
use App\Models\User;
use App\Models\Venue;
use App\Support\Currencies;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        // A revenue record is in its tenant's currency. Different currencies are
        // never added together: the month's revenue is one figure per currency.
        $thisMonth = TenantRevenueRecord::query()
            ->join('tenants', 'tenants.id', '=', 'tenant_revenue_records.tenant_id')
            ->where('tenant_revenue_records.period_start', '>=', now()->startOfMonth())
            ->get(['tenants.currency', 'tenant_revenue_records.gross_gaming_revenue', 'tenant_revenue_records.chinga_share'])
            ->map(fn ($r) => $r->getAttributes());

        return response()->json([
            'data' => [
                'total_tenants' => Tenant::count(),
                'active_tenants' => Tenant::where('status', 'active')->count(),
                'total_players' => User::whereNotNull('tenant_id')->count(),
                'total_venues' => Venue::withoutGlobalScopes()->count(),
                // [{currency, gross_gaming_revenue, chinga_share}], replacing the
                // scalar revenue_this_month / chinga_share_this_month that mixed currencies.
                'revenue_this_month_by_currency' => Currencies::totalsByCurrency($thisMonth, ['gross_gaming_revenue', 'chinga_share']),
                'recent_tenants' => Tenant::latest()->limit(5)->get(['uuid', 'name', 'slug', 'status', 'currency', 'created_at']),
            ],
        ]);
    }
}
