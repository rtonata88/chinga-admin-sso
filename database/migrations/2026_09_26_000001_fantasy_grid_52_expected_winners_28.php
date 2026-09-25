<?php

use App\Models\Game;
use App\Support\FantasySettingsSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Chinga Fantasy: the grid becomes 52 teams dealt 12/10/9/7/6/5/3 across
 * the tiers, which makes the average number of winners per round 28 at
 * the default house edge (the results-phase design brief). Applied only
 * where the previous defaults (50, equal tiers) are still in force; a
 * game already tuned by hand keeps its values. The stored schema is
 * refreshed so the admin form shows the new defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fantasy = Game::query()->where('slug', 'chinga-fantasy')->first();
        if (! $fantasy) {
            return;
        }
        $settings = $fantasy->settings ?? [];
        $onOldDefaults = (int) ($settings['grid_size'] ?? 50) === 50 && trim((string) ($settings['tier_weights'] ?? '')) === '';
        if ($onOldDefaults) {
            $settings['grid_size'] = 52;
            $settings['tier_weights'] = '12,10,9,7,6,5,3';
        }
        $fantasy->forceFill([
            'settings_schema' => FantasySettingsSchema::definition(),
            'settings' => $settings,
        ])->save();
    }

    public function down(): void
    {
        $fantasy = Game::query()->where('slug', 'chinga-fantasy')->first();
        if (! $fantasy) {
            return;
        }
        $settings = $fantasy->settings ?? [];
        if ((int) ($settings['grid_size'] ?? 0) === 52 && ($settings['tier_weights'] ?? '') === '12,10,9,7,6,5,3') {
            $settings['grid_size'] = 50;
            $settings['tier_weights'] = '';
        }
        $fantasy->forceFill(['settings' => $settings])->save();
    }
};
