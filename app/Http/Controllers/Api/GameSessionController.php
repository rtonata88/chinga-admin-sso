<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameSession;
use App\Models\VoucherCode;
use App\Services\GameSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                // Pool split — the bet drained these amounts from each pool.
                // chinga-fantasy uses amount_from_deposit to compute jackpot
                // contributions (jackpot only grows from real deposits).
                'amount_from_deposit' => $transaction->amount_from_deposit ?? '0.00',
                'amount_from_winnings' => $transaction->amount_from_winnings ?? '0.00',
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
     * Service-to-service settlement credit.
     * Requires client_credentials token with wallet:write scope.
     * Accepts a session token and credits that session's source even if the session is no longer active.
     */
    public function settlementCredit(Request $request): JsonResponse
    {
        $request->validate([
            'session_token' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference' => ['required', 'string', 'max:100'],
        ]);

        $sessionToken = $request->input('session_token');
        $clientId = $this->resolveClientId($request);

        if (!$clientId) {
            return response()->json([
                'message' => 'Unable to identify OAuth client for this request.',
            ], 401);
        }

        $session = GameSession::where('session_token', $sessionToken)->first();
        if (!$session) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        // Enforce per-client game binding: an OAuth client may only credit
        // sessions of games it has been authorized for via oauth_client_games.
        $allowed = DB::table('oauth_client_games')
            ->where('oauth_client_id', $clientId)
            ->where('game_id', $session->game_id)
            ->exists();

        if (!$allowed) {
            return response()->json([
                'message' => 'OAuth client is not authorized to credit this game.',
            ], 403);
        }

        try {
            $transaction = $this->gameSessionService->settlementCredit(
                $sessionToken,
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
     * Resolve the OAuth client_id from the current bearer token (client_credentials grant).
     */
    private function resolveClientId(Request $request): ?string
    {
        $token = $request->user()?->currentAccessToken();
        if ($token && !empty($token->oauth_client_id)) {
            return (string) $token->oauth_client_id;
        }
        return null;
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
