// resources/js/pages/admin/revenue.tsx
//
// Tenant revenue, brass-on-ink to match /tenant-overview. Pulls from
// `tenant_revenue_records`, which is populated by the daily
// `revenue:calculate` artisan job (cron at 02:00). Default range is
// year-to-date — narrower windows often look empty because the job
// only writes a row per closed period, not in real time.

import { KpiCard, formatCurrencyCompact } from '@/components/operator/kpi-card';
import UserLayout from '@/layouts/user-layout';
import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface RevenueTotals {
    total_bets: number;
    total_wins: number;
    gross_gaming_revenue: number;
    chinga_share: number;
    tenant_share: number;
}

interface GameRevenue {
    game_id: number;
    game: { uuid: string; name: string } | null;
    total_bets: number;
    total_wins: number;
    gross_gaming_revenue: number;
    tenant_share: number;
}

interface RevenueRecord {
    id: number;
    game: { uuid: string; name: string } | null;
    period_type: string;
    period_start: string;
    period_end: string;
    total_bets: number;
    total_wins: number;
    gross_gaming_revenue: number;
    chinga_share: number;
    tenant_share: number;
    status: string;
}

function formatNAD(value: number | null | undefined): string {
    return Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function statusPill(status: string): string {
    switch (status) {
        case 'confirmed': return 'settled';
        case 'pending': return 'pending';
        case 'paid': return 'live';
        default: return 'void';
    }
}

const DATE_FMT = new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });

function formatDate(iso: string): string {
    return DATE_FMT.format(new Date(iso));
}

function todayIso(): string {
    return new Date().toISOString().slice(0, 10);
}

function yearStartIso(): string {
    const d = new Date();
    return `${d.getFullYear()}-01-01`;
}

export default function TenantRevenue() {
    const [totals, setTotals] = useState<RevenueTotals | null>(null);
    const [perGame, setPerGame] = useState<GameRevenue[]>([]);
    const [records, setRecords] = useState<RevenueRecord[]>([]);
    const [loading, setLoading] = useState(true);
    const [recordsLoading, setRecordsLoading] = useState(true);
    const [from, setFrom] = useState(yearStartIso());
    const [to, setTo] = useState(todayIso());

    const fetchSummary = async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams();
            if (from) params.append('from', from);
            if (to) params.append('to', to);

            const res = await fetch(`/api/v1/admin/revenue/summary?${params}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await res.json();
            if (data.success) {
                setTotals(data.data.totals);
                setPerGame(data.data.per_game || []);
            }
        } catch (error) {
            console.error('Failed to fetch revenue summary:', error);
        } finally {
            setLoading(false);
        }
    };

    const fetchRecords = async () => {
        setRecordsLoading(true);
        try {
            const params = new URLSearchParams();
            if (from) params.append('from', from);
            if (to) params.append('to', to);

            const res = await fetch(`/api/v1/admin/revenue?${params}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await res.json();
            if (data.success) {
                setRecords(data.data || []);
            }
        } catch (error) {
            console.error('Failed to fetch revenue records:', error);
        } finally {
            setRecordsLoading(false);
        }
    };

    useEffect(() => {
        fetchSummary();
        fetchRecords();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const applyFilter = () => {
        fetchSummary();
        fetchRecords();
    };

    const empty = !loading && (!totals || (totals.gross_gaming_revenue === 0 && records.length === 0));

    return (
        <UserLayout title="Revenue">
            <Head title="Revenue · Admin" />

            <div className="cgo-page">
                {/* Page header */}
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Admin</div>
                        <h1 className="cgo-title">Revenue</h1>
                        <div className="cgo-subtitle">
                            Summary KPIs are live. Records below are settled daily by the{' '}
                            <code style={{ fontFamily: 'var(--cg-mono)', color: 'var(--cg-fg-2)' }}>
                                revenue:calculate
                            </code>
                            {' '}job and represent immutable closed-period statements.
                        </div>
                    </div>
                    <div style={{ display: 'flex', gap: 8 }}>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={applyFilter}
                        >
                            Refresh
                        </button>
                    </div>
                </div>

                {/* KPI strip · 5-up. */}
                <div className="cgo-kpis cgo-kpis--5">
                    <KpiCard
                        label="Total bets"
                        value={totals ? formatCurrencyCompact(totals.total_bets) : '—'}
                        meta="staked"
                    />
                    <KpiCard
                        label="Total wins"
                        value={totals ? formatCurrencyCompact(totals.total_wins) : '—'}
                        meta="paid out"
                    />
                    <KpiCard
                        label="Gross Gaming Revenue"
                        value={totals ? formatCurrencyCompact(totals.gross_gaming_revenue) : '—'}
                        meta="bets − wins"
                    />
                    <KpiCard
                        label="Your share"
                        value={totals ? formatCurrencyCompact(totals.tenant_share) : '—'}
                        brass
                        meta="after split"
                    />
                    <KpiCard
                        label="Platform share"
                        value={totals ? formatCurrencyCompact(totals.chinga_share) : '—'}
                        meta="retained by platform"
                    />
                </div>

                {/* Filter bar — date range. */}
                <div className="cgo-filterbar">
                    <span className="cgo-sort-label">Period</span>
                    <label className="cgo-input" style={{ minWidth: 150 }}>
                        <input
                            type="date"
                            value={from}
                            onChange={(e) => setFrom(e.target.value)}
                            placeholder="From"
                            style={{ minWidth: 130 }}
                        />
                    </label>
                    <span style={{ color: 'var(--cg-fg-3)', fontSize: 12 }}>→</span>
                    <label className="cgo-input" style={{ minWidth: 150 }}>
                        <input
                            type="date"
                            value={to}
                            onChange={(e) => setTo(e.target.value)}
                            placeholder="To"
                            style={{ minWidth: 130 }}
                        />
                    </label>
                    <button
                        type="button"
                        className="cg-btn cg-btn--ghost cg-btn--sm"
                        onClick={applyFilter}
                    >
                        Apply
                    </button>
                    <div className="cgo-right">
                        <button
                            type="button"
                            className="cg-btn cg-btn--text cg-btn--sm"
                            onClick={() => { setFrom(yearStartIso()); setTo(todayIso()); setTimeout(applyFilter, 0); }}
                        >
                            Year-to-date
                        </button>
                        <button
                            type="button"
                            className="cg-btn cg-btn--text cg-btn--sm"
                            onClick={() => {
                                const d = new Date();
                                const start = new Date(d.getFullYear(), d.getMonth(), 1).toISOString().slice(0, 10);
                                setFrom(start); setTo(todayIso()); setTimeout(applyFilter, 0);
                            }}
                        >
                            This month
                        </button>
                    </div>
                </div>

                {/* Empty-state hint when nothing matches. */}
                {empty && (
                    <div
                        style={{
                            background: 'var(--cg-ink-card)',
                            border: '1px solid var(--cg-rule)',
                            borderTop: 0,
                            borderRadius: '0 0 8px 8px',
                            padding: '32px 24px',
                            textAlign: 'center',
                            color: 'var(--cg-fg-3)',
                            fontSize: 13,
                            marginBottom: 24,
                        }}
                    >
                        <div style={{ marginBottom: 6 }}>No betting activity in this period.</div>
                        <div style={{ fontSize: 11 }}>
                            Summary KPIs are computed live from bets — they're zero because no
                            wagers were placed in this window. Closed-period record rows below
                            are written daily by the{' '}
                            <code style={{ fontFamily: 'var(--cg-mono)' }}>revenue:calculate</code> job.
                        </div>
                    </div>
                )}

                {/* Revenue by Game */}
                {perGame.length > 0 && (
                    <>
                        <div
                            className="cgo-table-bar"
                            style={{
                                borderRadius: '8px 8px 0 0',
                                borderTop: '1px solid var(--cg-rule)',
                                borderLeft: '1px solid var(--cg-rule)',
                                borderRight: '1px solid var(--cg-rule)',
                                marginTop: 0,
                            }}
                        >
                            <div className="cgo-table-bar-title">Revenue by game</div>
                        </div>
                        <div
                            className="cgo-table-wrap"
                            style={{ borderRadius: '0 0 8px 8px', marginBottom: 24 }}
                        >
                            <table className="cgo-wagers">
                                <thead>
                                    <tr>
                                        <th style={{ minWidth: 200 }}>Game</th>
                                        <th className="cgo-r" style={{ width: 140 }}>Total bets</th>
                                        <th className="cgo-r" style={{ width: 140 }}>Total wins</th>
                                        <th className="cgo-r" style={{ width: 140 }}>GGR</th>
                                        <th className="cgo-r" style={{ width: 140 }}>Your share</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {perGame.map((row) => (
                                        <tr key={row.game_id}>
                                            <td>
                                                <span className="cgo-name">{row.game?.name || 'Unknown'}</span>
                                            </td>
                                            <td className="cgo-r">
                                                <span className="cgo-stake">
                                                    <span className="cgo-ccy">NAD</span>
                                                    {formatNAD(row.total_bets)}
                                                </span>
                                            </td>
                                            <td className="cgo-r">
                                                <span className="cgo-payout">{formatNAD(row.total_wins)}</span>
                                            </td>
                                            <td className="cgo-r">
                                                <span className="cgo-payout">{formatNAD(row.gross_gaming_revenue)}</span>
                                            </td>
                                            <td className="cgo-r">
                                                <span className="cgo-payout" style={{ color: 'var(--cg-brass-hi)' }}>
                                                    {formatNAD(row.tenant_share)}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}

                {/* Revenue records */}
                <div
                    className="cgo-table-bar"
                    style={{
                        borderRadius: '8px 8px 0 0',
                        borderTop: '1px solid var(--cg-rule)',
                        borderLeft: '1px solid var(--cg-rule)',
                        borderRight: '1px solid var(--cg-rule)',
                        marginTop: 0,
                    }}
                >
                    <div className="cgo-table-bar-title">Revenue records</div>
                </div>
                <div
                    className="cgo-table-wrap cgo-table-wrap--scroll"
                    style={{ borderRadius: '0 0 8px 8px', marginBottom: 24 }}
                >
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ minWidth: 200 }}>Period</th>
                                <th style={{ minWidth: 180 }}>Game</th>
                                <th className="cgo-r" style={{ width: 130 }}>Bets</th>
                                <th className="cgo-r" style={{ width: 130 }}>Wins</th>
                                <th className="cgo-r" style={{ width: 130 }}>GGR</th>
                                <th className="cgo-r" style={{ width: 130 }}>Your share</th>
                                <th style={{ width: 100 }}>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {recordsLoading ? (
                                <tr>
                                    <td colSpan={7} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        Loading…
                                    </td>
                                </tr>
                            ) : records.length === 0 ? (
                                <tr>
                                    <td colSpan={7} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        No revenue records in this period.
                                    </td>
                                </tr>
                            ) : (
                                records.map((r) => (
                                    <tr key={r.id}>
                                        <td>
                                            <div className="cgo-name">
                                                {formatDate(r.period_start)} – {formatDate(r.period_end)}
                                            </div>
                                            <div className="cgo-uid" style={{ textTransform: 'capitalize' }}>
                                                {r.period_type}
                                            </div>
                                        </td>
                                        <td>
                                            <span style={{ fontSize: 12, color: 'var(--cg-fg-2)' }}>
                                                {r.game?.name || 'Unknown'}
                                            </span>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-stake">
                                                <span className="cgo-ccy">NAD</span>
                                                {formatNAD(r.total_bets)}
                                            </span>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-payout">{formatNAD(r.total_wins)}</span>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-payout">{formatNAD(r.gross_gaming_revenue)}</span>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-payout" style={{ color: 'var(--cg-brass-hi)' }}>
                                                {formatNAD(r.tenant_share)}
                                            </span>
                                        </td>
                                        <td>
                                            <span className={`cgo-pill ${statusPill(r.status)}`}>
                                                {r.status}
                                            </span>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </UserLayout>
    );
}
