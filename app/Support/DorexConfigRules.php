<?php

namespace App\Support;

/**
 * The Dorex rules a save must pass beyond its schema bounds, mirroring validateConfig in
 *
 * @chinga/dorex-math (platform docs/dorex/2026-10-08-dorex-design.md D5, D12): decimals the
 * engine's units cannot hold are refused (q to 6 places, vol and house_edge to 4, max_price
 * to 2), the bounds q 0–0.01, vol 0.005–0.10, max_ticks 50–1,200 and max_price 2–1,000 hold,
 * the minimum stake is not above the maximum, and the largest stake's ceiling reaches 2.00.
 *
 * The ceiling is the engine's effectiveCeiling, in integers: the largest price C (in
 * ten-thousandths, a whole hundredth) whose worst crossing still pays within max win —
 * stake × ⌈(C − 1) × (10,000 + V) × 100 / (1,000,000 − Q)⌉ ≤ max_win × 10,000 — capped by
 * max_price. The shared cases in tests/Fixtures/dorex-config-cases.json keep the two sides in step.
 */
class DorexConfigRules
{
    public const GAME_SLUG = 'dorex';

    private const PRICE_SCALE = 10_000;

    private const Q_SCALE = 1_000_000;

    private const MIN_CEILING_AT_MAX_BET = 20_000;

    /** @return array<string, string> settings key => message; empty when the config is valid */
    public static function violations(array $settings): array
    {
        $defaults = collect(DorexSettingsSchema::definition()['properties'])->map(fn ($p) => $p['default'] ?? null)->all();
        $s = array_merge($defaults, array_filter($settings, fn ($v) => $v !== null));

        $out = [];
        $q = self::scaled($s['q'], 6);
        $vol = self::scaled($s['vol'], 4);
        $maxPrice = self::scaled($s['max_price'], 2);
        $maxTicks = is_numeric($s['max_ticks']) && (float) $s['max_ticks'] == (int) $s['max_ticks'] ? (int) $s['max_ticks'] : null;

        if ($q === null || $q < 0 || $q > 10_000) {
            $out['q'] = 'q must be between 0 and 0.01, with at most six decimal places.';
        }
        if ($vol === null || $vol < 50 || $vol > 1_000) {
            $out['vol'] = 'vol must be between 0.005 and 0.10, with at most four decimal places.';
        }
        if ($maxTicks === null || $maxTicks < 50 || $maxTicks > 1_200) {
            $out['max_ticks'] = 'max_ticks must be a whole number between 50 and 1,200.';
        }
        if ($maxPrice === null || $maxPrice < 200 || $maxPrice > 100_000) {
            $out['max_price'] = 'max_price must be between 2.00 and 1,000.00, with at most two decimal places.';
        }
        if (self::scaled($s['house_edge'], 4) === null) {
            $out['house_edge'] = 'house_edge has more than four decimal places.';
        }

        $min = (int) $s['min_bet_amount'];
        $max = (int) $s['max_bet_amount'];
        $maxWin = (int) $s['max_win_per_position'];
        if ($min > $max) {
            $out['min_bet_amount'] = "min_bet_amount N\${$min} is above max_bet_amount N\${$max}.";
        }
        if (! isset($out['q']) && ! isset($out['vol']) && ! isset($out['max_price']) && $q !== null && $vol !== null && $maxPrice !== null && $max >= 1 && $maxWin >= 1) {
            $ceiling = self::effectiveCeiling($max, $maxWin, $q, $vol, $maxPrice * 100);
            if ($ceiling < self::MIN_CEILING_AT_MAX_BET) {
                $shown = number_format($ceiling / self::PRICE_SCALE, 2, '.', '');
                $out['max_win_per_position'] = "max_win_per_position N\${$maxWin} leaves the N\${$max} stake a ceiling of {$shown}; it must reach 2.00.";
            }
        }

        return $out;
    }

    /** The ceiling for a stake in price units (ten-thousandths), as @chinga/dorex-math effectiveCeiling. */
    public static function effectiveCeiling(int $stakeNad, int $maxWinNad, int $qPpm, int $volBp, int $maxPriceUnits): int
    {
        $bound = intdiv($maxWinNad * self::PRICE_SCALE, $stakeNad);
        $factor = (self::PRICE_SCALE + $volBp) * intdiv(self::Q_SCALE, self::PRICE_SCALE);
        $largest = intdiv($bound * (self::Q_SCALE - $qPpm), $factor) + 1;
        $capped = min($largest, $maxPriceUnits);

        return intdiv($capped, 100) * 100;
    }

    /** A non-negative decimal as an integer count of 10^-places, or null when it holds more places. */
    private static function scaled(mixed $value, int $places): ?int
    {
        if (! is_numeric($value) || (float) $value < 0) {
            return null;
        }
        $f = (float) $value * (10 ** $places);
        $r = (int) round($f);

        return abs($f - $r) < 1e-6 ? $r : null;
    }
}
