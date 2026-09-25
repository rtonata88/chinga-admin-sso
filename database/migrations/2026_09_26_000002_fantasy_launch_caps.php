<?php

use App\Models\Game;
use App\Support\FantasySettingsSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Chinga Fantasy launch caps: a new operator's first weeks are protected
 * by the caps, not the edge. Max bet 20, max win 2,000 per ticket, 5,000
 * stake per round, and a new ceiling on the round's total liability
 * (20,000). Applied where the previous defaults are still in force; a
 * game tuned by hand keeps its values, and per-operator overrides raise
 * the caps once their turnover is known.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fantasy = Game::query()->where('slug', 'chinga-fantasy')->first();
        if (! $fantasy) {
            return;
        }
        $s = $fantasy->settings ?? [];
        if ((float) ($s['max_bet_amount'] ?? 50) === 50.0) {
            $s['max_bet_amount'] = 20;
        }
        if ((float) ($s['max_win_per_bet'] ?? 16000) === 16000.0) {
            $s['max_win_per_bet'] = 2000;
        }
        if ((float) ($s['max_total_stake_per_round'] ?? 25000) === 25000.0) {
            $s['max_total_stake_per_round'] = 5000;
        }
        $s['max_total_liability_per_round'] = $s['max_total_liability_per_round'] ?? 20000;
        $fantasy->forceFill([
            'settings_schema' => FantasySettingsSchema::definition(),
            'settings' => $s,
        ])->save();
    }

    public function down(): void
    {
        // Forward only: the previous caps are a business decision, not a schema.
    }
};
