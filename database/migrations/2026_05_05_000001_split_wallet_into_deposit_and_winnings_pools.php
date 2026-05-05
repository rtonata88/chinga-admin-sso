<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two-pool wallet model:
 *   - deposit_balance:  funds the player put in (vouchers, deposits) — playable
 *                        but cannot be withdrawn. Drained first when bets are
 *                        placed.
 *   - winnings_balance: funds the player won through play — withdrawable.
 *                        Bets only draw from here once deposit_balance is
 *                        empty. Withdrawals always come from this pool.
 *
 * `balance` is preserved as a stored sum (deposit + winnings) so existing
 * reads (chinga-fantasy /api/balance, etc.) keep working unchanged. The
 * service layer keeps all three columns in lock-step.
 *
 * On wallet_transactions we add amount_from_deposit + amount_from_winnings
 * to record exactly how each pool moved per transaction. Sum of the two
 * always equals `amount` for the recorded direction.
 *
 * Migration #2 amnesty: every existing balance is treated as winnings on
 * day one. Players can cash out everything they had immediately, but from
 * here on the rule applies.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->decimal('deposit_balance', 15, 2)->default(0)->after('balance');
            $table->decimal('winnings_balance', 15, 2)->default(0)->after('deposit_balance');
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->decimal('amount_from_deposit', 15, 2)->default(0)->after('amount');
            $table->decimal('amount_from_winnings', 15, 2)->default(0)->after('amount_from_deposit');
        });

        // Amnesty: existing balances become withdrawable winnings. Players
        // who already had balance can cash out once; new flows from here on
        // follow the deposit/winnings split.
        DB::statement('UPDATE wallets SET winnings_balance = balance, deposit_balance = 0');
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropColumn(['amount_from_deposit', 'amount_from_winnings']);
        });
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn(['deposit_balance', 'winnings_balance']);
        });
    }
};
