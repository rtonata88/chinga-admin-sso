<?php

namespace App\Support;

/**
 * Vrrr Pha's settings schema (PRD §6). The engine re-reads the merged
 * settings from GET /api/v1/games/{uuid}/config at the top of every round.
 *
 * Keys that define the published maths (house_edge, max_multiplier,
 * growth_rate_k, tick_interval_ms) are not tenant-overridable: the RTP is
 * published per game (RTS 3), so every tenant must run the same curve.
 * Bet limits, exposure caps and the auto-cashout flag are per tenant.
 */
class VrrrPhaSettingsSchema
{
    public static function definition(): array
    {
        return [
            'type' => 'object',
            'required' => [
                'min_bet_amount', 'max_bet_amount', 'house_edge', 'max_multiplier', 'max_win_per_round',
                'max_total_stake_per_round', 'betting_window_seconds', 'crash_display_seconds',
                'growth_rate_k', 'tick_interval_ms', 'auto_cashout_enabled', 'auto_rebet_enabled',
            ],
            'properties' => [
                'min_bet_amount' => ['type' => 'number', 'title' => 'Min bet (NAD)', 'description' => 'Server-enforced before the wallet debit', 'minimum' => 1, 'default' => 5, 'x-group' => 'game', 'x-format' => 'currency'],
                'max_bet_amount' => ['type' => 'number', 'title' => 'Max bet (NAD)', 'minimum' => 1, 'default' => 50, 'x-group' => 'game', 'x-format' => 'currency'],
                'house_edge' => ['type' => 'number', 'title' => 'House edge', 'description' => 'Fraction. 0.04 = 96% nominal RTP, published per RTS 3. Same for every tenant.', 'minimum' => 0, 'maximum' => 0.5, 'multipleOf' => 0.0001, 'default' => 0.04, 'x-group' => 'game', 'x-tenant-overridable' => false],
                'max_multiplier' => ['type' => 'integer', 'title' => 'Max multiplier', 'description' => 'The speedometer\'s top speed and the dial ceiling (PRD §5.4). Crash points are capped here; the return for every reachable cash-out target is unchanged.', 'minimum' => 2, 'maximum' => 1000000, 'default' => 320, 'x-group' => 'game', 'x-tenant-overridable' => false],
                'max_win_per_round' => ['type' => 'number', 'title' => 'Max win per round (NAD)', 'description' => 'Absolute ceiling per bet', 'minimum' => 0, 'default' => 50000, 'x-group' => 'game', 'x-format' => 'currency'],
                'max_total_stake_per_round' => ['type' => 'number', 'title' => 'Max total stake per round (NAD)', 'description' => 'Per-tenant exposure control; bets are refused once reached', 'minimum' => 0, 'default' => 25000, 'x-group' => 'game', 'x-format' => 'currency'],
                'betting_window_seconds' => ['type' => 'integer', 'title' => 'Betting window (seconds)', 'description' => 'Hard floor of 5 s is enforced in the engine (RTS 14G)', 'minimum' => 5, 'maximum' => 120, 'default' => 8, 'x-group' => 'game'],
                'crash_display_seconds' => ['type' => 'integer', 'title' => 'Crash display (seconds)', 'minimum' => 1, 'maximum' => 30, 'default' => 3, 'x-group' => 'game'],
                'growth_rate_k' => ['type' => 'number', 'title' => 'Growth rate k', 'description' => 'multiplier(t) = e^(k·t). 0.154 puts 2.00x at about 4.5 s.', 'minimum' => 0.01, 'maximum' => 2, 'multipleOf' => 0.001, 'default' => 0.154, 'x-group' => 'game', 'x-tenant-overridable' => false],
                'tick_interval_ms' => ['type' => 'integer', 'title' => 'Tick interval (ms)', 'description' => 'Broadcast cadence', 'minimum' => 50, 'maximum' => 1000, 'default' => 100, 'x-group' => 'game', 'x-tenant-overridable' => false],
                'auto_cashout_enabled' => ['type' => 'boolean', 'title' => 'Auto-cashout', 'description' => 'Feature flag pending test-house classification (PRD §5.6). Targets are per round only, never carried over.', 'default' => true, 'x-group' => 'game'],
                'auto_rebet_enabled' => ['type' => 'boolean', 'title' => 'Auto-rebet', 'description' => 'Feature flag (PRD §5.9). RTS 8 reads as prohibiting this: keep it off wherever RTS 8 applies. When on, the player must opt in per session, every auto-placed bet is recorded as such, and it disarms on disconnect, reality check and after the round cap.', 'default' => false, 'x-group' => 'game'],
                'auto_rebet_max_rounds' => ['type' => 'integer', 'title' => 'Auto-rebet max rounds', 'description' => 'Consecutive auto-placed rounds before a fresh tap is required (PRD §5.9)', 'minimum' => 1, 'maximum' => 100, 'default' => 10, 'x-group' => 'game'],
                'geo_allowed_countries' => ['type' => 'array', 'items' => ['type' => 'string'], 'title' => 'Allowed countries', 'description' => 'ISO 3166-1 alpha-2 codes; empty means blocked everywhere', 'default' => ['NA'], 'x-group' => 'game'],
            ],
        ];
    }
}
