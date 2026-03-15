<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_revenue_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('period_type', ['daily', 'weekly', 'monthly']);
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('total_bets', 15, 2)->default(0);
            $table->decimal('total_wins', 15, 2)->default(0);
            $table->decimal('gross_gaming_revenue', 15, 2)->default(0);
            $table->decimal('revenue_share_pct', 5, 2)->default(0);
            $table->decimal('chinga_share', 15, 2)->default(0);
            $table->decimal('tenant_share', 15, 2)->default(0);
            $table->enum('status', ['pending', 'confirmed', 'paid'])->default('pending');
            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'game_id', 'period_type', 'period_start'], 'tenant_revenue_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_revenue_records');
    }
};
