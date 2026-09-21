// resources/js/pages/vrrr-pha/format.ts
//
// Shared formatting for the Vrrr Pha consoles. The engine sends money
// as 2dp strings, multipliers as 2dp strings, ratios as 4dp strings and
// tenants as uuids; these turn them into what the brass shell shows.

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

export function formatNAD(v: string | number | null | undefined): string {
    return num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

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

export type RoundState = 'BETTING' | 'LOCKED' | 'PULLING' | 'CRASHED' | 'SETTLING' | 'SETTLED';

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
