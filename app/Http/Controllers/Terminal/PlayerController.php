<?php

namespace App\Http\Controllers\Terminal;

use App\Http\Controllers\Controller;
use App\Services\Venue\VoucherCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlayerController extends Controller
{
    public function __construct(
        protected VoucherCodeService $voucherCodeService
    ) {}

    /**
     * Get player's current balance.
     */
    public function balance(Request $request): JsonResponse
    {
        $voucherCode = $request->voucher_code;

        return response()->json([
            'success' => true,
            'balance' => $voucherCode->balance,
            'currency' => $voucherCode->currency,
        ]);
    }

    /**
     * Check if player can place a bet/wager.
     */
    public function canPlay(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $voucherCode = $request->voucher_code;
        $amount = $request->input('amount');

        $allowed = $voucherCode->hasSufficientBalance($amount);

        return response()->json([
            'allowed' => $allowed,
            'balance' => $voucherCode->balance,
            'requested' => $amount,
            'shortfall' => $allowed ? 0 : $amount - $voucherCode->balance,
        ]);
    }

    /**
     * Debit credits (bet/wager).
     */
    public function debit(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        $voucherCode = $request->voucher_code;
        $session = $request->voucher_session;
        $amount = $request->input('amount');

        if (!$voucherCode->hasSufficientBalance($amount)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient balance.',
                'balance' => $voucherCode->balance,
                'requested' => $amount,
            ], 422);
        }

        try {
            $transaction = $this->voucherCodeService->debit(
                $voucherCode,
                $amount,
                $request->input('reference'),
                $session->terminal,
                $request->input('metadata')
            );

            return response()->json([
                'success' => true,
                'transaction_id' => $transaction->uuid,
                'amount' => abs($transaction->amount),
                'balance_before' => $transaction->balance_before,
                'balance_after' => $transaction->balance_after,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Credit winnings.
     */
    public function credit(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        $voucherCode = $request->voucher_code;
        $session = $request->voucher_session;

        try {
            $transaction = $this->voucherCodeService->credit(
                $voucherCode,
                $request->input('amount'),
                $request->input('reference'),
                $session->terminal,
                $request->input('metadata')
            );

            return response()->json([
                'success' => true,
                'transaction_id' => $transaction->uuid,
                'amount' => $transaction->amount,
                'balance_before' => $transaction->balance_before,
                'balance_after' => $transaction->balance_after,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Generic transaction (for games that need to do bet + win in one call).
     */
    public function transaction(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['required', 'in:debit,credit'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        if ($request->input('type') === 'debit') {
            return $this->debit($request);
        }

        return $this->credit($request);
    }

    /**
     * Get recent transactions for the session.
     */
    public function transactions(Request $request): JsonResponse
    {
        $session = $request->voucher_session;

        $transactions = $session->transactions()
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($tx) => [
                'uuid' => $tx->uuid,
                'type' => $tx->type,
                'amount' => $tx->amount,
                'balance_after' => $tx->balance_after,
                'reference' => $tx->reference,
                'created_at' => $tx->created_at->toIso8601String(),
            ]);

        return response()->json([
            'transactions' => $transactions,
        ]);
    }
}
