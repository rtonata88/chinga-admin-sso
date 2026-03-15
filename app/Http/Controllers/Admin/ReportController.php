<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use App\Models\LoginAttempt;
use App\Models\SecurityAuditLog;
use App\Models\SelfExclusion;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Venue;
use App\Models\VoucherCode;
use App\Models\VoucherTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                'kyc' => [
                    'pending_documents' => KycDocument::where('status', 'pending')->count(),
                    'approved_today' => KycDocument::where('status', 'approved')
                        ->whereDate('verified_at', $today)->count(),
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
     * KYC statistics.
     */
    public function kyc(Request $request): JsonResponse
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

        // Documents by status
        $byStatus = KycDocument::selectRaw('status, COUNT(*) as count')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('status')
            ->pluck('count', 'status');

        // Documents by type
        $byType = KycDocument::selectRaw('document_type, status, COUNT(*) as count')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('document_type', 'status')
            ->get()
            ->groupBy('document_type')
            ->map(fn ($items) => $items->pluck('count', 'status'));

        // Users by KYC level
        $byLevel = User::selectRaw('kyc_level, COUNT(*) as count')
            ->groupBy('kyc_level')
            ->pluck('count', 'kyc_level');

        // Average processing time
        $avgProcessingTime = KycDocument::whereIn('status', ['approved', 'rejected'])
            ->whereBetween('verified_at', [$startDate, $endDate])
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, verified_at)) as avg_hours')
            ->value('avg_hours');

        return response()->json([
            'success' => true,
            'data' => [
                'documents' => [
                    'total' => KycDocument::whereBetween('created_at', [$startDate, $endDate])->count(),
                    'by_status' => $byStatus,
                    'by_type' => $byType,
                ],
                'users_by_level' => $byLevel,
                'avg_processing_hours' => round($avgProcessingTime ?? 0, 1),
                'completion_rate' => User::where('kyc_level', '>=', 1)->count() / max(1, User::count()) * 100,
            ],
        ]);
    }

    /**
     * Responsible gambling statistics.
     */
    public function responsibleGambling(Request $request): JsonResponse
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

        // Self-exclusions
        $exclusions = SelfExclusion::selectRaw('type, COUNT(*) as count')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('type')
            ->pluck('count', 'type');

        $activeExclusions = SelfExclusion::active()->count();

        // By duration
        $byDuration = SelfExclusion::selectRaw('
                CASE
                    WHEN type = "permanent" THEN "permanent"
                    WHEN DATEDIFF(ends_at, starts_at) <= 1 THEN "24h"
                    WHEN DATEDIFF(ends_at, starts_at) <= 7 THEN "7d"
                    WHEN DATEDIFF(ends_at, starts_at) <= 30 THEN "30d"
                    ELSE "90d"
                END as duration,
                COUNT(*) as count
            ')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('duration')
            ->pluck('count', 'duration');

        return response()->json([
            'success' => true,
            'data' => [
                'self_exclusions' => [
                    'total_in_period' => SelfExclusion::whereBetween('created_at', [$startDate, $endDate])->count(),
                    'by_type' => $exclusions,
                    'by_duration' => $byDuration,
                    'currently_active' => $activeExclusions,
                ],
                'users_with_limits' => DB::table('responsible_gambling_settings')
                    ->where(function ($q) {
                        $q->whereNotNull('daily_deposit_limit')
                            ->orWhereNotNull('weekly_deposit_limit')
                            ->orWhereNotNull('monthly_deposit_limit');
                    })
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
