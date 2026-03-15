<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('voucher_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('voucher_code_id')->constrained()->onDelete('cascade');
            $table->foreignId('session_id')
                ->nullable()
                ->constrained('voucher_sessions')
                ->onDelete('set null');
            $table->enum('type', [
                'load',
                'win',
                'loss',
                'cashout',
                'adjustment',
                'transfer_in',
                'transfer_out'
            ]);
            $table->decimal('amount', 15, 2); // Positive for credits, negative for debits
            $table->decimal('balance_before', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->string('reference', 100)->nullable(); // External ref like game round ID
            $table->text('description')->nullable();
            $table->foreignId('performed_by_staff_id')
                ->nullable()
                ->constrained('venue_staff')
                ->onDelete('set null');
            $table->foreignId('terminal_id')
                ->nullable()
                ->constrained('venue_terminals')
                ->onDelete('set null');
            $table->json('metadata')->nullable(); // Game-specific data
            $table->timestamps();

            $table->index(['voucher_code_id', 'created_at']);
            $table->index('type');
            $table->index('reference');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voucher_transactions');
    }
};
