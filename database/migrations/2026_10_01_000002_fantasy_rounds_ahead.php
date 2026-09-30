<?php

use App\Models\Game;
use App\Support\FantasySettingsSchema;
use Illuminate\Database\Migrations\Migration;

/** Rounds dealt ahead: refresh the stored Fantasy schema so the form offers rounds_ahead (default 1). */
return new class extends Migration
{
    public function up(): void
    {
        Game::query()->where('slug', 'chinga-fantasy')->each(fn (Game $g) => $g->forceFill(['settings_schema' => FantasySettingsSchema::definition()])->save());
    }

    public function down(): void {}
};
