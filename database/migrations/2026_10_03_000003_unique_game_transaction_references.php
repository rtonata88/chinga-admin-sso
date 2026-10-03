<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One game transaction per (wallet, game session, reference, type), and the voucher
 * equivalent (Kulipi Kuna hardening H1). Existing duplicates are never deleted or merged
 * here (H2): the migration stops and lists them for a person to reconcile.
 */
return new class extends Migration
{
    private const WALLET_KEY = ['wallet_id', 'game_session_id', 'reference', 'type'];

    /** Matches VoucherCodeService::findGameTransaction() exactly. */
    private const VOUCHER_KEY = ['voucher_code_id', 'game_session_id', 'reference', 'type'];

    public function up(): void
    {
        // Both tables are checked before either is altered, so a refusal leaves the schema untouched.
        self::refuseDuplicates('wallet_transactions', self::WALLET_KEY);
        self::refuseDuplicates('voucher_transactions', self::VOUCHER_KEY);

        // DDL is not transactional in MySQL/MariaDB: skip an index a failed earlier run already added.
        if (! Schema::hasIndex('wallet_transactions', 'wallet_tx_game_reference_unique')) {
            Schema::table('wallet_transactions', function (Blueprint $t) {
                $t->unique(self::WALLET_KEY, 'wallet_tx_game_reference_unique');
            });
        }
        if (! Schema::hasIndex('voucher_transactions', 'voucher_tx_game_reference_unique')) {
            Schema::table('voucher_transactions', function (Blueprint $t) {
                $t->unique(self::VOUCHER_KEY, 'voucher_tx_game_reference_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('voucher_transactions', fn (Blueprint $t) => $t->dropUnique('voucher_tx_game_reference_unique'));
        Schema::table('wallet_transactions', fn (Blueprint $t) => $t->dropUnique('wallet_tx_game_reference_unique'));
    }

    /**
     * Groups of rows the unique index would refuse. A NULL in any key column never collides
     * in a MySQL unique index (deposits, top-ups and other non-game rows have no session), so
     * those rows are not duplicates and are left out.
     *
     * @param  list<string>  $key
     * @return list<object>
     */
    public static function duplicateGroups(string $table, array $key, int $limit = 20): array
    {
        $cols = implode(', ', $key);
        $notNull = implode(' AND ', array_map(fn ($c) => "{$c} IS NOT NULL", $key));

        return DB::select(
            "SELECT {$cols}, COUNT(*) AS n, GROUP_CONCAT(id ORDER BY id) AS ids
               FROM {$table}
              WHERE {$notNull}
              GROUP BY {$cols}
             HAVING COUNT(*) > 1
              LIMIT {$limit}"
        );
    }

    /** @param list<string> $key */
    private static function refuseDuplicates(string $table, array $key): void
    {
        $groups = self::duplicateGroups($table, $key);
        if ($groups === []) {
            return;
        }
        $cols = implode(', ', $key);
        $notNull = implode(' AND ', array_map(fn ($c) => "{$c} IS NOT NULL", $key));
        $lines = array_map(fn ($g) => json_encode($g), $groups);

        throw new RuntimeException(
            "{$table} has duplicate game transactions; reconcile them by hand, then migrate again. Nothing was changed.\n"
            .implode("\n", $lines)
            ."\nFind them all: SELECT {$cols}, COUNT(*) FROM {$table} WHERE {$notNull} GROUP BY {$cols} HAVING COUNT(*) > 1;"
        );
    }
};
