<?php

namespace App\Support;

/**
 * Chinga Fantasy's settings schema. One source for the migration backfill
 * and the seeder. Defaults mirror gameConfigService.DEFAULTS in
 * chinga-fantasy/app/services/gameConfigService.js and the bounds mirror
 * the validation rules the hardcoded form used to carry. If you change
 * either side, change both — they are a contract between the services.
 */
class FantasySettingsSchema
{
    public static function definition(): array
    {
        return [
            'type' => 'object',
            'required' => [
                'min_bet_amount', 'max_bet_amount', 'display_teams', 'round_betting_seconds',
                'round_results_seconds', 'round_dialog_seconds', 'min_jackpot_amount',
                'max_jackpot_amount', 'jackpot_percentage', 'winning_teams_count',
            ],
            'properties' => [
                'min_bet_amount' => ['type' => 'number', 'title' => 'Min bet (NAD)', 'minimum' => 1, 'default' => 5, 'x-group' => 'game', 'x-format' => 'currency'],
                'max_bet_amount' => ['type' => 'number', 'title' => 'Max bet (NAD)', 'minimum' => 1, 'default' => 50, 'x-group' => 'game', 'x-format' => 'currency'],
                'display_teams' => ['type' => 'integer', 'title' => 'Display teams', 'minimum' => 4, 'maximum' => 100, 'default' => 50, 'x-group' => 'game'],
                'winning_teams_count' => ['type' => 'integer', 'title' => 'Winning teams', 'description' => 'How many teams are drawn as winners each round', 'minimum' => 4, 'maximum' => 50, 'default' => 20, 'x-group' => 'game'],
                'round_betting_seconds' => ['type' => 'integer', 'title' => 'Betting phase (seconds)', 'minimum' => 10, 'maximum' => 300, 'default' => 30, 'x-group' => 'game'],
                'round_results_seconds' => ['type' => 'integer', 'title' => 'Results phase (seconds)', 'minimum' => 5, 'maximum' => 120, 'default' => 30, 'x-group' => 'game'],
                'round_dialog_seconds' => ['type' => 'integer', 'title' => 'Dialog phase (seconds)', 'minimum' => 5, 'maximum' => 120, 'default' => 30, 'x-group' => 'game'],
                'min_jackpot_amount' => ['type' => 'number', 'title' => 'Min jackpot (NAD)', 'minimum' => 0, 'default' => 100, 'x-group' => 'game', 'x-format' => 'currency'],
                'max_jackpot_amount' => ['type' => 'number', 'title' => 'Max jackpot (NAD)', 'description' => 'Jackpot stops growing once this cap is reached', 'minimum' => 0, 'default' => 50000, 'x-group' => 'game', 'x-format' => 'currency'],
                'jackpot_percentage' => ['type' => 'integer', 'title' => 'Jackpot contribution %', 'description' => 'Of each losing deposit-funded bet that feeds the jackpot', 'minimum' => 0, 'maximum' => 100, 'default' => 15, 'x-group' => 'game', 'x-format' => 'percent'],
                'default_business_model' => ['type' => 'string', 'title' => 'Default business model', 'enum' => ['reseller', 'direct'], 'default' => 'reseller', 'x-group' => 'commercial', 'x-tenant-overridable' => false, 'x-enum-labels' => ['reseller' => 'Reseller (betting shop, gets revenue share)', 'direct' => 'Direct (platform-sold, 100% to platform)']],
                'default_revenue_share_pct' => ['type' => 'number', 'title' => 'Default revenue share %', 'description' => "Tenant's cut of NGR. e.g. 70% → tenant gets 70%, platform 30%.", 'minimum' => 0, 'maximum' => 100, 'default' => 70, 'x-group' => 'commercial', 'x-tenant-overridable' => false, 'x-format' => 'percent'],
                'default_tax_pct' => ['type' => 'number', 'title' => 'Default gambling tax %', 'description' => 'Deducted from GGR before the share split.', 'minimum' => 0, 'maximum' => 100, 'default' => 0, 'x-group' => 'commercial', 'x-tenant-overridable' => false, 'x-format' => 'percent'],
                'house_edge_target_pct' => ['type' => 'number', 'title' => 'House edge target %', 'description' => 'Informational target. e.g. 10% means we expect to keep 10% of every NAD wagered (RTP = 90%).', 'minimum' => 0, 'maximum' => 100, 'default' => 5, 'x-group' => 'commercial', 'x-tenant-overridable' => false, 'x-format' => 'percent'],
            ],
        ];
    }
}
