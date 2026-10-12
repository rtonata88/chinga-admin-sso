<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A game can be sold under a different name in each country ("Kulipi Kuna" in
 * Namibia, something else in Botswana). Null means the catalogue name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_games', function (Blueprint $table) {
            $table->string('display_name', 60)->nullable()->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_games', function (Blueprint $table) {
            $table->dropColumn('display_name');
        });
    }
};
