<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\VoucherCode;
use App\Services\GameSessionService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoucherWebSessionController extends Controller
{
    public function __construct(
        protected GameSessionService $gameSessionService,
        protected WalletService $walletService
    ) {}

    public function start(Request $request): JsonResponse
    {
        $request->validate([
            'game_id' => ['required', 'string'],
            'code' => ['required', 'string'],
            'pin' => ['nullable', 'string'],
        ]);

        $tenantId = app('current_tenant')?->id;
        if (!$tenantId) {
            return response()->json(['message' => 'Tenant context required.'], 400);
        }

        $game = Game::where('uuid', $request->input('game_id'))->first();
        if (!$game) {
            return response()->json(['message' => 'Game not found.'], 404);
        }

        $code = VoucherCode::where('code', strtoupper($request->input('code')))
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$code) {
            return response()->json(['message' => 'Invalid voucher code.'], 404);
        }

        // Verify PIN if required
        if ($code->hasPin()) {
            if (!$request->input('pin')) {
                return response()->json(['message' => 'PIN is required for this voucher code.'], 422);
            }
            if (!$code->verifyPin($request->input('pin'))) {
                return response()->json(['message' => 'Invalid PIN.'], 422);
            }
        }

        // Check voucher is usable
        if (!$code->canBeUsed()) {
            return response()->json(['message' => 'Voucher code cannot be used.'], 422);
        }

        // Get the linked voucher user
        $user = $code->user;
        if (!$user) {
            return response()->json(['message' => 'No user linked to this voucher. Please contact support.'], 422);
        }

        try {
            // Transfer any remaining voucher balance to the user's wallet
            $wallet = $user->getOrCreateWallet($code->currency);
            if (bccomp($code->balance, '0', 2) > 0) {
                $this->walletService->deposit(
                    $wallet,
                    $code->balance,
                    null,
                    "voucher_transfer_{$code->uuid}"
                );

                // Zero out the voucher balance (funds now in wallet)
                $code->update([
                    'balance' => '0.00',
                    'status' => 'cashed_out',
                ]);
            }

            // Start a wallet-based game session
            $session = $this->gameSessionService->startWalletSession(
                $user,
                $game,
                $request->ip()
            );

            // Issue a Passport token
            $token = $user->createToken('fantasy-voucher', ['openid', 'profile', 'wallet', 'gaming:history']);

            return response()->json([
                'user' => [
                    'uuid' => $user->uuid,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                ],
                'access_token' => $token->accessToken,
                'balance' => $wallet->fresh()->balance,
                'currency' => $wallet->currency,
                'session_token' => $session->session_token,
                'game' => [
                    'uuid' => $game->uuid,
                    'name' => $game->name,
                    'slug' => $game->slug,
                ],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
