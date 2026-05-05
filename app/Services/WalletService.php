<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Two-pool wallet bookkeeping.
 *
 *   deposit_balance:  funds the player put in (vouchers, deposits) — playable
 *                     but cannot be withdrawn. Drained first when bets land.
 *   winnings_balance: funds won through play — withdrawable. Bets only draw
 *                     here once deposit_balance is empty. Withdrawals always
 *                     come from this pool.
 *   balance: deposit_balance + winnings_balance (kept in sync; existing reads
 *            keep working).
 *
 * Each wallet_transaction records amount_from_deposit + amount_from_winnings
 * so refunds know which pool to restore to.
 */
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
     * Deposit funds into a wallet. Goes to the deposit pool — the player
     * has to play these through and win before they're withdrawable.
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
                'deposit_balance' => bcadd($wallet->deposit_balance ?? '0', $amount, 2),
                'total_deposited' => bcadd($wallet->total_deposited, $amount, 2),
            ]);

            return $this->recordTransaction(
                $wallet,
                'deposit',
                $amount,
                $balanceBefore,
                $balanceAfter,
                $performedBy,
                $reference,
                'Deposit',
                null,
                $amount, // amount_from_deposit
                '0'      // amount_from_winnings
            );
        });
    }

    /**
     * Withdraw funds from a wallet. Only winnings are withdrawable — this
     * checks the winnings pool, not the total balance, and drains there.
     *
     * Used by WithdrawalService.request() to "hold" funds at the start of a
     * withdrawal request. The held amount returns to the winnings pool via
     * refundWithdrawalHold() if the request is cancelled or rejected.
     */
    public function withdraw(Wallet $wallet, string $amount, ?User $performedBy = null, ?string $reference = null): WalletTransaction
    {
        $this->validateAmount($amount);
        $this->ensureWalletActive($wallet);

        return DB::transaction(function () use ($wallet, $amount, $performedBy, $reference) {
            $wallet = Wallet::lockForUpdate()->find($wallet->id);

            if (!$wallet->hasSufficientWithdrawableBalance($amount)) {
                throw new \RuntimeException('Insufficient withdrawable balance — only winnings can be withdrawn.');
            }

            $balanceBefore = $wallet->balance;
            $balanceAfter = bcsub($balanceBefore, $amount, 2);

            $wallet->update([
                'balance' => $balanceAfter,
                'winnings_balance' => bcsub($wallet->winnings_balance ?? '0', $amount, 2),
                'total_withdrawn' => bcadd($wallet->total_withdrawn, $amount, 2),
            ]);

            return $this->recordTransaction(
                $wallet,
                'withdrawal',
                $amount,
                $balanceBefore,
                $balanceAfter,
                $performedBy,
                $reference,
                'Withdrawal',
                null,
                '0',     // amount_from_deposit
                $amount  // amount_from_winnings
            );
        });
    }

    /**
     * Debit a wallet for a game bet. Drains the deposit pool first; if the
     * bet exceeds deposits, the overflow comes from winnings. The split is
     * recorded on the transaction so a future refund can restore each pool
     * accurately.
     */
    public function debit(Wallet $wallet, string $amount, \App\Models\GameSession $session, ?string $reference = null): WalletTransaction
    {
        $this->validateAmount($amount);
        $this->ensureWalletActive($wallet);

        return DB::transaction(function () use ($wallet, $amount, $session, $reference) {
            // Idempotency: don't double-debit if the same reference comes through twice.
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

            // Use the pool sum as the source of truth (rather than the stored
            // `balance` column) so a column-drift bug can never let a bet
            // underflow the pools. Pools and balance are kept in lock-step by
            // this service, but the pools are what actually fund the bet.
            $depositPool = (string) ($wallet->deposit_balance ?? '0');
            $winningsPool = (string) ($wallet->winnings_balance ?? '0');
            $totalAvailable = bcadd($depositPool, $winningsPool, 2);

            if (bccomp($totalAvailable, $amount, 2) < 0) {
                throw new \RuntimeException('Insufficient balance for bet.');
            }

            // Drain deposit first, overflow from winnings.
            $fromDeposit = bccomp($depositPool, $amount, 2) >= 0
                ? $amount
                : $depositPool;
            $fromWinnings = bcsub($amount, $fromDeposit, 2);

            $balanceBefore = $wallet->balance;
            $balanceAfter = bcsub($balanceBefore, $amount, 2);

            $wallet->update([
                'balance' => $balanceAfter,
                'deposit_balance' => bcsub($depositPool, $fromDeposit, 2),
                'winnings_balance' => bcsub($wallet->winnings_balance ?? '0', $fromWinnings, 2),
                'total_lost' => bcadd($wallet->total_lost, $amount, 2),
            ]);

            return $this->recordTransaction(
                $wallet,
                'bet',
                $amount,
                $balanceBefore,
                $balanceAfter,
                null,
                $reference,
                'Game bet',
                $session->id,
                $fromDeposit,
                $fromWinnings
            );
        });
    }

    /**
     * Credit a wallet for a game win. Wins go entirely into the winnings
     * pool — that's the only money the player can ever withdraw.
     */
    public function credit(Wallet $wallet, string $amount, \App\Models\GameSession $session, ?string $reference = null): WalletTransaction
    {
        $this->validateAmount($amount);
        $this->ensureWalletActive($wallet);

        return DB::transaction(function () use ($wallet, $amount, $session, $reference) {
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
                'winnings_balance' => bcadd($wallet->winnings_balance ?? '0', $amount, 2),
                'total_won' => bcadd($wallet->total_won, $amount, 2),
            ]);

            return $this->recordTransaction(
                $wallet,
                'win',
                $amount,
                $balanceBefore,
                $balanceAfter,
                null,
                $reference,
                'Game winnings',
                $session->id,
                '0',     // amount_from_deposit
                $amount  // amount_from_winnings
            );
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
     * Restores to the winnings pool (where withdrawals always drain from),
     * so it's safe regardless of whether the player has won/lost since.
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

            // Restore to winnings (where withdrawals come from), and undo the
            // total_withdrawn bump that happened on the original hold.
            $wallet->update([
                'balance' => $balanceAfter,
                'winnings_balance' => bcadd($wallet->winnings_balance ?? '0', $amount, 2),
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
                $description,
                null,
                '0',
                $amount
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
        string $amountFromDeposit = '0',
        string $amountFromWinnings = '0',
    ): WalletTransaction {
        return WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'game_session_id' => $gameSessionId,
            'type' => $type,
            'amount' => $amount,
            'amount_from_deposit' => $amountFromDeposit,
            'amount_from_winnings' => $amountFromWinnings,
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
