<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill for the voucher double-deposit bug.
 *
 * VoucherCodeService used to (a) create the voucher with balance=$X and
 * (b) immediately deposit $X into the auto-created voucher user's wallet.
 * On web redeem the controller then transferred voucher.balance to the
 * wallet AGAIN, leaving wallets with 2x the real amount.
 *
 * Code fix: remove (b) so the voucher holds the funds until redeem.
 *
 * This migration undoes (b) for already-created vouchers:
 *   - Subtract the amount of every wallet_transaction whose reference starts
 *     with 'voucher_initial_' from the wallet's balance and total_deposited
 *   - Delete those wallet_transaction rows
 *
 * For vouchers that have NOT been redeemed, the wallet ends up at 0 (correct
 * — the voucher still holds the money). For vouchers that HAVE been redeemed
 * and the player has played some of it, the wallet ends up at the real
 * remaining balance.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function () {
            // Subtract the bad amounts from each wallet
            DB::statement('
                UPDATE wallets w
                INNER JOIN (
                    SELECT wallet_id, SUM(amount) AS total_amount
                    FROM wallet_transactions
                    WHERE reference LIKE \'voucher_initial_%\'
                    GROUP BY wallet_id
                ) t ON t.wallet_id = w.id
                SET
                    w.balance = GREATEST(0, w.balance - t.total_amount),
                    w.total_deposited = GREATEST(0, w.total_deposited - t.total_amount)
            ');

            // Delete the bad transaction rows
            DB::table('wallet_transactions')
                ->where('reference', 'like', 'voucher_initial_%')
                ->delete();
        });
    }

    public function down(): void
    {
        // No reverse — the bad rows are gone for good. Re-running them
        // would put us back into the broken state.
    }
};
