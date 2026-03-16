<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use App\Models\SecurityAuditLog;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Venue;
use App\Models\VoucherCode;
use App\Models\VoucherTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        // Active sessions
        $activeSessions = UserSession::where('last_activity_at', '>=', now()->subHours(24))->count();

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
     * Audit log viewer.
     */
    public function auditLogs(Request $request): JsonResponse
    {
        $query = SecurityAuditLog::with('user:id,uuid,name,email');

        // Filter by action
        if ($action = $request->input('action')) {
            $query->where('action', 'like', "%{$action}%");
        }

        // Filter by user
        if ($userUuid = $request->input('user_uuid')) {
            $user = User::where('uuid', $userUuid)->first();
            if ($user) {
                $query->where('user_id', $user->id);
            }
        }

        // Filter by date range
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
            'data' => $logs->map(fn ($log) => [
                'id' => $log->id,
                'user' => $log->user ? [
                    'uuid' => $log->user->uuid,
                    'name' => $log->user->name,
                    'email' => $log->user->email,
                ] : null,
                'action' => $log->action,
                'description' => $log->description,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'created_at' => $log->created_at->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
