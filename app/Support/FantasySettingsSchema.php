<?php

namespace App\Support;

/**
 * Chinga Fantasy v2 settings (PRD §11). house_edge is the only setting
 * that moves RTP and is bounded here AND clamped in the engine; the
 * bounds are the allowed RTP band (85%–98%), so a save outside it is
 * refused by validation. tier_odds is the exact 2dp display odds per
 * tier, comma-separated (the settings form has no number arrays); the
 * engine derives every probability from it. pick_count is fixed at 4:
 * it is the exponent in the odds formula, not a tunable. The v1 keys
 * that made RTP an accident (display_teams, winning_teams_count,
 * jackpot_percentage, odds range) are gone.
 */
class FantasySettingsSchema
{
    public const RTP_MIN = 0.85;

    public const RTP_MAX = 0.98;

    public static function definition(): array
    {
        return [
            'type' => 'object',
            'required' => [
                'house_edge', 'tier_odds', 'grid_size', 'grid_layout', 'pick_count', 'min_bet_amount', 'max_bet_amount',
                'max_win_per_bet', 'max_total_stake_per_round', 'max_total_liability_per_round', 'payout_window_days', 'rounds_ahead', 'jackpot_share_of_edge', 'jackpot_cap',
                'jackpot_activation', 'betting_seconds', 'results_seconds', 'settle_seconds',
            ],
            'properties' => [
                'house_edge' => ['type' => 'number', 'title' => 'House edge', 'description' => 'The ONLY setting that changes RTP. RTP = 1 − edge: 0.10 is 90% back to players. Allowed 0.02–0.15 (RTP 98%–85%); the engine clamps it too.', 'minimum' => 1 - self::RTP_MAX, 'maximum' => 1 - self::RTP_MIN, 'default' => 0.10, 'x-group' => 'game'],
                'tier_odds' => ['type' => 'string', 'title' => 'Tier odds', 'description' => 'Exact 2dp display odds per tier, favourite to longshot, comma-separated. Win chances are derived from these; never the other way round.', 'default' => '1.25,1.50,1.80,2.20,2.75,3.50,4.50', 'x-group' => 'game', 'x-tenant-overridable' => false],
                'tier_weights' => ['type' => 'string', 'title' => 'Tier weights', 'description' => 'Relative teams per tier, favourite to longshot, comma-separated; blank means equal. With the grid size this sets the average number of winners per round (the default 12,10,9,7,6,5,3 on a grid of 52 gives about 28).', 'default' => '12,10,9,7,6,5,3', 'x-group' => 'game', 'x-tenant-overridable' => false],
                'grid_size' => ['type' => 'integer', 'title' => 'Grid size', 'minimum' => 20, 'maximum' => 100, 'default' => 52, 'x-group' => 'game', 'x-tenant-overridable' => false],
                'grid_layout' => ['type' => 'string', 'title' => 'Grid layout', 'description' => 'Fixed: every team keeps its tile from round to round (the Teams page sets the positions) and exactly grid-size teams must be active, or no round opens. Shuffled: the grid is dealt from the pool each round. Tiers (odds and chances) are dealt fresh from the seed every round either way.', 'enum' => ['fixed', 'shuffled'], 'default' => 'fixed', 'x-group' => 'game', 'x-tenant-overridable' => false],
                'pick_count' => ['type' => 'integer', 'title' => 'Picks per ticket', 'description' => 'Fixed at 4: changing it changes the odds formula and is a code change.', 'minimum' => 4, 'maximum' => 4, 'default' => 4, 'x-group' => 'game', 'x-tenant-overridable' => false],
                'min_bet_amount' => ['type' => 'number', 'title' => 'Min stake (NAD)', 'minimum' => 1, 'default' => 5, 'x-group' => 'game', 'x-format' => 'currency'],
                'max_bet_amount' => ['type' => 'number', 'title' => 'Max stake per ticket (NAD)', 'description' => 'Launch default 20; raise per operator once a few weeks of turnover are known.', 'minimum' => 1, 'default' => 20, 'x-group' => 'game', 'x-format' => 'currency'],
                'max_win_per_bet' => ['type' => 'number', 'title' => 'Max win per ticket (NAD)', 'description' => 'Drives the per-ticket stake cap shown on the slip; a payout is never truncated. Launch default 2,000: it is the cap, not the edge, that keeps an operator\'s first weeks out of the red.', 'minimum' => 100, 'default' => 2000, 'x-group' => 'game', 'x-format' => 'currency'],
                'max_total_stake_per_round' => ['type' => 'number', 'title' => 'Max total stake per round (NAD)', 'description' => 'Stake ceiling per tenant per round; further tickets are refused once reached, never on the drawn outcome.', 'minimum' => 100, 'default' => 5000, 'x-group' => 'game', 'x-format' => 'currency'],
                'rounds_ahead' => ['type' => 'integer', 'title' => 'Rounds dealt ahead', 'description' => 'Rounds dealt, committed and open for sale beyond the running one, so a counter always has a round to sell into. 1 keeps the next round on sale while the current one is drawn and settled.', 'minimum' => 0, 'maximum' => 3, 'default' => 1, 'x-group' => 'game', 'x-tenant-overridable' => false],
                'payout_window_days' => ['type' => 'integer', 'title' => 'Slip payout window (days)', 'description' => 'How long a winning over-the-counter slip can be paid out after its round. Printed on the slip and enforced at the counter.', 'minimum' => 1, 'maximum' => 90, 'default' => 7, 'x-group' => 'game'],
                'max_total_liability_per_round' => ['type' => 'number', 'title' => 'Max total liability per round (NAD)', 'description' => 'Ceiling on what one round could pay out in total (the sum of every ticket\'s return if it wins). Protects against a venue backing the same four favourites together. Further tickets are refused once a new one would exceed it.', 'minimum' => 100, 'default' => 20000, 'x-group' => 'game', 'x-format' => 'currency'],
                'jackpot_share_of_edge' => ['type' => 'number', 'title' => 'Jackpot share of edge', 'description' => 'Fraction of the house edge that funds the Chinga Bonus: 0.10 of a 0.10 edge is 1% of turnover.', 'minimum' => 0, 'maximum' => 1, 'default' => 0.10, 'x-group' => 'game'],
                'jackpot_cap' => ['type' => 'number', 'title' => 'Jackpot cap (NAD)', 'description' => 'Accrual stops at the cap (published in the rules).', 'minimum' => 0, 'default' => 50000, 'x-group' => 'game', 'x-format' => 'currency'],
                'jackpot_activation' => ['type' => 'number', 'title' => 'Jackpot activation (NAD)', 'description' => 'The pool must reach this before a bonus team is dealt.', 'minimum' => 0, 'default' => 100, 'x-group' => 'game', 'x-format' => 'currency'],
                'jackpot_cap_policy' => ['type' => 'string', 'title' => 'At the cap', 'enum' => ['stop'], 'default' => 'stop', 'x-group' => 'game', 'x-tenant-overridable' => false, 'x-enum-labels' => ['stop' => 'Accrual stops']],
                'betting_seconds' => ['type' => 'integer', 'title' => 'Betting phase (seconds)', 'minimum' => 5, 'maximum' => 300, 'default' => 30, 'x-group' => 'game'],
                'results_seconds' => ['type' => 'integer', 'title' => 'Results phase (seconds)', 'minimum' => 3, 'maximum' => 120, 'default' => 30, 'x-group' => 'game'],
                'settle_seconds' => ['type' => 'integer', 'title' => 'Settle phase (seconds)', 'minimum' => 3, 'maximum' => 120, 'default' => 30, 'x-group' => 'game'],
                'default_business_model' => ['type' => 'string', 'title' => 'Default business model', 'enum' => ['reseller', 'direct'], 'default' => 'reseller', 'x-group' => 'commercial', 'x-tenant-overridable' => false, 'x-enum-labels' => ['reseller' => 'Reseller (betting shop, gets revenue share)', 'direct' => 'Direct (platform-sold, 100% to platform)']],
                'default_revenue_share_pct' => ['type' => 'number', 'title' => 'Default revenue share %', 'description' => "Tenant's cut of NGR. e.g. 70% → tenant gets 70%, platform 30%.", 'minimum' => 0, 'maximum' => 100, 'default' => 70, 'x-group' => 'commercial', 'x-tenant-overridable' => false, 'x-format' => 'percent'],
                'default_tax_pct' => ['type' => 'number', 'title' => 'Default gambling tax %', 'description' => 'Deducted from GGR before the share split.', 'minimum' => 0, 'maximum' => 100, 'default' => 0, 'x-group' => 'commercial', 'x-tenant-overridable' => false, 'x-format' => 'percent'],
            ],
        ];
    }

    /** v1 keys carried into their v2 equivalents; everything else v1 is dropped. */
    public static function migrateV1(array $old): array
    {
        $map = [
            'min_bet_amount' => 'min_bet_amount',
            'max_bet_amount' => 'max_bet_amount',
            'max_jackpot_amount' => 'jackpot_cap',
            'min_jackpot_amount' => 'jackpot_activation',
            'round_betting_seconds' => 'betting_seconds',
            'round_results_seconds' => 'results_seconds',
            'round_dialog_seconds' => 'settle_seconds',
            'default_business_model' => 'default_business_model',
            'default_revenue_share_pct' => 'default_revenue_share_pct',
            'default_tax_pct' => 'default_tax_pct',
        ];
        $new = [];
        foreach ($map as $from => $to) {
            if (array_key_exists($from, $old)) {
                $new[$to] = $old[$from];
            }
        }
        foreach (array_keys(self::definition()['properties']) as $key) {
            if (array_key_exists($key, $old)) {
                $new[$key] = $old[$key];
            }
        }

        return $new;
    }

    /**
     * Teams per tier for a settings array: the grid split by the tier
     * weights, largest remainders first, ties to the lower tier. The same
     * rule as tierCounts() in @chinga/fantasy-math.
     *
     * @return int[]|null
     */
    public static function tierCounts(array $settings): ?array
    {
        $odds = self::numberList($settings['tier_odds'] ?? null);
        $grid = $settings['grid_size'] ?? null;
        if ($odds === [] || ! is_numeric($grid) || (int) $grid < 1) {
            return null;
        }
        $weights = self::numberList($settings['tier_weights'] ?? null);
        if ($weights === []) {
            $weights = array_fill(0, count($odds), 1.0);
        }
        if (count($weights) !== count($odds) || array_sum($weights) <= 0) {
            return null;
        }
        $total = array_sum($weights);
        $raw = array_map(fn (float $w) => $w / $total * (int) $grid, $weights);
        $counts = array_map(fn (float $r) => (int) floor($r), $raw);
        $remainder = (int) $grid - array_sum($counts);
        $order = array_keys($raw);
        usort($order, function (int $a, int $b) use ($raw, $counts) {
            $fa = $raw[$a] - $counts[$a];
            $fb = $raw[$b] - $counts[$b];

            return $fb <=> $fa ?: $a <=> $b;
        });
        for ($i = 0; $i < $remainder; $i++) {
            $counts[$order[$i % count($order)]]++;
        }

        return $counts;
    }

    /**
     * Average winners per round: each team wins on its own with
     * p = (1 − e)^(1/4) / odds, so the mean is the sum of p over the grid.
     * It is not a setting in itself; grid size, tier weights and house
     * edge set it, and outcomes stay independent.
     */
    public static function expectedWinners(array $settings): ?float
    {
        $counts = self::tierCounts($settings);
        $odds = self::numberList($settings['tier_odds'] ?? null);
        $edge = $settings['house_edge'] ?? null;
        if ($counts === null || ! is_numeric($edge)) {
            return null;
        }
        $factor = (1 - (float) $edge) ** 0.25;
        $mean = 0.0;
        foreach ($odds as $i => $o) {
            if ($o <= 0) {
                return null;
            }
            $mean += $counts[$i] * ($factor / $o);
        }

        return $mean;
    }

    /** @return float[] */
    private static function numberList(mixed $raw): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if (! is_numeric($part)) {
                return [];
            }
            $out[] = (float) $part;
        }

        return $out;
    }

    /** Theoretical RTP for a settings array, or null when the edge is absent. */
    public static function rtp(array $settings): ?float
    {
        $edge = $settings['house_edge'] ?? null;

        return is_numeric($edge) ? 1 - (float) $edge : null;
    }
}
