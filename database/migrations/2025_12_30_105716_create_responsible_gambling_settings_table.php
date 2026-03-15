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
        Schema::create('responsible_gambling_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Deposit limits
            $table->decimal('daily_deposit_limit', 15, 2)->nullable();
            $table->decimal('weekly_deposit_limit', 15, 2)->nullable();
            $table->decimal('monthly_deposit_limit', 15, 2)->nullable();

            // Pending limit increases (24h cooling-off)
            $table->decimal('pending_daily_limit', 15, 2)->nullable();
            $table->decimal('pending_weekly_limit', 15, 2)->nullable();
            $table->decimal('pending_monthly_limit', 15, 2)->nullable();
            $table->timestamp('pending_limits_effective_at')->nullable();

            // Session limits
            $table->unsignedInteger('session_time_limit_minutes')->nullable();
            $table->decimal('session_loss_limit', 15, 2)->nullable();

            // Reality check
            $table->unsignedInteger('reality_check_interval_minutes')->nullable();

            // Wagering limits
            $table->decimal('daily_wager_limit', 15, 2)->nullable();
            $table->decimal('weekly_wager_limit', 15, 2)->nullable();

            // Login restrictions
            $table->time('login_time_start')->nullable();
            $table->time('login_time_end')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('responsible_gambling_settings');
    }
};
