<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_reconciliation_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->decimal('recorded_balance', 15, 2);
            $table->decimal('expected_from_sum', 15, 2);
            $table->decimal('expected_from_last_tx', 15, 2)->nullable();
            $table->decimal('drift_from_sum', 15, 2);
            $table->decimal('drift_from_last_tx', 15, 2)->nullable();
            $table->enum('status', ['open', 'acknowledged', 'resolved'])->default('open');
            $table->text('notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['wallet_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_reconciliation_alerts');
    }
};
