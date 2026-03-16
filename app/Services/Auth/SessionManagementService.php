<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SessionManagementService
{
    /**
     * Create or update a session for the user.
     */
    public function createSession(User $user, Request $request): UserSession
    {
        $sessionId = session()->getId();

        $session = UserSession::updateOrCreate(
            [
                'user_id' => $user->id,
                'session_id' => $sessionId,
            ],
            [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'last_active_at' => now(),
                'expires_at' => now()->addMinutes(config('session.lifetime', 120)),
            ]
        );

        $session->markAsCurrent();

        return $session;
    }

    /**
     * Update session activity.
     */
    public function updateActivity(User $user, Request $request): void
    {
        $sessionId = session()->getId();

        UserSession::where('user_id', $user->id)
            ->where('session_id', $sessionId)
            ->update([
                'last_active_at' => now(),
                'expires_at' => now()->addMinutes(config('session.lifetime', 120)),
            ]);
    }

    /**
     * Get all active sessions for a user.
     */
    public function getActiveSessions(User $user): Collection
    {
        return UserSession::forUser($user->id)
            ->active()
            ->orderByDesc('last_active_at')
            ->get();
    }

    /**
     * Get the current session.
     */
    public function getCurrentSession(User $user): ?UserSession
    {
        return UserSession::forUser($user->id)
            ->where('session_id', session()->getId())
            ->first();
    }

    /**
     * Revoke a specific session.
     */
    public function revokeSession(User $user, int $sessionId): bool
    {
        $session = UserSession::forUser($user->id)
            ->where('id', $sessionId)
            ->first();

        if (!$session) {
            return false;
        }

        // Don't allow revoking the current session through this method
        if ($session->session_id === session()->getId()) {
            throw new \RuntimeException('Cannot revoke the current session. Use logout instead.');
        }

        // Invalidate the session in Laravel's session store if possible
        // Note: This requires database session driver
        if (config('session.driver') === 'database') {
            \DB::table(config('session.table', 'sessions'))
                ->where('id', $session->session_id)
                ->delete();
        }

        $session->delete();

        return true;
    }

    /**
     * Revoke all sessions except the current one.
     */
    public function revokeAllOtherSessions(User $user): int
    {
        $currentSessionId = session()->getId();

        $sessions = UserSession::forUser($user->id)
            ->where('session_id', '!=', $currentSessionId)
            ->get();

        $count = 0;

        foreach ($sessions as $session) {
            // Invalidate in Laravel's session store
            if (config('session.driver') === 'database') {
                \DB::table(config('session.table', 'sessions'))
                    ->where('id', $session->session_id)
                    ->delete();
            }

            $session->delete();
            $count++;
        }

        return $count;
    }

    /**
     * Revoke all sessions for a user (on logout everywhere).
     */
    public function revokeAllSessions(User $user): int
    {
        $sessions = UserSession::forUser($user->id)->get();

        $count = 0;

        foreach ($sessions as $session) {
            if (config('session.driver') === 'database') {
                \DB::table(config('session.table', 'sessions'))
                    ->where('id', $session->session_id)
                    ->delete();
            }

            $session->delete();
            $count++;
        }

        return $count;
    }

    /**
     * Clean up expired sessions.
     */
    public function cleanupExpired(): int
    {
        return UserSession::expired()->delete();
    }

    /**
     * Get session statistics for a user.
     */
    public function getSessionStats(User $user): array
    {
        $sessions = $this->getActiveSessions($user);

        return [
            'total_active' => $sessions->count(),
            'devices' => $sessions->pluck('device_type')->countBy(),
            'locations' => $sessions->pluck('country_code')->filter()->countBy(),
            'most_recent' => $sessions->first()?->last_active_at,
        ];
    }
}
