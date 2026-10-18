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
 *
 * In a currency without minor units (USSD design U-D16) every payout must
 * still be whole: a stake must be a multiple of stakeStep(), so the minimum
 * and maximum stake must be too. Nothing is ever rounded.
 */
class KulipiKunaConfigRules
{
    public const GAME_SLUG = 'kulipi-kuna';

    /** Whole units a stake must be a multiple of: 1 with cents, 100 / gcd(level 1 in hundredths, 100) without. */
    public static function stakeStep(int $edgeMil, int $decimals): int
    {
        if ($decimals >= 2) {
            return 1;
        }
        $a = intdiv(1000 - $edgeMil, 5);
        $b = 100;
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return intdiv(100, $a);
    }

    /**
     * @param  array|null  $currency  the tenant's profile (Currencies::forTenant); null on global defaults alone
     * @return array<string, string> settings key => message; empty when the config is valid
     */
    public static function violations(array $settings, ?array $currency = null): array
    {
        $defaults = collect(KulipiKunaSettingsSchema::definition()['properties'])->map(fn ($p) => $p['default'] ?? null)->all();
        $s = array_merge($defaults, array_filter($settings, fn ($v) => $v !== null));

        $edgeMil = (int) round(((float) $s['house_edge']) * 1000);
        $cap = (int) $s['hard_level_cap'];
        $maxWin = (int) $s['max_win_per_ladder'];
        $min = (int) $s['min_bet_amount'];
        $max = (int) $s['max_bet_amount'];

        $currency ??= Currencies::profile('NAD');
        $sym = $currency['symbol'];

        $out = [];
        if ($min > $max) {
            $out['min_bet_amount'] = "min_bet_amount {$sym}{$min} is above max_bet_amount {$sym}{$max}.";
        }
        $step = self::stakeStep($edgeMil, (int) $currency['decimals']);
        if ($step > 1) {
            foreach (['min_bet_amount' => $min, 'max_bet_amount' => $max] as $key => $value) {
                if ($value % $step !== 0) {
                    $out[$key] ??= "{$key} {$sym}{$value} must be a multiple of {$step}: {$currency['code']} has no cents, and at this house edge only multiples of {$step} pay whole amounts.";
                }
            }
        }
        if ($cap < 1 || $cap > 10) {
            $out['hard_level_cap'] = "hard_level_cap {$cap} must be between 1 and 10.";
        }
        $level1Hundredths = intdiv(1000 - $edgeMil, 5);
        if ($max * $level1Hundredths > $maxWin * 100) {
            $level1 = number_format($max * $level1Hundredths / 100, 2, '.', '');
            $out['max_win_per_ladder'] = "max_win_per_ladder {$sym}{$maxWin} does not cover level 1 at the max stake ({$sym}{$level1}).";
        }

        $betting = (int) $s['level_decision_seconds'];
        $decision = (int) $s['decision_seconds'];
        if ($decision < $betting) {
            // A Continue only lands in a round's betting window, so a shorter decision time could end inside it.
            $out['decision_seconds'] = "decision_seconds {$decision} is shorter than the betting window ({$betting} s); it must be at least as long.";
        }

        return $out;
    }
}
