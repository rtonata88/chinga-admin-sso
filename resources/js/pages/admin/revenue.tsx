// resources/js/pages/admin/revenue.tsx
//
// Tenant revenue, brass-on-ink to match /tenant-overview. Pulls from
// `tenant_revenue_records`, which is populated by the daily
// `revenue:calculate` artisan job (cron at 02:00). Default range is
// year-to-date — narrower windows often look empty because the job
// only writes a row per closed period, not in real time.
//
// Money is never added across currencies: the summary is one figure per
// currency (a tenant admin's is exactly one, their tenant's), the by-game
// table has one row per (game, currency), and each record shows in its
// tenant's currency.

import { KpiCard } from '@/components/operator/kpi-card';
import { MoneyFigure } from '@/components/operator/money-figure';
import UserLayout from '@/layouts/user-layout';
import { type Amount, type CurrencyTotal, formatTotals, toCents, useCurrencies } from '@/lib/money';
import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type TotalField = 'total_bets' | 'total_wins' | 'gross_gaming_revenue' | 'chinga_share' | 'tenant_share';

/** One entry per currency. */
type RevenueTotals = CurrencyTotal<TotalField>[];

interface GameRevenue {
    game_id: number;
    game: { uuid: string; name: string } | null;
    /** null on a platform error row for a game that could not be reached. */
    currency: string | null;
    total_bets: Amount;
    total_wins: Amount;
    gross_gaming_revenue: Amount;
    tenant_share: Amount;
    error?: string | null;
}

interface RevenueRecord {
    id: number;
    game: { uuid: string; name: string } | null;
    /** The record's tenant's currency. */
    currency: string | null;
    period_type: string;
    period_start: string;
    period_end: string;
    total_bets: Amount;
    total_wins: Amount;
    gross_gaming_revenue: Amount;
    chinga_share: Amount;
    tenant_share: Amount;
    status: string;
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
    const profileOf = useCurrencies();
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

    const empty =
        !loading && (!totals || (totals.every((t) => toCents(t.gross_gaming_revenue) === 0) && records.length === 0));
    const total = (field: TotalField) => (totals ? formatTotals(totals, field, profileOf, true) : '—');

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
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm max-sm:min-h-10"
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
                        value={total('total_bets')}
                        meta="staked"
                    />
                    <KpiCard
                        label="Total wins"
                        value={total('total_wins')}
                        meta="paid out"
                    />
                    <KpiCard
                        label="Gross Gaming Revenue"
                        value={total('gross_gaming_revenue')}
                        meta="bets − wins"
                    />
                    <KpiCard
                        label="Your share"
                        value={total('tenant_share')}
                        brass
                        meta="after split"
                    />
                    <KpiCard
                        label="Platform share"
                        value={total('chinga_share')}
                        meta="retained by platform"
                    />
                </div>

                {/* Filter bar — date range. */}
                <div className="cgo-filterbar">
                    <span className="cgo-sort-label">Period</span>
                    <label className="cgo-input max-sm:min-h-10" style={{ minWidth: 150 }}>
                        <input
                            type="date"
                            value={from}
                            onChange={(e) => setFrom(e.target.value)}
                            placeholder="From"
                            style={{ minWidth: 130 }}
                        />
                    </label>
                    <span style={{ color: 'var(--cg-fg-3)', fontSize: 12 }}>→</span>
                    <label className="cgo-input max-sm:min-h-10" style={{ minWidth: 150 }}>
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
                        className="cg-btn cg-btn--ghost cg-btn--sm max-sm:min-h-10"
                        onClick={applyFilter}
                    >
                        Apply
                    </button>
                    <div className="cgo-right flex-wrap">
                        <button
                            type="button"
                            className="cg-btn cg-btn--text cg-btn--sm max-sm:min-h-10"
                            onClick={() => { setFrom(yearStartIso()); setTo(todayIso()); setTimeout(applyFilter, 0); }}
                        >
                            Year-to-date
                        </button>
                        <button
                            type="button"
                            className="cg-btn cg-btn--text cg-btn--sm max-sm:min-h-10"
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
                                    {perGame.map((row) => {
                                        const p = profileOf(row.currency);
                                        return (
                                        <tr key={`${row.game_id}:${row.currency ?? ''}`}>
                                            <td>
                                                <span className="cgo-name">{row.game?.name || 'Unknown'}</span>
                                            </td>
                                            <td className="cgo-r">
                                                <span className="cgo-stake">
                                                    <MoneyFigure value={row.total_bets} profile={p} />
                                                </span>
                                            </td>
                                            <td className="cgo-r">
                                                <span className="cgo-payout"><MoneyFigure value={row.total_wins} profile={p} code={false} /></span>
                                            </td>
                                            <td className="cgo-r">
                                                <span className="cgo-payout"><MoneyFigure value={row.gross_gaming_revenue} profile={p} code={false} /></span>
                                            </td>
                                            <td className="cgo-r">
                                                <span className="cgo-payout" style={{ color: 'var(--cg-brass-hi)' }}>
                                                    <MoneyFigure value={row.tenant_share} profile={p} code={false} />
                                                </span>
                                            </td>
                                        </tr>
                                        );
                                    })}
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
                                records.map((r) => {
                                    const p = profileOf(r.currency);
                                    return (
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
                                                <MoneyFigure value={r.total_bets} profile={p} />
                                            </span>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-payout"><MoneyFigure value={r.total_wins} profile={p} code={false} /></span>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-payout"><MoneyFigure value={r.gross_gaming_revenue} profile={p} code={false} /></span>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-payout" style={{ color: 'var(--cg-brass-hi)' }}>
                                                <MoneyFigure value={r.tenant_share} profile={p} code={false} />
                                            </span>
                                        </td>
                                        <td>
                                            <span className={`cgo-pill ${statusPill(r.status)}`}>
                                                {r.status}
                                            </span>
                                        </td>
                                    </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </UserLayout>
    );
}
