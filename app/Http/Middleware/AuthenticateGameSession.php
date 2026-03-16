<?php

namespace App\Http\Middleware;

use App\Models\GameSession;
use App\Models\VoucherCode;
use App\Models\Wallet;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateGameSession
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'message' => 'Session token is required.',
            ], 401);
        }

        // Verify gs_ prefix
        if (!str_starts_with($token, 'gs_')) {
            return response()->json([
                'message' => 'Invalid session token format.',
            ], 401);
        }

        $session = GameSession::where('session_token', $token)
            ->with(['source', 'game', 'terminal'])
            ->first();

        if (!$session) {
            return response()->json([
                'message' => 'Invalid session token.',
            ], 401);
        }

        // Check if session has ended
        if ($session->ended_at !== null) {
            return response()->json([
                'message' => 'Session has ended.',
                'end_reason' => $session->end_reason,
            ], 401);
        }

        // Check 30-minute inactivity timeout
        if ($session->updated_at->diffInMinutes(now()) > 30) {
            $session->end('timeout');

            return response()->json([
                'message' => 'Session has timed out due to inactivity.',
            ], 401);
        }

        // Verify source is still active
        $source = $session->source;

        if ($session->source_type === Wallet::class) {
            if (!$source->isActive()) {
                $session->end('forced');

                return response()->json([
                    'message' => 'Wallet is no longer active.',
                ], 401);
            }
        } elseif ($session->source_type === VoucherCode::class) {
            if ($source->isExpired()) {
                $session->end('forced');

                return response()->json([
                    'message' => 'Voucher code has expired.',
                ], 401);
            }
        }

        // If terminal session, verify terminal is still active
        if ($session->terminal_id && $session->terminal) {
            if (!$session->terminal->isActive()) {
                $session->end('forced');

                return response()->json([
                    'message' => 'Terminal is no longer active.',
                ], 401);
            }
        }

        // Touch session to extend timeout
        $session->touch();

        // Attach session to request
        $request->attributes->set('gameSession', $session);

        return $next($request);
    }
}
