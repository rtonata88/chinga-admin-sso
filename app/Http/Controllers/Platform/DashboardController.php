<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantRevenueRecord;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'total_tenants' => Tenant::count(),
                'active_tenants' => Tenant::where('status', 'active')->count(),
                'total_players' => User::whereNotNull('tenant_id')->count(),
                'total_venues' => Venue::withoutGlobalScopes()->count(),
                'revenue_this_month' => TenantRevenueRecord::where('period_start', '>=', now()->startOfMonth())
                    ->sum('gross_gaming_revenue'),
                'chinga_share_this_month' => TenantRevenueRecord::where('period_start', '>=', now()->startOfMonth())
                    ->sum('chinga_share'),
                'recent_tenants' => Tenant::latest()->limit(5)->get(['uuid', 'name', 'slug', 'status', 'created_at']),
            ],
        ]);
    }
}
