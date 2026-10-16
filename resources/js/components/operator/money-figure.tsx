// resources/js/components/operator/money-figure.tsx
//
// A table figure with its currency code in front ("BWP 1,184.60"), in the
// .cgo-ccy idiom the operator tables use. Each row passes its own profile, so
// a platform table of several tenants shows each in that tenant's currency.
// A row with no known currency (a backend key no SSO tenant owns) shows the
// bare figure rather than borrowing another currency's code.

import { type Amount, type CurrencyProfile, formatFigure } from '@/lib/money';

const BARE: CurrencyProfile = { code: '', symbol: '', decimals: 2, symbol_position: 'before', space: false, thousands: ',', decimal: '.' };

export function MoneyFigure({ value, profile, code = true }: { value: Amount; profile: CurrencyProfile | null; code?: boolean }) {
    return (
        <>
            {code && profile ? <span className="cgo-ccy">{profile.code}</span> : null}
            {formatFigure(value, profile ?? BARE)}
        </>
    );
}

/** A figure's sign for colouring (negative GGR in red) without parsing it as a float. */
export function isNegative(value: Amount): boolean {
    return typeof value === 'number' ? value < 0 : typeof value === 'string' && value.trim().startsWith('-') && /[1-9]/.test(value);
}
