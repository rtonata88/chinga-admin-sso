<?php

namespace App\Http\Controllers\Terminal;

use App\Http\Controllers\Controller;
use App\Models\VenueTerminal;
use App\Models\VoucherCode;
use App\Services\Venue\VoucherCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        protected VoucherCodeService $voucherCodeService
    ) {}

    /**
     * Authenticate terminal with API key.
     */
    public function authenticateTerminal(Request $request): JsonResponse
    {
        $request->validate([
            'terminal_id' => ['required', 'string'],
            'venue_id' => ['required', 'string'],
        ]);

        $apiKey = $request->header('X-Terminal-Key');

        if (!$apiKey) {
            return response()->json([
                'success' => false,
                'message' => 'Terminal API key is required.',
            ], 401);
        }

        $hashedKey = hash('sha256', $apiKey);
        $terminal = VenueTerminal::where('api_key', $hashedKey)
            ->whereHas('venue', fn ($q) => $q->where('uuid', $request->input('venue_id')))
            ->where('uuid', $request->input('terminal_id'))
            ->first();

        if (!$terminal) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid terminal credentials.',
            ], 401);
        }

        if (!$terminal->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Terminal is not active.',
            ], 403);
        }

        if (!$terminal->venue->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Venue is not active.',
            ], 403);
        }

        $terminal->recordHeartbeat($request->ip());

        return response()->json([
            'success' => true,
            'message' => 'Terminal authenticated.',
            'terminal' => [
                'uuid' => $terminal->uuid,
                'name' => $terminal->name,
                'type' => $terminal->type,
            ],
            'venue' => [
                'uuid' => $terminal->venue->uuid,
                'name' => $terminal->venue->name,
                'currency' => $terminal->venue->currency,
            ],
        ]);
    }

    /**
     * Terminal heartbeat.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $terminal = $request->terminal;

        $terminal->recordHeartbeat($request->ip());

        return response()->json([
            'success' => true,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Authenticate player with voucher code.
     */
    public function authenticateCode(Request $request): JsonResponse
    {
        $terminal = $request->terminal;

        $request->validate([
            'code' => ['required', 'string', 'min:4', 'max:20'],
            'pin' => ['nullable', 'string', 'size:4'],
        ]);

        // Rate limiting
        $key = 'code-auth:' . $terminal->venue_id . ':' . $request->input('code');
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'code' => ["Too many attempts. Please try again in {$seconds} seconds."],
            ]);
        }

        $voucherCode = $this->voucherCodeService->findCodeAtVenue(
            $request->input('code'),
            $terminal->venue
        );

        if (!$voucherCode) {
            RateLimiter::hit($key, 300);

            return response()->json([
                'success' => false,
                'message' => 'Invalid voucher code.',
            ], 401);
        }

        // Check PIN if required
        if ($voucherCode->hasPin()) {
            if (!$request->input('pin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'PIN is required for this code.',
                    'requires_pin' => true,
                ], 401);
            }

            if (!$voucherCode->verifyPin($request->input('pin'))) {
                RateLimiter::hit($key, 300);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid PIN.',
                ], 401);
            }
        }

        // Check code status
        if ($voucherCode->isExpired()) {
            return response()->json([
                'success' => false,
                'message' => 'This voucher code has expired.',
            ], 401);
        }

        if ($voucherCode->status === 'deactivated') {
            return response()->json([
                'success' => false,
                'message' => 'This voucher code has been deactivated.',
            ], 401);
        }

        if ($voucherCode->isInUse() && $voucherCode->current_terminal_id !== $terminal->id) {
            return response()->json([
                'success' => false,
                'message' => 'This code is in use on another terminal.',
            ], 401);
        }

        if (!$voucherCode->hasBalance()) {
            return response()->json([
                'success' => false,
                'message' => 'This voucher code has no balance.',
            ], 401);
        }

        RateLimiter::clear($key);

        try {
            // Start session
            $session = $this->voucherCodeService->startSession(
                $voucherCode,
                $terminal,
                $request->ip()
            );

            return response()->json([
                'success' => true,
                'session_token' => $session->session_token,
                'code_info' => [
                    'code' => $voucherCode->masked_code,
                    'balance' => $voucherCode->balance,
                    'currency' => $voucherCode->currency,
                    'expires_at' => $voucherCode->expires_at?->toIso8601String(),
                    'has_pin' => $voucherCode->hasPin(),
                    'venue_name' => $terminal->venue->name,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Verify PIN for an active session.
     */
    public function verifyPin(Request $request): JsonResponse
    {
        $request->validate([
            'pin' => ['required', 'string', 'size:4'],
        ]);

        $voucherCode = $request->voucher_code;

        if (!$voucherCode->verifyPin($request->input('pin'))) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid PIN.',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'message' => 'PIN verified.',
        ]);
    }

    /**
     * End voucher code session (logout).
     */
    public function logout(Request $request): JsonResponse
    {
        $session = $request->voucher_session;

        $this->voucherCodeService->endSession($session, 'logout');

        return response()->json([
            'success' => true,
            'message' => 'Session ended.',
            'final_balance' => $session->voucherCode->balance,
        ]);
    }

    /**
     * Get current session info.
     */
    public function sessionInfo(Request $request): JsonResponse
    {
        $session = $request->voucher_session;
        $voucherCode = $request->voucher_code;

        return response()->json([
            'session' => [
                'uuid' => $session->uuid,
                'started_at' => $session->started_at->toIso8601String(),
                'balance_start' => $session->balance_start,
                'duration_minutes' => $session->duration_minutes,
            ],
            'code_info' => [
                'code' => $voucherCode->masked_code,
                'balance' => $voucherCode->balance,
                'currency' => $voucherCode->currency,
                'expires_at' => $voucherCode->expires_at?->toIso8601String(),
            ],
        ]);
    }
}
