<?php

namespace App\Http\Middleware;

use App\Models\VoucherSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateVoucherSession
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

        $session = VoucherSession::where('session_token', $token)
            ->with(['voucherCode', 'voucherCode.venue'])
            ->first();

        if (!$session) {
            return response()->json([
                'message' => 'Invalid session token.',
            ], 401);
        }

        if (!$session->isActive()) {
            return response()->json([
                'message' => 'Session has ended.',
                'end_reason' => $session->end_reason,
            ], 401);
        }

        $voucherCode = $session->voucherCode;

        if ($voucherCode->isExpired()) {
            // Auto-end session if code expired
            $session->end('forced');

            return response()->json([
                'message' => 'Voucher code has expired.',
            ], 401);
        }

        if (!$voucherCode->venue->isActive()) {
            return response()->json([
                'message' => 'This venue is not active.',
            ], 403);
        }

        // Attach session and voucher code to request
        $request->merge([
            'voucher_session' => $session,
            'voucher_code' => $voucherCode,
        ]);

        // Update last activity
        $voucherCode->touchActivity();

        return $next($request);
    }
}
