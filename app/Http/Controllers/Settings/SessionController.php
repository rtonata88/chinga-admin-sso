<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Auth\SecurityAuditService;
use App\Services\Auth\SessionManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SessionController extends Controller
{
    public function __construct(
        protected SessionManagementService $sessionService,
        protected SecurityAuditService $auditService
    ) {}

    /**
     * Display active sessions.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $sessions = $this->sessionService->getActiveSessions($user);
        $currentSession = $this->sessionService->getCurrentSession($user);

        return Inertia::render('settings/sessions', [
            'sessions' => $sessions->map(fn ($session) => [
                'id' => $session->id,
                'device_info' => $session->device_info,
                'device_type' => $session->device_type,
                'browser' => $session->browser,
                'platform' => $session->platform,
                'location' => $session->location,
                'ip_address' => $session->ip_address,
                'is_current' => $session->session_id === $currentSession?->session_id,
                'last_active_at' => $session->last_active_at->toIso8601String(),
                'created_at' => $session->created_at->toIso8601String(),
            ]),
            'stats' => $this->sessionService->getSessionStats($user),
        ]);
    }

    /**
     * Revoke a specific session.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        try {
            $revoked = $this->sessionService->revokeSession($user, $id);

            if (!$revoked) {
                return response()->json([
                    'message' => 'Session not found.',
                ], 404);
            }

            $this->auditService->logSessionRevoked($user, 1);

            return response()->json([
                'message' => 'Session has been revoked.',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Revoke all other sessions.
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        $user = $request->user();
        $count = $this->sessionService->revokeAllOtherSessions($user);

        if ($count > 0) {
            $this->auditService->logSessionRevoked($user, $count);
        }

        return response()->json([
            'message' => "{$count} session(s) have been revoked.",
            'revoked_count' => $count,
        ]);
    }
}
