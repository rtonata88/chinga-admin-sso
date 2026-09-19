// resources/js/components/operator/kpi-card.tsx
//
// Brass KPI card matching the operator-console reference. Drops into
// any .cgo-kpis grid; both /dashboard and /admin use it now. Optional
// delta (with ▲/▼ + pos/neg color), meta line, and an 8-bar
// sparkline for trend cards.

export interface KpiDelta {
    sign: 'pos' | 'neg' | 'flat';
    text: string;
}

export interface KpiCardProps {
    label: string;
    value: string;
    /** Apply the brass-hi color to the number (use on the headline metric). */
    brass?: boolean;
    delta?: KpiDelta;
    meta?: string;
    /** 0..100 percentages — rendered as 8 vertical bars when present. */
    spark?: number[];
}

export function KpiCard({ label, value, brass, delta, meta, spark }: KpiCardProps) {
    const showDelta = delta && delta.sign !== 'flat';
    const showMetaInline = !showDelta;
    const showMetaAside = showDelta && meta && (!spark || spark.length === 0);

    return (
        <div className="cgo-kpi">
            <div className="cgo-kpi-label">{label}</div>
            <div className={`cgo-kpi-num${brass ? ' brass' : ''}`}>{value}</div>
            <div className="cgo-kpi-foot">
                {showDelta ? (
                    <span className={delta!.sign === 'pos' ? 'cgo-delta-pos' : 'cgo-delta-neg'}>
                        {delta!.sign === 'pos' ? '▲ ' : '▼ '}
                        {delta!.text}
                    </span>
                ) : showMetaInline ? (
                    <span className="cgo-meta">{meta}</span>
                ) : null}

                {spark && spark.length > 0 ? (
                    <div className="cgo-spark">
                        {spark.map((h, i) => (
                            <span key={i} style={{ height: `${h}%` }} />
                        ))}
                    </div>
                ) : showMetaAside ? (
                    <span className="cgo-meta">{meta}</span>
                ) : null}
            </div>
        </div>
    );
}

// Helpers — used by both /dashboard and /admin to keep formatting
// consistent. Move into a util module if more pages start using them.

export function formatNAD(n: number): string {
    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function formatCount(n: number): string {
    return n.toLocaleString('en-US');
}

/**
 * Compact NAD formatter for KPI display values. Always prefixes with
 * "N$" and uses K/M/B suffixes so even busy days fit a card without
 * wrapping. Decimal precision shrinks as the magnitude grows.
 */
export function formatCurrencyCompact(n: number): string {
    const sign = n < 0 ? '-' : '';
    const abs = Math.abs(n);
    if (abs >= 1_000_000_000) return `${sign}N$${(abs / 1_000_000_000).toFixed(1)}B`;
    if (abs >= 1_000_000) return `${sign}N$${(abs / 1_000_000).toFixed(1)}M`;
    if (abs >= 100_000) return `${sign}N$${(abs / 1_000).toFixed(0)}K`;
    if (abs >= 1_000) return `${sign}N$${(abs / 1_000).toFixed(1)}K`;
    if (abs >= 100) return `${sign}N$${abs.toFixed(0)}`;
    return `${sign}N$${abs.toFixed(2)}`;
}

/**
 * Day-over-day delta. Returns flat when previous is zero AND current
 * is zero (so KpiCard hides the line); returns "New today" when
 * previous is zero and current is positive.
 */
export function deltaPct(today: number, yesterday: number): KpiDelta {
    if (yesterday === 0) {
        if (today > 0) return { sign: 'pos', text: 'New today' };
        return { sign: 'flat', text: 'No change' };
    }
    const pct = ((today - yesterday) / yesterday) * 100;
    const sign: KpiDelta['sign'] = pct > 0 ? 'pos' : pct < 0 ? 'neg' : 'flat';
    return { sign, text: `${pct >= 0 ? '+' : '-'}${Math.abs(pct).toFixed(1)}%` };
}
