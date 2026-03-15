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
        Schema::create('voucher_codes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('venue_id')->constrained()->onDelete('cascade');
            $table->string('code', 20)->unique(); // The actual code players use
            $table->string('pin')->nullable(); // Hashed 4-digit PIN
            $table->decimal('balance', 15, 2)->default(0.00);
            $table->char('currency', 3)->default('NAD');
            $table->enum('status', [
                'created',
                'active',
                'in_use',
                'cashed_out',
                'expired',
                'deactivated'
            ])->default('created');
            $table->foreignId('created_by_staff_id')
                ->constrained('venue_staff')
                ->onDelete('restrict');
            $table->foreignId('current_terminal_id')
                ->nullable()
                ->constrained('venue_terminals')
                ->onDelete('set null');
            $table->unsignedBigInteger('current_session_id')->nullable();
            $table->decimal('total_loaded', 15, 2)->default(0.00);
            $table->decimal('total_won', 15, 2)->default(0.00);
            $table->decimal('total_lost', 15, 2)->default(0.00);
            $table->decimal('total_cashed_out', 15, 2)->default(0.00);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('code');
            $table->index(['venue_id', 'status']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voucher_codes');
    }
};
