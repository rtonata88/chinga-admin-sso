<?php

use App\Models\Game;
use App\Support\FantasySettingsSchema;
use Illuminate\Database\Migrations\Migration;

/** Refresh the stored Fantasy settings schema so the admin form shows the ticket wording. */
return new class extends Migration
{
    public function up(): void
    {
        Game::query()->where('slug', 'chinga-fantasy')->each(fn (Game $g) => $g->forceFill(['settings_schema' => FantasySettingsSchema::definition()])->save());
    }

    public function down(): void {}
};
