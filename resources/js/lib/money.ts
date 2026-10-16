// resources/js/lib/money.ts
//
// Money on the consoles, in the right currency (USSD design U-D15). A tenant's
// users see every figure in their tenant's currency (the profile the SSO shares
// as `tenant.currency`); platform consoles show each tenant's money in that
// tenant's own currency and NEVER add different currencies together — a
// cross-tenant total is one figure per currency, with no exchange rate.
//
// Mirrors app/Support/Currencies.php and @chinga/currency. Amounts arrive as
// decimal strings (or numbers from older endpoints) and are handled as integer
// cents, so a column of figures sums exactly.

import { usePage } from '@inertiajs/react';

export interface CurrencyProfile {
    code: string;
    name?: string;
    symbol: string;
    /** 0 or 2 (U-D16). */
    decimals: number;
    symbol_position: 'before' | 'after' | string;
    space: boolean;
    thousands: string;
    decimal: string;
}

export type Amount = string | number | null | undefined;

/** One currency's share of a cross-tenant total: { currency: 'BWP', total_wagered: '120.50', ... }. */
export type CurrencyTotal<F extends string = string> = { currency: string } & Record<F, string | number>;

/** A code we hold no profile for still shows its own code, never another currency's symbol. */
function unknownProfile(code: string): CurrencyProfile {
    return { code, symbol: code, decimals: 2, symbol_position: 'before', space: true, thousands: ',', decimal: '.' };
}

/** Decimal string or number → integer cents. Strings are parsed exactly; numbers (older endpoints) to the nearest cent. */
export function toCents(v: Amount): number {
    if (v === null || v === undefined || v === '') return 0;
    if (typeof v === 'number') return Number.isFinite(v) ? Math.round(v * 100) : 0;
    const m = /^\s*(-?)(\d*)(?:\.(\d*))?\s*$/.exec(v);
    if (!m) return 0;
    const whole = Number(m[2] || '0');
    const frac = Number(((m[3] ?? '') + '00').slice(0, 2));
    // A third decimal place rounds half away from zero; consoles only ever receive 2dp.
    const third = Number((m[3] ?? '')[2] ?? '0');
    const cents = whole * 100 + frac + (third >= 5 ? 1 : 0);
    return m[1] === '-' ? -cents : cents;
}

/** "1,184.60" / "2,500": the figure without a symbol. */
export function formatFigure(v: Amount, p: CurrencyProfile): string {
    const cents = toCents(v);
    const abs = Math.abs(cents);
    const fraction = abs % 100;
    const whole = String(Math.floor(abs / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, p.thousands);
    // A 0-decimal currency never carries cents; if one ever arrives, show it rather than hide it.
    const body = p.decimals > 0 || fraction !== 0 ? `${whole}${p.decimal}${String(fraction).padStart(2, '0')}` : whole;
    return cents < 0 ? `-${body}` : body;
}

function withSymbol(figure: string, p: CurrencyProfile, negative: boolean): string {
    const sep = p.space ? ' ' : '';
    const body = p.symbol_position === 'after' ? `${figure}${sep}${p.symbol}` : `${p.symbol}${sep}${figure}`;
    return negative ? `-${body}` : body;
}

/** "P1,184.60", "USh 2,500", "-N$12.40". */
export function formatMoney(v: Amount, p: CurrencyProfile): string {
    const cents = toCents(v);
    return withSymbol(formatFigure(Math.abs(cents) / 100, p), p, cents < 0);
}

/** KPI-card form: "N$12.4K", "P1.2M"; precision shrinks as the magnitude grows. */
export function formatMoneyCompact(v: Amount, p: CurrencyProfile): string {
    const cents = toCents(v);
    const abs = Math.abs(cents) / 100;
    let figure: string;
    if (abs >= 1_000_000_000) figure = `${(abs / 1_000_000_000).toFixed(1)}B`;
    else if (abs >= 1_000_000) figure = `${(abs / 1_000_000).toFixed(1)}M`;
    else if (abs >= 100_000) figure = `${(abs / 1_000).toFixed(0)}K`;
    else if (abs >= 1_000) figure = `${(abs / 1_000).toFixed(1)}K`;
    else if (abs >= 100 || p.decimals === 0) figure = abs.toFixed(0);
    else figure = abs.toFixed(2);
    return withSymbol(figure, p, cents < 0);
}

/**
 * Sums money fields per currency from rows that each carry (or map to) a
 * currency code. Different currencies are never added together.
 */
export function sumByCurrency<R, F extends string>(
    rows: readonly R[],
    currencyOf: (row: R) => string | null | undefined,
    fields: readonly F[],
    valueOf: (row: R, field: F) => Amount = (row, field) => (row as Record<string, Amount>)[field],
): CurrencyTotal<F>[] {
    const by = new Map<string, Record<F, number>>();
    for (const row of rows) {
        const code = currencyOf(row);
        if (!code) continue;
        let acc = by.get(code);
        if (!acc) {
            acc = Object.fromEntries(fields.map((f) => [f, 0])) as Record<F, number>;
            by.set(code, acc);
        }
        for (const f of fields) acc[f] += toCents(valueOf(row, f));
    }
    return [...by.entries()]
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([currency, acc]) => ({
            currency,
            ...(Object.fromEntries(fields.map((f) => [f, (acc[f] / 100).toFixed(2)])) as Record<F, string>),
        })) as CurrencyTotal<F>[];
}

interface MoneyPageProps {
    tenant?: { currency?: CurrencyProfile } | null;
    currencies?: Record<string, CurrencyProfile>;
    [key: string]: unknown;
}

/** The signed-in user's tenant currency; null for a platform admin outside any tenant. */
export function useTenantCurrency(): CurrencyProfile | null {
    const { props } = usePage<MoneyPageProps>();
    return props.tenant?.currency ?? null;
}

/** Looks up a profile by code from the shared `currencies` prop. Unknown codes show their own code. */
export function useCurrencies(): (code: string | null | undefined) => CurrencyProfile | null {
    const { props } = usePage<MoneyPageProps>();
    const all = props.currencies ?? {};
    const tenant = props.tenant?.currency ?? null;
    return (code) => {
        if (!code) return null;
        // The tenant's own profile carries its symbol override.
        if (tenant && tenant.code === code) return tenant;
        return all[code] ?? unknownProfile(code);
    };
}

/**
 * One figure per currency, joined: "N$12,400.00 · P3,100.00". An empty list
 * shows a dash rather than a zero in some arbitrary currency.
 */
export function formatTotals(
    totals: readonly CurrencyTotal[],
    field: string,
    profileOf: (code: string) => CurrencyProfile | null,
    compact = false,
): string {
    if (totals.length === 0) return '—';
    return totals
        .map((t) => {
            const p = profileOf(t.currency) ?? unknownProfile(t.currency);
            const v = t[field] as Amount;
            return compact ? formatMoneyCompact(v, p) : formatMoney(v, p);
        })
        .join(' · ');
}
