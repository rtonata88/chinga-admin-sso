// resources/js/pages/games/dorex-ceiling.ts
//
// Dorex settings preview (X4): the ceiling each stake tier gets, and the most a stake can be
// paid when the price crosses it, recomputed from the form. Integers, as @chinga/dorex-math
// effectiveCeiling: prices in ten-thousandths, q in millionths, vol in ten-thousandths. The
// largest C (a whole hundredth) with stake × ⌈(C − 1)(10,000 + V)·100 / (1,000,000 − Q)⌉ ≤
// max_win × 10,000, capped by max_price. Display only; the engine does its own maths.
// Stakes and max win are whole units of each tenant's own currency, so the most paid is
// shown in the currency given (or as a bare figure when the game's tenants span several).
//
// Checked against the engine (q 0.0012, vol 0.04, max win 16000, max price 500):
//   N$10 and N$20 reach the 500.00 price ceiling; N$33 gets 465.64; N$50 gets 307.32.

export interface CeilingRow {
    stake: number;
    ceiling: string;
    mostPaid: string;
}

const num = (v: unknown, fallback: number): number => {
    const n = typeof v === 'string' ? Number(v) : typeof v === 'number' ? v : NaN;
    return Number.isFinite(n) ? n : fallback;
};

/** A decimal as an integer count of 10^-places, or null when it holds more places. */
function scaled(v: unknown, places: number, fallback: number): number | null {
    const f = num(v, fallback) * 10 ** places;
    const r = Math.round(f);
    return Math.abs(f - r) < 1e-6 ? r : null;
}

/**
 * Turns a 2dp decimal string into what the page shows (formatMoney in the
 * caller's currency). Taken as a function so this module stays free of
 * imports: the settings tests can run it under plain node.
 */
export type MoneyFormat = (amount: string) => string;

/** No currency (the game's tenants span several): the grouped figure alone. */
const bare: MoneyFormat = (amount) => {
    const [whole, cents] = amount.split('.');
    return `${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${cents}`;
};

function money(cents: bigint, format: MoneyFormat): string {
    return format(`${cents / 100n}.${(cents % 100n).toString().padStart(2, '0')}`);
}

/** More stake tiers than this and the preview is not drawn. */
export const MAX_TIERS = 200;

export function ceilingRows(settings: Record<string, unknown>, format: MoneyFormat = bare): CeilingRow[] {
    const q = scaled(settings.q, 6, 0.0012);
    const vol = scaled(settings.vol, 4, 0.04);
    const maxPrice = scaled(settings.max_price, 2, 500);
    const maxWin = Math.trunc(num(settings.max_win_per_position, 16000));
    const min = Math.trunc(num(settings.min_bet_amount, 10));
    const max = Math.trunc(num(settings.max_bet_amount, 50));
    if (q === null || vol === null || maxPrice === null || q < 0 || q > 10_000 || vol < 50 || vol > 1_000) return [];
    if (min < 1 || max < min || maxWin < 1 || (max - min) / 5 > MAX_TIERS) return [];

    const tiers = new Set<number>([min, max]);
    for (let s = Math.ceil(min / 5) * 5; s < max; s += 5) if (s > min) tiers.add(s);
    const factor = BigInt((10_000 + vol) * 100);
    const space = BigInt(1_000_000 - q);
    const cap = BigInt(maxPrice * 100);

    return [...tiers]
        .sort((a, b) => a - b)
        .map((stake) => {
            const bound = (BigInt(maxWin) * 10_000n) / BigInt(stake);
            let c = (bound * space) / factor + 1n;
            if (c > cap) c = cap;
            c = (c / 100n) * 100n;
            const worst = ((c - 1n) * factor + space - 1n) / space;
            const paid = (BigInt(stake) * worst) / 100n;
            const hundredths = c / 100n;
            return {
                stake,
                ceiling: `${hundredths / 100n}.${(hundredths % 100n).toString().padStart(2, '0')}`,
                mostPaid: money(paid, format),
            };
        });
}
