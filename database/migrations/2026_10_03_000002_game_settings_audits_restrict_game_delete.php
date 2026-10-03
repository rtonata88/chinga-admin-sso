<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An audit trail must not vanish with its game (K4 final review, finding 7):
     * deleting a game that has settings audits is now refused instead of
     * cascading the audit rows away.
     */
    public function up(): void
    {
        Schema::table('game_settings_audits', function (Blueprint $table) {
            $table->dropForeign(['game_id']);
            $table->foreign('game_id')->references('id')->on('games')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('game_settings_audits', function (Blueprint $table) {
            $table->dropForeign(['game_id']);
            $table->foreign('game_id')->references('id')->on('games')->cascadeOnDelete();
        });
    }
};
