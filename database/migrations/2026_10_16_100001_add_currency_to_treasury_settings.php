<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row of treasury inputs PER CURRENCY. Tenants now hold money in
 * different currencies (NAD, BWP, ...), each backed by its own bank account
 * and taxed by its own jurisdiction, so a bank balance in one currency is
 * never set against wallets in another. The existing row was entered for the
 * Namibian account, so it becomes the NAD row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treasury_settings', function (Blueprint $table) {
            $table->char('currency', 3)->default('NAD')->after('id');
        });
        Schema::table('treasury_settings', function (Blueprint $table) {
            $table->unique('currency');
        });
    }

    public function down(): void
    {
        Schema::table('treasury_settings', function (Blueprint $table) {
            $table->dropUnique(['currency']);
            $table->dropColumn('currency');
        });
    }
};
