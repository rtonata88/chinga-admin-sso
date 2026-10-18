<?php

use App\Models\Game;
use App\Support\KulipiKunaSettingsSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Separate time to cash out or continue: refresh the stored Kulipi Kuna schema so the settings
 * form offers decision_seconds (default 30) and calls level_decision_seconds the betting window.
 * Saved values are untouched; a tenant without decision_seconds gets the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Game::query()->where('slug', 'kulipi-kuna')->each(fn (Game $g) => $g->forceFill(['settings_schema' => KulipiKunaSettingsSchema::definition()])->save());
    }

    public function down(): void {}
};
