<?php

namespace App\Support;

/**
 * The Kulipi Kuna rules a save must pass beyond its schema bounds (K4
 * design A3), mirroring validateConfig in @chinga/kulipi-math: the
 * minimum stake is not above the maximum, the level cap is 1–10, and
 * max_win_per_ladder covers level 1 at the maximum stake. Level 1 pays
 * (1000 − edge‰) / 5 hundredths of the stake, computed in integers. The
 * shared cases in tests/Fixtures/kulipi-config-cases.json keep the two
 * sides in step.
 */
class KulipiKunaConfigRules
{
    public const GAME_SLUG = 'kulipi-kuna';

    /** @return array<string, string> settings key => message; empty when the config is valid */
    public static function violations(array $settings): array
    {
        $defaults = collect(KulipiKunaSettingsSchema::definition()['properties'])->map(fn ($p) => $p['default'] ?? null)->all();
        $s = array_merge($defaults, array_filter($settings, fn ($v) => $v !== null));

        $edgeMil = (int) round(((float) $s['house_edge']) * 1000);
        $cap = (int) $s['hard_level_cap'];
        $maxWin = (int) $s['max_win_per_ladder'];
        $min = (int) $s['min_bet_amount'];
        $max = (int) $s['max_bet_amount'];

        $out = [];
        if ($min > $max) {
            $out['min_bet_amount'] = "min_bet_amount N\${$min} is above max_bet_amount N\${$max}.";
        }
        if ($cap < 1 || $cap > 10) {
            $out['hard_level_cap'] = "hard_level_cap {$cap} must be between 1 and 10.";
        }
        $level1Hundredths = intdiv(1000 - $edgeMil, 5);
        if ($max * $level1Hundredths > $maxWin * 100) {
            $level1 = number_format($max * $level1Hundredths / 100, 2, '.', '');
            $out['max_win_per_ladder'] = "max_win_per_ladder N\${$maxWin} does not cover level 1 at the max stake (N\${$level1}).";
        }

        return $out;
    }
}
