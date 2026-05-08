// resources/js/pages/operator/live-wagers.tsx
//
// Live Wagers Monitor — operator console screen v1.
// Built per design_handoff_operator_console/README.md (and the
// reference HTML in the same folder). The visual structure is, top to
// bottom: page header → KPI strip → filter bar → table → footer.
//
// Data is currently mocked at the controller level (see
// app/Http/Controllers/Operator/LiveWagersController.php). The
// FantasyAdminClient already exposes per-round bet listings; cross-
// round live wagers is the next plumbing step (out of scope for v1).

import { Head, router } from '@inertiajs/react';
import { Plus, Download, MoreHorizontal } from 'lucide-react';

import OperatorConsoleLayout from '@/layouts/operator/operator-console-layout';

type WagerStatus = 'live' | 'pending' | 'settled-loss' | 'settled-win' | 'flagged-aml' | 'flagged-velocity' | 'void';

interface Wager {
    id: string;
    placedAt: string;       // "21:14:02"
    placedDate: string;     // "08 May"
    player: { name: string; id: string; isVip: boolean; initials: string };
    event: { name: string; market: string };
    selection: { pick: string; suffix?: string };
    stake: { amount: string; currency: string };
    odds: string;
    potential: string;
    status: WagerStatus;
    legs?: { resolved: number; total: number };
}

interface Kpi {
    label: string;
    value: string;
    brass?: boolean;
    delta?: { sign: 'pos' | 'neg'; text: string };
    meta?: string;
    spark?: number[]; // 0..100 percentages, rendered as bars
}

type FilterValue = 'all' | 'live' | 'pending' | 'flagged' | 'settled';

interface FilterCount {
    label: string;
    value: FilterValue;
    count: number;
    danger?: boolean;
}

interface PageProps {
    lastUpdated: string;        // "21:14:08 CAT"
    kpis: [Kpi, Kpi, Kpi, Kpi];
    filters: {
        active: FilterValue;
        sport: string;
        stakeRange: string;
        timeRange: string;
        sort: string;
        counts: FilterCount[];
    };
    wagers: Wager[];
    pagination: {
        from: number;
        to: number;
        total: number;
        currentPage: number;
        lastPage: number;
    };
}

function statusPill(status: WagerStatus, legs?: { resolved: number; total: number }): { className: string; label: string } {
    switch (status) {
        case 'live':
            return {
                className: 'cgo-pill live',
                label: legs ? `Live · ${legs.resolved}/${legs.total}` : 'Live',
            };
        case 'pending':
            return { className: 'cgo-pill pending', label: 'Pending' };
        case 'settled-win':
            return { className: 'cgo-pill settled', label: 'Settled · Win' };
        case 'settled-loss':
            return { className: 'cgo-pill settled', label: 'Settled · Loss' };
        case 'flagged-aml':
            return { className: 'cgo-pill flagged', label: 'Flagged · AML' };
        case 'flagged-velocity':
            return { className: 'cgo-pill flagged', label: 'Flagged · Velocity' };
        case 'void':
            return { className: 'cgo-pill void', label: 'Void · Refund' };
    }
}

function isFlaggedRow(status: WagerStatus): boolean {
    return status === 'flagged-aml' || status === 'flagged-velocity';
}

export default function LiveWagersMonitor({ lastUpdated, kpis, filters, wagers, pagination }: PageProps) {
    const setFilter = (value: FilterValue) => {
        router.get('/operator/wagers', { status: value }, { preserveState: true, preserveScroll: true });
    };

    return (
        <OperatorConsoleLayout
            breadcrumbs={[
                { label: 'Trading' },
                { label: 'Live wagers' },
            ]}
        >
            <Head title="Live wagers — Operator Console" />

            <div className="cgo-page">
                {/* Page header */}
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Trading desk</div>
                        <h1 className="cgo-title">
                            Live wagers monitor
                            <span className="cgo-live-pill">Live · auto-refresh 5s</span>
                        </h1>
                        <div className="cgo-subtitle">
                            All open positions across sportsbook, virtuals and casino. Updated {lastUpdated}.
                        </div>
                    </div>
                    <div className="cgo-head-actions">
                        <button type="button" className="cg-btn cg-btn--ghost cg-btn--sm">
                            <Plus size={14} strokeWidth={1.5} />
                            New rule
                        </button>
                        <button type="button" className="cg-btn cg-btn--ghost cg-btn--sm">
                            <Download size={14} strokeWidth={1.5} />
                            Export CSV
                        </button>
                        <button type="button" className="cg-btn cg-btn--primary cg-btn--sm">
                            Settle batch
                        </button>
                    </div>
                </div>

                {/* KPI strip */}
                <div className="cgo-kpis">
                    {kpis.map((kpi) => (
                        <div className="cgo-kpi" key={kpi.label}>
                            <div className="cgo-kpi-label">{kpi.label}</div>
                            <div className={`cgo-kpi-num${kpi.brass ? ' brass' : ''}`}>{kpi.value}</div>
                            <div className="cgo-kpi-foot">
                                {kpi.delta ? (
                                    <span className={kpi.delta.sign === 'pos' ? 'cgo-delta-pos' : 'cgo-delta-neg'}>
                                        {kpi.delta.sign === 'pos' ? '▲' : '▼'} {kpi.delta.text}
                                    </span>
                                ) : (
                                    <span className="cgo-meta">{kpi.meta}</span>
                                )}
                                {kpi.spark ? (
                                    <div className="cgo-spark">
                                        {kpi.spark.map((h, i) => (
                                            <span key={i} style={{ height: `${h}%` }} />
                                        ))}
                                    </div>
                                ) : kpi.delta && kpi.meta ? (
                                    <span className="cgo-meta">{kpi.meta}</span>
                                ) : null}
                            </div>
                        </div>
                    ))}
                </div>

                {/* Filter bar */}
                <div className="cgo-filterbar">
                    {filters.counts.map((c) => (
                        <button
                            key={c.value}
                            type="button"
                            className={`cgo-chip${filters.active === c.value ? ' active' : ''}`}
                            onClick={() => setFilter(c.value)}
                        >
                            {c.label}{' '}
                            <span className={`cgo-chip-count${c.danger ? ' danger' : ''}`}>
                                {c.count.toLocaleString()}
                            </span>
                        </button>
                    ))}
                    <div className="cgo-filter-divider" />
                    <button type="button" className="cgo-chip">Sport: {filters.sport} ▾</button>
                    <button type="button" className="cgo-chip">{filters.stakeRange} ▾</button>
                    <button type="button" className="cgo-chip">{filters.timeRange} ▾</button>
                    <div className="cgo-right">
                        <span className="cgo-sort-label">Sort</span>
                        <button type="button" className="cgo-chip">{filters.sort} ▾</button>
                    </div>
                </div>

                {/* Wagers table */}
                <div className="cgo-table-wrap">
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ width: 130 }}>Time</th>
                                <th>Player</th>
                                <th>Event &amp; market</th>
                                <th>Selection</th>
                                <th className="cgo-r">Stake</th>
                                <th className="cgo-r">Odds</th>
                                <th className="cgo-r">Potential</th>
                                <th>Status</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {wagers.map((w) => {
                                const pill = statusPill(w.status, w.legs);
                                const flagged = isFlaggedRow(w.status);
                                return (
                                    <tr key={w.id} className={flagged ? 'flagged' : undefined}>
                                        <td className="cgo-ts">
                                            {w.placedAt}
                                            <span className="cgo-date">{w.placedDate}</span>
                                        </td>
                                        <td>
                                            <div className="cgo-user">
                                                <div className="cgo-av">{w.player.initials}</div>
                                                <div>
                                                    <div className="cgo-name">{w.player.name}</div>
                                                    <div className="cgo-uid">
                                                        {w.player.id}
                                                        {w.player.isVip ? ' · VIP' : ''}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div className="cgo-event">
                                                {w.event.name}
                                                <span className="cgo-market">{w.event.market}</span>
                                            </div>
                                        </td>
                                        <td>
                                            <span className="cgo-selection">
                                                <span className="cgo-pick">{w.selection.pick}</span>
                                                {w.selection.suffix ? <> · {w.selection.suffix}</> : null}
                                            </span>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-stake">
                                                <span className="cgo-ccy">{w.stake.currency}</span>
                                                {w.stake.amount}
                                            </span>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-odds">{w.odds}</span>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-payout">{w.potential}</span>
                                        </td>
                                        <td>
                                            <span className={pill.className}>{pill.label}</span>
                                        </td>
                                        <td className="cgo-r">
                                            <button type="button" className="cgo-row-action" aria-label="Row actions">
                                                <MoreHorizontal size={14} strokeWidth={1.5} />
                                            </button>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>

                    <div className="cgo-table-foot">
                        <div>
                            Showing{' '}
                            <b>
                                {pagination.from} – {pagination.to}
                            </b>{' '}
                            of <b className="cgo-mono">{pagination.total.toLocaleString()}</b> wagers
                        </div>
                        <Pager current={pagination.currentPage} last={pagination.lastPage} />
                    </div>
                </div>
            </div>
        </OperatorConsoleLayout>
    );
}

// Pagination — current page is brass-filled, neighbours visible, ellipsis
// for runs over the visible window. Matches reference HTML.
function Pager({ current, last }: { current: number; last: number }) {
    const pages: (number | 'gap')[] = [];
    const push = (p: number | 'gap') => pages.push(p);

    if (last <= 7) {
        for (let i = 1; i <= last; i++) push(i);
    } else {
        push(1);
        if (current > 4) push('gap');
        const start = Math.max(2, current - 1);
        const end = Math.min(last - 1, current + 1);
        for (let i = start; i <= end; i++) push(i);
        if (current < last - 3) push('gap');
        push(last);
    }

    return (
        <div className="cgo-pager">
            <button type="button" disabled={current === 1} aria-label="Previous">‹</button>
            {pages.map((p, i) =>
                p === 'gap' ? (
                    <button type="button" key={`gap-${i}`} disabled>…</button>
                ) : (
                    <button
                        type="button"
                        key={p}
                        className={p === current ? 'curr' : undefined}
                        aria-current={p === current ? 'page' : undefined}
                    >
                        {p}
                    </button>
                ),
            )}
            <button type="button" disabled={current === last} aria-label="Next">›</button>
        </div>
    );
}
