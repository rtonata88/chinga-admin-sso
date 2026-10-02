<?php

namespace App\Support;

/**
 * Kulipi Kuna's settings schema (PRD §9, design D3/D4). The engine re-reads
 * the merged settings from GET /api/v1/games/{uuid}/config at the top of
 * every round and snapshots them onto the round.
 *
 * house_edge is the only setting that moves RTP. It is a multiple of 0.005
 * within 0.02–0.10 so every level pays an exact multiple of the stake, and
 * it is not tenant-overridable: the RTP is published per game (RTS 3). The
 * level cap is the published maths too. Money is whole NAD (stakes are
 * whole N$), so every payout is exact to the cent and nothing is rounded.
 */
class KulipiKunaSettingsSchema
{
    public static function definition(): array
    {
        return [
            'type' => 'object',
            'required' => [
                'house_edge', 'hard_level_cap', 'max_win_per_ladder', 'max_total_riding_per_round',
                'min_bet_amount', 'max_bet_amount', 'level_decision_seconds', 'reveal_seconds',
            ],
            'properties' => [
                'house_edge' => ['type' => 'number', 'title' => 'House edge', 'description' => 'Fraction, charged once per ladder: 0.04 makes level 1 pay 1.92x and every level after double, 96% RTP at every depth. A multiple of 0.005 between 0.02 and 0.10. Same for every tenant.', 'minimum' => 0.02, 'maximum' => 0.1, 'multipleOf' => 0.005, 'default' => 0.04, 'x-group' => 'game', 'x-tenant-overridable' => false],
                'hard_level_cap' => ['type' => 'integer', 'title' => 'Hard level cap', 'description' => 'Deepest level any ladder can reach, whatever the stake.', 'minimum' => 1, 'maximum' => 10, 'default' => 10, 'x-group' => 'game', 'x-tenant-overridable' => false],
                'max_win_per_ladder' => ['type' => 'number', 'title' => 'Max win per ladder (NAD)', 'description' => 'Sets how deep a stake may climb, shown before the ladder starts. A payout is never cut.', 'minimum' => 1, 'multipleOf' => 1, 'default' => 16000, 'x-group' => 'game', 'x-format' => 'currency'],
                'max_total_riding_per_round' => ['type' => 'number', 'title' => 'Max total riding (NAD)', 'description' => 'Per-tenant ceiling on the value of every live ladder. New ladders are refused once it is reached; continues never are.', 'minimum' => 1, 'multipleOf' => 1, 'default' => 25000, 'x-group' => 'game', 'x-format' => 'currency'],
                'min_bet_amount' => ['type' => 'number', 'title' => 'Min stake (NAD)', 'description' => 'Whole Namibian dollars', 'minimum' => 1, 'multipleOf' => 1, 'default' => 5, 'x-group' => 'game', 'x-format' => 'currency'],
                'max_bet_amount' => ['type' => 'number', 'title' => 'Max stake (NAD)', 'description' => 'Whole Namibian dollars', 'minimum' => 1, 'multipleOf' => 1, 'default' => 50, 'x-group' => 'game', 'x-format' => 'currency'],
                'level_decision_seconds' => ['type' => 'integer', 'title' => 'Decision window (seconds)', 'description' => 'How long players have to stake, continue or collect each round. No choice means collect. Set 5 if the test house reads each level as a game cycle (PRD §7.1).', 'minimum' => 5, 'maximum' => 60, 'default' => 6, 'x-group' => 'game'],
                'reveal_seconds' => ['type' => 'integer', 'title' => 'Reveal (seconds)', 'description' => 'How long the open hands are shown. There is no skip (RTS 14E).', 'minimum' => 1, 'maximum' => 10, 'default' => 2, 'x-group' => 'game'],
            ],
        ];
    }
}
