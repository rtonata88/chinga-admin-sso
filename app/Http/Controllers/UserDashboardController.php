<?php

namespace App\Http\Controllers;

use App\Models\UserSession;
use Inertia\Inertia;
use Inertia\Response;

class UserDashboardController extends Controller
{
    public function __invoke(): Response
    {
        $user = auth()->user();

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

        // Active sessions count
        $activeSessionCount = UserSession::where('user_id', $user->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->count();

        return Inertia::render('dashboard', [
            'account' => $account,
            'sessions' => $sessions,
            'active_session_count' => $activeSessionCount,
        ]);
    }
}
