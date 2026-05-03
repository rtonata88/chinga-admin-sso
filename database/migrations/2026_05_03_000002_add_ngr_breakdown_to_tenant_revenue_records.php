<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot the NGR breakdown so historical reports stay faithful even when
 * tenants change tax_pct / business_model later.
 *
 * Stack:
 *   GGR = total_bets - total_wins
 *   tax_amount        = GGR * tax_pct
 *   net_gaming_revenue (NGR) = GGR - tax_amount
 *   tenant_share      = reseller ? NGR * revenue_share_pct : 0
 *   chinga_share      = NGR - tenant_share
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_revenue_records', function (Blueprint $table) {
            $table->enum('business_model', ['reseller', 'direct'])
                ->default('reseller')
                ->after('revenue_share_pct');
            $table->decimal('tax_pct', 5, 2)->default(0)->after('business_model');
            $table->decimal('tax_amount', 15, 2)->default(0)->after('tax_pct');
            $table->decimal('net_gaming_revenue', 15, 2)->default(0)->after('tax_amount');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_revenue_records', function (Blueprint $table) {
            $table->dropColumn(['business_model', 'tax_pct', 'tax_amount', 'net_gaming_revenue']);
        });
    }
};
