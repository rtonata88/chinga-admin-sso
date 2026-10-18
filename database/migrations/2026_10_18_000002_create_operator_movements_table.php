<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seamless wallet mode (docs/platform/seamless-wallet-spec.md).
 *
 * - wallets.mode: a seamless player's wallet is a mirror of the operator's balance,
 *   not money we hold. Reconciliation and the Treasury page leave it out; its
 *   reconciliation is the daily statement match with the operator.
 * - operator_movements: every stake, win and rollback sent to an operator, written
 *   BEFORE the call and settled after it, so a crash or a timeout leaves a row that
 *   says exactly what is still open. Unique per (operator, kind, reference): our
 *   reference is the operator's idempotency key, and a repeat never moves money twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->enum('mode', ['hosted', 'seamless'])->default('hosted')->after('currency');
        });

        Schema::create('operator_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_session_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('kind', ['debit', 'credit', 'rollback']);
            $table->string('reference', 100);
            $table->string('debit_reference', 100)->nullable();
            $table->decimal('amount', 15, 2);
            $table->char('currency', 3);
            // pending: sent or about to be; ok / failed: the operator's final answer;
            // retrying: no clear answer yet, operators:retry tries again at next_attempt_at;
            // abandoned: retries ran out (credits after 24 h), raised for the teams.
            $table->enum('status', ['pending', 'ok', 'failed', 'retrying', 'abandoned'])->default('pending');
            $table->string('error_code', 40)->nullable();
            $table->string('operator_transaction_id', 100)->nullable();
            $table->decimal('balance_after', 15, 2)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['operator_integration_id', 'kind', 'reference']);
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_movements');
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
