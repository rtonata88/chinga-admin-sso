<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The original migration declared status as enum('pending','confirmed','paid')
 * but CalculateRevenueCommand writes 'calculated' (the record has been
 * computed but not yet confirmed for payout). MySQL truncates on enum
 * mismatch — extend the enum.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE tenant_revenue_records
             MODIFY status ENUM('pending','calculated','confirmed','paid') NOT NULL DEFAULT 'pending'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE tenant_revenue_records
             MODIFY status ENUM('pending','confirmed','paid') NOT NULL DEFAULT 'pending'"
        );
    }
};
