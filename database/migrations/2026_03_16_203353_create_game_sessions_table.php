<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create game_sessions table
        Schema::create('game_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('session_token')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->foreignId('terminal_id')->nullable()->constrained('venue_terminals')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->decimal('balance_start', 15, 2);
            $table->decimal('balance_end', 15, 2)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->enum('end_reason', ['logout', 'timeout', 'cashed_out', 'forced'])->nullable();
            $table->timestamps();
            $table->index(['source_type', 'source_id']);
            $table->index(['tenant_id', 'game_id']);
        });

        // Add FK from wallet_transactions to game_sessions
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->foreign('game_session_id')->references('id')->on('game_sessions')->nullOnDelete();
        });

        // Drop FKs that reference voucher_sessions before dropping the table
        $this->dropForeignIfExists('voucher_codes', 'voucher_codes_current_session_id_foreign');
        $this->dropForeignIfExists('voucher_transactions', 'voucher_transactions_session_id_foreign');

        // Drop voucher_sessions table
        Schema::dropIfExists('voucher_sessions');

        // Re-add FK from voucher_codes to game_sessions
        Schema::table('voucher_codes', function (Blueprint $table) {
            $table->foreign('current_session_id')->references('id')->on('game_sessions')->nullOnDelete();
        });

        // Rename session_id to game_session_id on voucher_transactions
        Schema::table('voucher_transactions', function (Blueprint $table) {
            $table->renameColumn('session_id', 'game_session_id');
        });

        Schema::table('voucher_transactions', function (Blueprint $table) {
            $table->foreign('game_session_id')->references('id')->on('game_sessions')->nullOnDelete();
        });

        // Rename 'loss' to 'bet' in voucher_transactions type enum
        DB::statement("UPDATE voucher_transactions SET type = 'bet' WHERE type = 'loss'");
        DB::statement("ALTER TABLE voucher_transactions MODIFY COLUMN type ENUM('load', 'win', 'bet', 'cashout', 'adjustment', 'transfer_in', 'transfer_out')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert voucher_transactions type enum
        DB::statement("UPDATE voucher_transactions SET type = 'loss' WHERE type = 'bet'");
        DB::statement("ALTER TABLE voucher_transactions MODIFY COLUMN type ENUM('load', 'win', 'loss', 'cashout', 'adjustment', 'transfer_in', 'transfer_out')");

        // Rename game_session_id back to session_id on voucher_transactions
        Schema::table('voucher_transactions', function (Blueprint $table) {
            $table->dropForeign(['game_session_id']);
            $table->renameColumn('game_session_id', 'session_id');
        });

        // Drop FK from voucher_codes to game_sessions
        Schema::table('voucher_codes', function (Blueprint $table) {
            $table->dropForeign(['current_session_id']);
        });

        // Recreate voucher_sessions table
        Schema::create('voucher_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('voucher_code_id')->constrained()->onDelete('cascade');
            $table->foreignId('terminal_id')->nullable()->constrained('venue_terminals')->onDelete('set null');
            $table->unsignedBigInteger('game_client_id')->nullable();
            $table->string('session_token')->unique();
            $table->string('ip_address', 45)->nullable();
            $table->decimal('balance_start', 15, 2);
            $table->decimal('balance_end', 15, 2)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->enum('end_reason', ['logout', 'timeout', 'cashed_out', 'forced'])->nullable();
            $table->timestamps();
            $table->index('session_token');
            $table->index(['voucher_code_id', 'ended_at']);
        });

        // Re-add FK from voucher_codes to voucher_sessions
        Schema::table('voucher_codes', function (Blueprint $table) {
            $table->foreign('current_session_id')->references('id')->on('voucher_sessions')->onDelete('set null');
        });

        // Re-add FK from voucher_transactions session_id to voucher_sessions
        Schema::table('voucher_transactions', function (Blueprint $table) {
            $table->foreign('session_id')->references('id')->on('voucher_sessions')->onDelete('set null');
        });

        // Drop FK from wallet_transactions to game_sessions
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropForeign(['game_session_id']);
        });

        Schema::dropIfExists('game_sessions');
    }

    /**
     * Drop a foreign key constraint if it exists.
     */
    private function dropForeignIfExists(string $table, string $foreignKey): void
    {
        $exists = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND TABLE_SCHEMA = DATABASE()",
            [$table, $foreignKey]
        );

        if (!empty($exists)) {
            Schema::table($table, function (Blueprint $table) use ($foreignKey) {
                $table->dropForeign($foreignKey);
            });
        }
    }
};
