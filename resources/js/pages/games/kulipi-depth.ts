// resources/js/pages/games/kulipi-depth.ts
//
// Kulipi Kuna settings preview (K4 design §2.5): the deepest level each stake
// tier reaches and what that level pays, recomputed from the form. Integer
// hundredths of the stake, as in @chinga/kulipi-math: level(n) =
// (1000 − edge‰) / 5 × 2^(n−1); depth is the largest n ≤ cap whose payout
// fits max_win_per_ladder. Display only; the engine does its own maths.
//
// Checked by hand against the PRD table (edge 0.04, cap 10, max win 16000;
// level 1 = 192 hundredths of the stake):
//   N$5 and N$10 reach level 10; N$10 at level 10 pays 10 × 192 × 512 = N$9,830.40.
//   N$20, N$25 and N$30 reach level 9 (N$30: 1,474,560 cents fits; level 10 does not).
//   N$40, N$45 and N$50 reach level 8; N$50 at level 8 pays 50 × 192 × 128 = N$12,288.00.

export interface DepthRow {
    stake: number;
    depth: number;
    pays: string;
}

const num = (v: unknown, fallback: number): number => {
    const n = typeof v === 'string' ? Number(v) : typeof v === 'number' ? v : NaN;
    return Number.isFinite(n) ? n : fallback;
};

function nad(cents: number): string {
    const whole = Math.floor(cents / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return `N$${whole}.${String(cents % 100).padStart(2, '0')}`;
}

export function depthRows(settings: Record<string, unknown>): DepthRow[] {
    const edgeMil = Math.round(num(settings.house_edge, 0.04) * 1000);
    const cap = Math.trunc(num(settings.hard_level_cap, 10));
    const maxWinCents = Math.trunc(num(settings.max_win_per_ladder, 16000)) * 100;
    const min = Math.trunc(num(settings.min_bet_amount, 5));
    const max = Math.trunc(num(settings.max_bet_amount, 50));
    if (edgeMil < 20 || edgeMil > 100 || cap < 1 || cap > 10 || min < 1 || max < min) return [];

    const tiers = new Set<number>([min, max]);
    for (let s = Math.ceil(min / 5) * 5; s < max; s += 5) if (s > min) tiers.add(s);

    const level1 = (1000 - edgeMil) / 5;
    return [...tiers]
        .sort((a, b) => a - b)
        .map((stake) => {
            let depth = 0;
            for (let n = 1; n <= cap; n++) {
                if (stake * level1 * 2 ** (n - 1) > maxWinCents) break;
                depth = n;
            }
            return { stake, depth, pays: depth === 0 ? '—' : nad(stake * level1 * 2 ** (depth - 1)) };
        });
}
