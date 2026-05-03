<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Player-initiated withdrawal requests.
 *
 * Lifecycle:
 *   requested  → admin reviews
 *   approved   → admin pays out-of-band, then marks paid
 *   paid       → terminal (external_reference recorded)
 *   rejected   → terminal (rejection_reason recorded, wallet refunded)
 *   cancelled  → terminal (player cancelled before approval, wallet refunded)
 *
 * The wallet is debited at request time (hold_transaction_id), and refunded
 * automatically on reject/cancel (refund_transaction_id). Once paid, the
 * debit stands and the player has received funds out-of-band.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Subject
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();

            // Wallet integration — IDs of the held debit (and refund, if any).
            $table->unsignedBigInteger('hold_transaction_id')->nullable();
            $table->unsignedBigInteger('refund_transaction_id')->nullable();

            // Money (snapshotted)
            $table->decimal('amount', 15, 2);
            $table->decimal('fee_amount', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2);
            $table->string('currency', 3);

            // How the player wants to be paid
            $table->enum('payment_method', ['bank_transfer', 'venue_cash', 'mobile_money', 'voucher']);
            $table->json('payment_details')->nullable();

            // Workflow
            $table->enum('status', ['requested', 'approved', 'paid', 'rejected', 'cancelled'])
                ->default('requested');
            $table->string('external_reference')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->foreign('hold_transaction_id')->references('id')->on('wallet_transactions')->nullOnDelete();
            $table->foreign('refund_transaction_id')->references('id')->on('wallet_transactions')->nullOnDelete();

            $table->index(['tenant_id', 'status']);
            $table->index(['user_id', 'created_at']);
            $table->index('status');
            $table->index('wallet_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_requests');
    }
};
