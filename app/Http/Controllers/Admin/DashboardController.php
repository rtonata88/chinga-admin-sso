<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Models\Venue;
use App\Models\VoucherCode;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the admin dashboard.
     */
    public function index(): Response
    {
        $today = today();
        $thisWeek = now()->startOfWeek();
        $thisMonth = now()->startOfMonth();

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

        // Recent registrations
        $recentUsers = User::orderBy('created_at', 'desc')
            ->limit(5)
            ->get(['uuid', 'name', 'email', 'created_at', 'status']);

        return Inertia::render('admin/dashboard', [
            'stats' => $stats,
            'recent_users' => $recentUsers->map(fn ($u) => [
                'uuid' => $u->uuid,
                'name' => $u->name,
                'email' => $u->email,
                'status' => $u->status,
                'created_at' => $u->created_at->toIso8601String(),
            ]),
        ]);
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
}
