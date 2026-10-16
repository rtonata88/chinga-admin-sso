// resources/js/pages/vrrr-pha/format.ts
//
// Shared formatting for the Vrrr Pha consoles. The engine sends money
// as 2dp strings, multipliers as 2dp strings, ratios as 4dp strings and
// tenants as uuids; these turn them into what the brass shell shows.
//
// Money is in each row's tenant's currency: the console controllers tag every
// engine row with `currency`, and a cross-tenant total is one figure per
// currency (lib/money.ts), never a sum across currencies.

import { type Amount, type CurrencyProfile, type CurrencyTotal, formatFigure, formatMoney, formatMoneyCompact, formatTotals, toCents, useCurrencies } from '@/lib/money';

const DATETIME_FMT = new Intl.DateTimeFormat('en-GB', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit',
});

export function formatDateTime(iso: string | null | undefined): string {
    if (!iso) return '—';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '—' : DATETIME_FMT.format(d);
}

export function num(v: string | number | null | undefined): number {
    if (v === null || v === undefined || v === '') return 0;
    const n = typeof v === 'string' ? parseFloat(v) : v;
    return Number.isFinite(n) ? n : 0;
}

/** What a tagged engine row carries: its tenant's currency code, null when the SSO does not know the tenant. */
export interface HasCurrency {
    currency?: string | null;
}

/** Rows of a tenant the SSO does not know total under this code rather than vanish (TenantCurrencyMap::UNKNOWN). */
export const UNKNOWN_CURRENCY = '?';

const UNKNOWN_PROFILE: CurrencyProfile = { code: UNKNOWN_CURRENCY, symbol: UNKNOWN_CURRENCY, decimals: 2, symbol_position: 'before', space: true, thousands: ',', decimal: '.' };

export interface RowMoney {
    /** The profile for a code; an unknown or missing code shows as "?", never as another currency. */
    profile: (code: string | null | undefined) => CurrencyProfile;
    /** The code chip a money cell shows beside its figure. */
    code: (code: string | null | undefined) => string;
    /** The figure alone, in that currency's decimals and separators. */
    figure: (v: Amount, code: string | null | undefined) => string;
    /** A KPI value from per-currency totals: "N$12.4K · P3.1K", or "—" with nothing to show. */
    totals: (totals: readonly CurrencyTotal[], field: string) => string;
    /** A KPI value in one currency: "N$12.4K". */
    compact: (v: Amount, code: string | null | undefined) => string;
    /** The full figure with its symbol: "P1,184.60". */
    full: (v: Amount, code: string | null | undefined) => string;
}

export function useRowMoney(): RowMoney {
    const lookup = useCurrencies();
    const profile = (code: string | null | undefined): CurrencyProfile => lookup(code || UNKNOWN_CURRENCY) ?? UNKNOWN_PROFILE;
    return {
        profile,
        code: (code) => code || UNKNOWN_CURRENCY,
        figure: (v, code) => formatFigure(v, profile(code)),
        totals: (totals, field) => formatTotals(totals, field, (c) => profile(c), true),
        compact: (v, code) => formatMoneyCompact(v, profile(code)),
        full: (v, code) => formatMoney(v, profile(code)),
    };
}

/** a − b in whole cents, as a 2dp decimal string (GGR = wagered − paid out). */
export function moneyDiff(a: Amount, b: Amount): string {
    const cents = toCents(a) - toCents(b);
    const abs = Math.abs(cents);
    return `${cents < 0 ? '-' : ''}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`;
}

/** A tagged row's currency, for sumByCurrency: unknown tenants total under "?". */
export const rowCurrency = (r: HasCurrency): string => r.currency || UNKNOWN_CURRENCY;

export function formatMultiplier(v: string | number | null | undefined): string {
    if (v === null || v === undefined || v === '') return '—';
    return `${num(v).toFixed(2)}×`;
}

/** A 0..1 ratio as a percentage, e.g. "96.00%". */
export function formatRatioPct(v: string | number | null | undefined, digits = 2): string {
    if (v === null || v === undefined || v === '') return '—';
    return `${(num(v) * 100).toFixed(digits)}%`;
}

export function shortUuid(u: string | null | undefined): string {
    return u ? u.slice(0, 8) : '—';
}

export function tenantLabel(uuid: string | null | undefined, names: Record<string, string>): string {
    if (!uuid) return '—';
    return names[uuid] ?? shortUuid(uuid);
}

export type RoundState = 'BETTING' | 'LOCKED' | 'PULLING' | 'CRASHED' | 'SETTLING' | 'SETTLED' | 'OPEN' | 'CLOSED' | 'REVEAL' | 'LIVE' | 'ENDED';

/** Round state → pill class + label. In-play states are "live"; the crash point is hidden until CRASHED. */
export function statePill(state: string): { label: string; pill: 'live' | 'pending' | 'settled' | 'void' } {
    switch (state) {
        case 'BETTING':
            return { label: 'betting', pill: 'live' };
        case 'LOCKED':
            return { label: 'locked', pill: 'live' };
        case 'PULLING':
            return { label: 'pulling', pill: 'live' };
        case 'CRASHED':
            return { label: 'crashed', pill: 'pending' };
        case 'SETTLING':
            return { label: 'settling', pill: 'pending' };
        case 'SETTLED':
            return { label: 'settled', pill: 'settled' };
        case 'OPEN':
            return { label: 'open', pill: 'live' };
        case 'CLOSED':
            return { label: 'closed', pill: 'live' };
        case 'REVEAL':
            return { label: 'revealing', pill: 'pending' };
        case 'LIVE':
            return { label: 'live', pill: 'live' };
        case 'ENDED':
            return { label: 'ended', pill: 'pending' };
        default:
            return { label: state.toLowerCase(), pill: 'void' };
    }
}

export function outcomePill(outcome: string): { label: string; pill: 'live' | 'pending' | 'settled' | 'flagged' | 'void' } {
    switch (outcome) {
        case 'cashed':
            return { label: 'cashed out', pill: 'settled' };
        case 'busted':
            return { label: 'busted', pill: 'flagged' };
        case 'pending':
            return { label: 'in play', pill: 'live' };
        case 'cashing':
            return { label: 'cashing', pill: 'pending' };
        case 'cancelled':
            return { label: 'cancelled', pill: 'void' };
        default:
            return { label: outcome, pill: 'void' };
    }
}

export const PANEL: React.CSSProperties = {
    background: 'var(--cg-ink-card)',
    border: '1px solid var(--cg-rule)',
    borderRadius: 8,
    padding: 16,
};

export const ERROR_BOX: React.CSSProperties = {
    background: 'var(--cg-ink-card)',
    border: '1px solid var(--cg-neg)',
    borderRadius: 6,
    padding: 14,
    marginBottom: 18,
    fontSize: 13,
    color: 'var(--cg-fg-1)',
};

export const SELECT_RESET: React.CSSProperties = {
    all: 'unset', flex: 1, color: 'inherit', font: 'inherit', cursor: 'pointer',
};

/*
 * Phone-width tap targets. The brass shell's controls are 28–32px; below
 * sm they grow to 40px. Important (!) because the shell's unlayered CSS
 * (`all: unset`, fixed heights) otherwise wins over Tailwind utilities.
 */
export const TAP = 'max-sm:min-h-10!';
export const TAP_SQUARE = 'max-sm:min-h-10! max-sm:min-w-10!';
