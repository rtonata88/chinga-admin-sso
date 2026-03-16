<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\VoucherCode;
use App\Services\GameSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameSessionController extends Controller
{
    public function __construct(
        protected GameSessionService $gameSessionService
    ) {}

    /**
     * Start a wallet-based game session (authenticated user).
     */
    public function startWalletSession(Request $request): JsonResponse
    {
        $request->validate([
            'game_id' => ['required', 'string'],
        ]);

        $game = Game::where('uuid', $request->input('game_id'))->first();

        if (!$game) {
            return response()->json([
                'message' => 'Game not found.',
            ], 404);
        }

        try {
            $session = $this->gameSessionService->startWalletSession(
                $request->user(),
                $game,
                $request->ip()
            );

            return response()->json([
                'session_token' => $session->session_token,
                'balance' => $session->balance_start,
                'currency' => $request->user()->getOrCreateWallet()->currency,
                'game' => [
                    'uuid' => $game->uuid,
                    'name' => $game->name,
                    'slug' => $game->slug,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Start a terminal/voucher code-based game session.
     */
    public function startTerminalSession(Request $request): JsonResponse
    {
        $request->validate([
            'game_id' => ['required', 'string'],
            'code' => ['required', 'string'],
            'pin' => ['nullable', 'string'],
        ]);

        $game = Game::where('uuid', $request->input('game_id'))->first();

        if (!$game) {
            return response()->json([
                'message' => 'Game not found.',
            ], 404);
        }

        $terminal = $request->input('terminal');
        $voucherCode = VoucherCode::where('code', strtoupper($request->input('code')))
            ->where('venue_id', $terminal->venue_id)
            ->first();

        if (!$voucherCode) {
            return response()->json([
                'message' => 'Invalid voucher code.',
            ], 404);
        }

        try {
            $session = $this->gameSessionService->startVoucherSession(
                $voucherCode,
                $game,
                $terminal,
                $request->input('pin'),
                $request->ip()
            );

            return response()->json([
                'session_token' => $session->session_token,
                'balance' => $session->balance_start,
                'currency' => $voucherCode->currency,
                'game' => [
                    'uuid' => $game->uuid,
                    'name' => $game->name,
                    'slug' => $game->slug,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * End the current game session.
     */
    public function endSession(Request $request): JsonResponse
    {
        $session = $request->attributes->get('gameSession');

        $request->validate([
            'reason' => ['nullable', 'string', 'in:logout,timeout,cashed_out'],
        ]);

        try {
            $endedSession = $this->gameSessionService->endSession(
                $session->session_token,
                $request->input('reason', 'logout')
            );

            return response()->json([
                'message' => 'Session ended.',
                'balance_start' => $endedSession->balance_start,
                'balance_end' => $endedSession->balance_end,
                'net_result' => $endedSession->net_result,
                'duration_minutes' => round($endedSession->duration_minutes, 1),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get current session info.
     */
    public function sessionInfo(Request $request): JsonResponse
    {
        $session = $request->attributes->get('gameSession');

        try {
            $info = $this->gameSessionService->getSessionInfo($session->session_token);

            return response()->json([
                'session' => [
                    'uuid' => $info->uuid,
                    'started_at' => $info->started_at->toIso8601String(),
                    'balance_start' => $info->balance_start,
                    'duration_minutes' => round($info->duration_minutes, 1),
                ],
                'game' => [
                    'uuid' => $info->game->uuid,
                    'name' => $info->game->name,
                    'slug' => $info->game->slug,
                ],
                'terminal' => $info->terminal ? [
                    'uuid' => $info->terminal->uuid,
                    'name' => $info->terminal->name,
                ] : null,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get current balance.
     */
    public function balance(Request $request): JsonResponse
    {
        $session = $request->attributes->get('gameSession');

        try {
            $data = $this->gameSessionService->getBalance($session->session_token);

            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Debit (bet/wager).
     */
    public function debit(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $session = $request->attributes->get('gameSession');

        try {
            $transaction = $this->gameSessionService->debit(
                $session->session_token,
                (string) $request->input('amount'),
                $request->input('reference')
            );

            return response()->json([
                'transaction_id' => $transaction->uuid,
                'amount' => abs((float) $transaction->amount),
                'balance_before' => $transaction->balance_before,
                'balance_after' => $transaction->balance_after,
            ]);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Insufficient balance')) {
                return response()->json([
                    'message' => $e->getMessage(),
                ], 402);
            }

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Credit (win).
     */
    public function credit(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $session = $request->attributes->get('gameSession');

        try {
            $transaction = $this->gameSessionService->credit(
                $session->session_token,
                (string) $request->input('amount'),
                $request->input('reference')
            );

            return response()->json([
                'transaction_id' => $transaction->uuid,
                'amount' => (float) $transaction->amount,
                'balance_before' => $transaction->balance_before,
                'balance_after' => $transaction->balance_after,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get recent transactions for the session.
     */
    public function transactions(Request $request): JsonResponse
    {
        $session = $request->attributes->get('gameSession');

        try {
            $transactions = $this->gameSessionService->getTransactions(
                $session->session_token,
                (int) $request->input('limit', 20)
            );

            return response()->json([
                'transactions' => $transactions->map(fn ($tx) => [
                    'uuid' => $tx->uuid,
                    'type' => $tx->type,
                    'amount' => $tx->amount,
                    'balance_after' => $tx->balance_after,
                    'reference' => $tx->reference,
                    'created_at' => $tx->created_at->toIso8601String(),
                ]),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
