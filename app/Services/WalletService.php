<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class WalletService
{
    /**
     * Create a wallet for a user.
     */
    public function createWallet(User $user, ?string $currency = null): Wallet
    {
        $tenant = app('current_tenant');
        $currency = $currency ?? $tenant?->currency ?? 'NAD';

        return Wallet::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'currency' => $currency,
            'status' => 'active',
        ]);
    }

    /**
     * Deposit funds into a wallet.
     */
    public function deposit(Wallet $wallet, string $amount, ?User $performedBy = null, ?string $reference = null): WalletTransaction
    {
        $this->validateAmount($amount);
        $this->ensureWalletActive($wallet);

        return DB::transaction(function () use ($wallet, $amount, $performedBy, $reference) {
            $wallet = Wallet::lockForUpdate()->find($wallet->id);

            $balanceBefore = $wallet->balance;
            $balanceAfter = bcadd($balanceBefore, $amount, 2);

            $wallet->update([
                'balance' => $balanceAfter,
                'total_deposited' => bcadd($wallet->total_deposited, $amount, 2),
            ]);

            return $this->recordTransaction($wallet, 'deposit', $amount, $balanceBefore, $balanceAfter, $performedBy, $reference, 'Deposit');
        });
    }

    /**
     * Withdraw funds from a wallet.
     */
    public function withdraw(Wallet $wallet, string $amount, ?User $performedBy = null, ?string $reference = null): WalletTransaction
    {
        $this->validateAmount($amount);
        $this->ensureWalletActive($wallet);

        return DB::transaction(function () use ($wallet, $amount, $performedBy, $reference) {
            $wallet = Wallet::lockForUpdate()->find($wallet->id);

            if (!$wallet->hasSufficientBalance($amount)) {
                throw new \RuntimeException('Insufficient balance for withdrawal.');
            }

            $balanceBefore = $wallet->balance;
            $balanceAfter = bcsub($balanceBefore, $amount, 2);

            $wallet->update([
                'balance' => $balanceAfter,
                'total_withdrawn' => bcadd($wallet->total_withdrawn, $amount, 2),
            ]);

            return $this->recordTransaction($wallet, 'withdrawal', $amount, $balanceBefore, $balanceAfter, $performedBy, $reference, 'Withdrawal');
        });
    }

    /**
     * Debit a wallet for a game bet.
     */
    public function debit(Wallet $wallet, string $amount, \App\Models\GameSession $session, ?string $reference = null): WalletTransaction
    {
        $this->validateAmount($amount);
        $this->ensureWalletActive($wallet);

        return DB::transaction(function () use ($wallet, $amount, $session, $reference) {
            // Idempotency check: if reference provided, check for duplicate in same session
            if ($reference) {
                $existing = WalletTransaction::where('wallet_id', $wallet->id)
                    ->where('game_session_id', $session->id)
                    ->where('reference', $reference)
                    ->where('type', 'bet')
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $wallet = Wallet::lockForUpdate()->find($wallet->id);

            if (!$wallet->hasSufficientBalance($amount)) {
                throw new \RuntimeException('Insufficient balance for bet.');
            }

            $balanceBefore = $wallet->balance;
            $balanceAfter = bcsub($balanceBefore, $amount, 2);

            $wallet->update([
                'balance' => $balanceAfter,
                'total_lost' => bcadd($wallet->total_lost, $amount, 2),
            ]);

            return $this->recordTransaction($wallet, 'bet', $amount, $balanceBefore, $balanceAfter, null, $reference, 'Game bet', $session->id);
        });
    }

    /**
     * Credit a wallet for a game win.
     */
    public function credit(Wallet $wallet, string $amount, \App\Models\GameSession $session, ?string $reference = null): WalletTransaction
    {
        $this->validateAmount($amount);
        $this->ensureWalletActive($wallet);

        return DB::transaction(function () use ($wallet, $amount, $session, $reference) {
            // Idempotency check: if reference provided, check for duplicate in same session
            if ($reference) {
                $existing = WalletTransaction::where('wallet_id', $wallet->id)
                    ->where('game_session_id', $session->id)
                    ->where('reference', $reference)
                    ->where('type', 'win')
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $wallet = Wallet::lockForUpdate()->find($wallet->id);

            $balanceBefore = $wallet->balance;
            $balanceAfter = bcadd($balanceBefore, $amount, 2);

            $wallet->update([
                'balance' => $balanceAfter,
                'total_won' => bcadd($wallet->total_won, $amount, 2),
            ]);

            return $this->recordTransaction($wallet, 'win', $amount, $balanceBefore, $balanceAfter, null, $reference, 'Game winnings', $session->id);
        });
    }

    /**
     * Get the current balance of a wallet as a string.
     */
    public function getBalance(Wallet $wallet): string
    {
        return $wallet->balance;
    }

    /**
     * Refund a previously-held withdrawal: credits the wallet by the held
     * transaction's amount and records the reversal as type='adjustment'.
     * Idempotent by reference.
     *
     * Used when a withdrawal request is rejected or cancelled before payout.
     */
    public function refundWithdrawalHold(
        WalletTransaction $heldTransaction,
        ?\App\Models\User $performedBy = null,
        ?string $reference = null,
        ?string $description = 'Withdrawal refund',
    ): WalletTransaction {
        if ($heldTransaction->type !== 'withdrawal') {
            throw new \InvalidArgumentException('refundWithdrawalHold requires a transaction of type withdrawal.');
        }

        $wallet = $heldTransaction->wallet()->firstOrFail();
        $amount = (string) $heldTransaction->amount;

        return DB::transaction(function () use ($wallet, $heldTransaction, $amount, $performedBy, $reference, $description) {
            // Idempotency: if this refund has already been applied, return it.
            if ($reference) {
                $existing = WalletTransaction::where('wallet_id', $wallet->id)
                    ->where('reference', $reference)
                    ->where('type', 'adjustment')
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $wallet = Wallet::lockForUpdate()->find($wallet->id);

            $balanceBefore = $wallet->balance;
            $balanceAfter = bcadd($balanceBefore, $amount, 2);

            // Reverse the withdrawal accounting too — total_withdrawn was bumped on the hold.
            $wallet->update([
                'balance' => $balanceAfter,
                'total_withdrawn' => bcsub($wallet->total_withdrawn, $amount, 2),
            ]);

            return $this->recordTransaction(
                $wallet,
                'adjustment',
                $amount,
                $balanceBefore,
                $balanceAfter,
                $performedBy,
                $reference,
                $description
            );
        });
    }

    /**
     * Record a wallet transaction.
     */
    private function recordTransaction(
        Wallet $wallet,
        string $type,
        string $amount,
        string $balanceBefore,
        string $balanceAfter,
        ?User $performedBy = null,
        ?string $reference = null,
        ?string $description = null,
        ?int $gameSessionId = null,
    ): WalletTransaction {
        return WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'game_session_id' => $gameSessionId,
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'reference' => $reference,
            'description' => $description,
            'performed_by' => $performedBy?->id,
        ]);
    }

    /**
     * Validate that the amount is positive.
     */
    private function validateAmount(string $amount): void
    {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero.');
        }
    }

    /**
     * Ensure the wallet is active.
     */
    private function ensureWalletActive(Wallet $wallet): void
    {
        if (!$wallet->isActive()) {
            throw new \RuntimeException('Wallet is not active.');
        }
    }
}
