<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add per-tenant commercial fields:
 *   - business_model: 'reseller' (gets revenue_share_pct of NGR) | 'direct' (100% to platform)
 *   - tax_pct: jurisdictional gambling tax, deducted from GGR before share split
 *
 * Existing rows default to 'reseller' (we already had revenue_share_pct on these),
 * with tax_pct = 0 until configured per tenant.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->enum('business_model', ['reseller', 'direct'])
                ->default('reseller')
                ->after('revenue_share_pct');
            $table->decimal('tax_pct', 5, 2)->default(0)->after('business_model');
        });

        // Existing tenants are resellers (current behavior); leave default in place.
        DB::table('tenants')->whereNull('business_model')->update(['business_model' => 'reseller']);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['business_model', 'tax_pct']);
        });
    }
};
