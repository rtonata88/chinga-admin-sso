<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Venue play: a voucher code of kind "counter" is a venue's float. The
 * counter logs the game in with it, sells tickets over the counter against
 * its balance, and pays winning slips out in cash. Player codes are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voucher_codes', function (Blueprint $table) {
            $table->string('kind', 20)->default('player')->after('status');
            $table->index(['venue_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('voucher_codes', function (Blueprint $table) {
            $table->dropIndex(['venue_id', 'kind']);
            $table->dropColumn('kind');
        });
    }
};
