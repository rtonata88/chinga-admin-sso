<?php

use App\Models\Game;
use App\Support\FantasySettingsSchema;
use Illuminate\Database\Migrations\Migration;

/** Fixed grid layout: refresh the stored Fantasy schema so the form offers grid_layout (default fixed). */
return new class extends Migration
{
    public function up(): void
    {
        Game::query()->where('slug', 'chinga-fantasy')->each(fn (Game $g) => $g->forceFill(['settings_schema' => FantasySettingsSchema::definition()])->save());
    }

    public function down(): void {}
};
