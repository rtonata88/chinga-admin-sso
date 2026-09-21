<?php

namespace App\Http\Controllers;

use App\Models\UserSession;
use App\Services\LiveActivity;
use App\Support\GameCatalogue;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class UserDashboardController extends Controller
{
    public function __invoke(LiveActivity $activity): Response
    {
        $user = auth()->user();

        $isAdmin = $user->isPlatformAdmin() || $user->isTenantAdmin();
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

        // Live activity — admins only, from every game backend in the
        // catalogue (PRD §4 P6). A tenant admin sees their tenant; a
        // platform admin the cross-tenant view. A backend that is down
        // is named on the page rather than failing it.
        $wagerStats = null;
        $wagerSpark = null;
        $recentBets = [];
        $perGame = [];
        $errors = [];
        if ($isAdmin) {
            $tenant = $user->isPlatformAdmin() ? null : $user->tenant;
            $feed = $activity->feed(GameCatalogue::withBackendForUser($user, $tenant), $tenant, Carbon::now());
            $wagerStats = ['today' => $feed['today'], 'yesterday' => $feed['yesterday']];
            $wagerSpark = $feed['spark'];
            $recentBets = $feed['recent_bets'];
            $perGame = $feed['per_game'];
            $errors = $feed['errors'];
        }

        return Inertia::render('dashboard', [
            'account' => $account,
            'sessions' => $sessions,
            'active_session_count' => $activeSessionCount,
            'wallet' => $walletData,
            'is_admin' => $isAdmin,
            'wager_stats' => $wagerStats,
            'wager_spark' => $wagerSpark,
            'recent_bets' => $recentBets,
            'per_game' => $perGame,
            'feed_errors' => $errors,
            'last_updated' => Carbon::now('Africa/Windhoek')->format('H:i:s').' CAT',
        ]);
    }

    private function walletData($user): ?array
    {
        $wallet = $user->wallet ?? null;
        if (! $wallet) {
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
