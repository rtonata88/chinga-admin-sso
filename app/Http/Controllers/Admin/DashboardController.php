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

        $fantasy = $this->fetchFantasyStats();

        return Inertia::render('admin/dashboard', [
            'stats' => $stats,
            'recent_users' => $recentUsers->map(fn ($u) => [
                'uuid' => $u->uuid,
                'name' => $u->name,
                'email' => $u->email,
                'status' => $u->status,
                'created_at' => $u->created_at->toIso8601String(),
            ]),
            'fantasy' => $fantasy,
        ]);
    }

    /**
     * Fetch Chinga Fantasy metrics for the current tenant. Returns null
     * on failure so the dashboard still renders if chinga-fantasy is down.
     */
    private function fetchFantasyStats(): ?array
    {
        $tenant = app('current_tenant');
        $tenantUuid = $tenant?->uuid;
        $from = now()->subDays(30)->startOfDay()->toIso8601String();
        $to = now()->toIso8601String();

        try {
            $summary = $this->fantasyAdminClient->statsSummary($tenantUuid, $from, $to);
            $byDay = $this->fantasyAdminClient->statsByDay($tenantUuid, $from, $to);
            $rounds = $this->fantasyAdminClient->listRounds($tenantUuid, 5, 0);

            return [
                'period' => ['from' => $from, 'to' => $to],
                'summary' => $summary,
                'by_day' => $byDay['days'] ?? [],
                'recent_rounds' => $rounds['data'] ?? [],
            ];
        } catch (\Throwable $e) {
            Log::warning('Fantasy stats fetch failed', ['error' => $e->getMessage()]);
            return null;
        }
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
}
