<?php

namespace App\Http\Controllers;

use App\Models\UserSession;
use App\Services\FantasyAdminClient;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class UserDashboardController extends Controller
{
    public function __invoke(FantasyAdminClient $fantasy): Response
    {
        $user = auth()->user();

        $isAdmin = $user->isPlatformAdmin() || $user->isTenantAdmin();
        $tenantUuid = $isAdmin && method_exists($user, 'tenant') ? optional($user->tenant)->uuid : null;

        // Account overview
        $account = [
            'name' => $user->name,
            'email' => $user->email,
            'display_name' => $user->display_name,
            'avatar_url' => $user->avatar_url,
            'status' => $user->status ?? 'active',
            'email_verified' => $user->email_verified_at !== null,
            'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
            'member_since' => $user->created_at->toIso8601String(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];

        // Recent sessions
        $sessions = UserSession::where('user_id', $user->id)
            ->orderBy('last_active_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($session) => [
                'device_type' => $session->device_type,
                'browser' => $session->browser,
                'platform' => $session->platform,
                'ip_address' => $session->ip_address,
                'city' => $session->city,
                'country_code' => $session->country_code,
                'is_current' => $session->is_current,
                'last_active_at' => $session->last_active_at?->toIso8601String(),
            ]);

        $activeSessionCount = UserSession::where('user_id', $user->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->count();

        // Wallet data — only meaningful for non-admin player accounts.
        $walletData = $this->walletData($user);

        // Live wager metrics — admins only. The chinga-fantasy backend
        // owns the source of truth; we read it through the
        // service-to-service FantasyAdminClient. Anything that throws
        // is silently swallowed so the dashboard never 500s — the page
        // will render zero-state cards if the upstream is down.
        $wagerStats = null;
        $wagerSpark = null;
        $recentRounds = [];

        if ($isAdmin) {
            try {
                $now = Carbon::now();
                $today = $now->copy()->startOfDay();
                $yesterday = $today->copy()->subDay();

                $todayStats = $fantasy->statsSummary($tenantUuid, $today->toIso8601String(), $now->toIso8601String());
                $yesterdayStats = $fantasy->statsSummary(
                    $tenantUuid,
                    $yesterday->toIso8601String(),
                    $today->toIso8601String(),
                );

                $weekStart = $now->copy()->subDays(7)->startOfDay();
                $byDay = $fantasy->statsByDay($tenantUuid, $weekStart->toIso8601String(), $now->toIso8601String());

                $wagerStats = [
                    'today' => $todayStats,
                    'yesterday' => $yesterdayStats,
                ];
                // Pull a small numeric series for the Handle sparkline. AdminController
                // returns rows with `bets_placed` / `total_wagered` keys.
                $wagerSpark = collect($byDay['rows'] ?? $byDay['days'] ?? [])
                    ->map(fn ($row) => (float) ($row['total_wagered'] ?? 0))
                    ->values()
                    ->take(-8)
                    ->all();

                $rounds = $fantasy->listRounds($tenantUuid, 6, 0);
                $recentRounds = collect($rounds['rounds'] ?? $rounds['rows'] ?? [])
                    ->map(fn ($r) => [
                        'id' => $r['id'] ?? null,
                        'round_number' => $r['round_number'] ?? null,
                        'tenant_uuid' => $r['tenant_uuid'] ?? null,
                        'created_at' => $r['created_at'] ?? null,
                        'bet_count' => $r['bet_count'] ?? $r['bets'] ?? null,
                        'total_wagered' => isset($r['total_wagered']) ? (float) $r['total_wagered'] : null,
                    ])
                    ->all();
            } catch (Throwable $e) {
                logger()->warning('UserDashboardController fantasy stats fetch failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return Inertia::render('dashboard', [
            'account' => $account,
            'sessions' => $sessions,
            'active_session_count' => $activeSessionCount,
            'wallet' => $walletData,
            'is_admin' => $isAdmin,
            'wager_stats' => $wagerStats,
            'wager_spark' => $wagerSpark,
            'recent_rounds' => $recentRounds,
            'last_updated' => Carbon::now('Africa/Windhoek')->format('H:i:s') . ' CAT',
        ]);
    }

    private function walletData($user): ?array
    {
        $wallet = $user->wallet ?? null;
        if (!$wallet) {
            return null;
        }

        $recentTransactions = $wallet->transactions()
            ->with('gameSession.game')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($t) => [
                'type' => $t->type,
                'amount' => (float) $t->amount,
                'balance_after' => (float) $t->balance_after,
                'description' => $t->description,
                'game_name' => $t->gameSession?->game?->name,
                'created_at' => $t->created_at->toIso8601String(),
            ]);

        return [
            'balance' => (float) $wallet->balance,
            'currency' => $wallet->currency ?? 'NAD',
            'status' => $wallet->status,
            'total_deposited' => (float) $wallet->total_deposited,
            'total_withdrawn' => (float) $wallet->total_withdrawn,
            'total_won' => (float) $wallet->total_won,
            'total_lost' => (float) $wallet->total_lost,
            'recent_transactions' => $recentTransactions,
        ];
    }
}
