<?php

use App\Models\Game;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform seams for a multi-game catalogue (PRD §4, P1–P3):
 *
 *   backend_url      per-game admin API base URL, replaces the single
 *                    CHINGA_FANTASY_API_URL env var
 *   launch_url       player-facing URL for lobbies and "open game"
 *   settings_schema  JSON Schema the admin settings form is rendered from
 *
 * The Chinga Fantasy row is backfilled from the legacy config key so the
 * running deployment keeps its backend URL without a manual step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('backend_url', 500)->nullable()->after('thumbnail_url');
            $table->string('launch_url', 500)->nullable()->after('backend_url');
            $table->json('settings_schema')->nullable()->after('settings');
        });

        $legacy = config('services.chinga_fantasy.api_url');
        if (is_string($legacy) && $legacy !== '') {
            Game::query()
                ->where('slug', 'chinga-fantasy')
                ->whereNull('backend_url')
                ->update(['backend_url' => rtrim($legacy, '/')]);
        }
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['backend_url', 'launch_url', 'settings_schema']);
        });
    }
};
