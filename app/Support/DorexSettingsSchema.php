<?php

namespace App\Support;

/**
 * Dorex's settings schema (PRD §9, platform docs/dorex/2026-10-08-dorex-design.md).
 * The engine re-reads the merged settings from GET /api/v1/games/{uuid}/config at
 * the top of every round and snapshots them onto the round.
 *
 * house_edge is the only setting that moves RTP: the price starts at 1 − house_edge
 * and every exit strategy returns exactly that (the price is a martingale). It is
 * not tenant-overridable: the RTP is published per game (RTS 3). q, vol, max_ticks,
 * tick_ms and max_price tune feel only, but they are the published path, so they
 * are not overridable either. Money is whole NAD.
 */
class DorexSettingsSchema
{
    public static function definition(): array
    {
        return [
            'type' => 'object',
            'required' => [
                'house_edge', 'q', 'vol', 'max_ticks', 'tick_ms', 'max_price',
                'max_win_per_position', 'max_total_exposure_per_round',
                'min_bet_amount', 'max_bet_amount', 'betting_seconds', 'end_display_seconds',
                'instrument_ticker',
            ],
            'properties' => [
                'house_edge' => ['type' => 'number', 'title' => 'House edge', 'description' => 'The price opens at 1 − house edge, and that is the RTP for every strategy: 0.04 opens at 0.96. A multiple of 0.005 between 0.02 and 0.10. Same for every tenant.', 'minimum' => 0.02, 'maximum' => 0.1, 'multipleOf' => 0.005, 'default' => 0.04, 'x-group' => 'game', 'x-tenant-overridable' => false],
                'q' => ['type' => 'number', 'title' => 'Margin-call chance per tick', 'description' => 'Feel only; never changes the RTP. 0.0012 margin-calls about 41% of rounds held to the end.', 'minimum' => 0, 'maximum' => 0.01, 'default' => 0.0012, 'x-group' => 'game', 'x-tenant-overridable' => false],
                'vol' => ['type' => 'number', 'title' => 'Volatility per tick', 'description' => 'Feel only; never changes the RTP. Up to four decimals.', 'minimum' => 0.005, 'maximum' => 0.1, 'default' => 0.04, 'x-group' => 'game', 'x-tenant-overridable' => false],
                'max_ticks' => ['type' => 'integer', 'title' => 'Ticks per round', 'description' => 'At 100 ms a tick, 450 is 45 seconds. A position still open at the end closes at the market price.', 'minimum' => 50, 'maximum' => 1200, 'default' => 450, 'x-group' => 'game', 'x-tenant-overridable' => false],
                'tick_ms' => ['type' => 'integer', 'title' => 'Tick length (ms)', 'description' => 'The path plays at this rate. There is no skip or speed-up (RTS 14E).', 'minimum' => 50, 'maximum' => 1000, 'default' => 100, 'x-group' => 'game', 'x-tenant-overridable' => false],
                'max_price' => ['type' => 'number', 'title' => 'Price ceiling', 'description' => 'Absorbing: a price at or above it closes every position at that tick\'s price.', 'minimum' => 2, 'maximum' => 1000, 'multipleOf' => 0.01, 'default' => 500, 'x-group' => 'game', 'x-tenant-overridable' => false],
                'max_win_per_position' => ['type' => 'number', 'title' => 'Max win per position (NAD)', 'description' => 'Sets each stake\'s ceiling, shown before the player commits. A payout is never cut.', 'minimum' => 1, 'multipleOf' => 1, 'default' => 16000, 'x-group' => 'game', 'x-format' => 'currency'],
                'max_total_exposure_per_round' => ['type' => 'number', 'title' => 'Max total stake per round (NAD)', 'description' => 'Per tenant. New positions are refused once the round\'s stakes reach it.', 'minimum' => 1, 'multipleOf' => 1, 'default' => 25000, 'x-group' => 'game', 'x-format' => 'currency'],
                'min_bet_amount' => ['type' => 'number', 'title' => 'Min stake (NAD)', 'description' => 'Whole Namibian dollars', 'minimum' => 1, 'multipleOf' => 1, 'default' => 10, 'x-group' => 'game', 'x-format' => 'currency'],
                'max_bet_amount' => ['type' => 'number', 'title' => 'Max stake (NAD)', 'description' => 'Whole Namibian dollars', 'minimum' => 1, 'multipleOf' => 1, 'default' => 50, 'x-group' => 'game', 'x-format' => 'currency'],
                'betting_seconds' => ['type' => 'integer', 'title' => 'Stake window (seconds)', 'description' => 'How long stakes are taken before the price starts.', 'minimum' => 5, 'maximum' => 60, 'default' => 8, 'x-group' => 'game'],
                'end_display_seconds' => ['type' => 'integer', 'title' => 'Result display (seconds)', 'description' => 'How long the finished chart is shown before the next stake window.', 'minimum' => 1, 'maximum' => 15, 'default' => 3, 'x-group' => 'game'],
                'instrument_ticker' => ['type' => 'string', 'title' => 'Instrument name', 'description' => 'Invented, and must not match any real currency, pair or listed ticker. No market data is involved.', 'default' => 'DRX', 'x-group' => 'game', 'x-tenant-overridable' => false],
            ],
        ];
    }
}
