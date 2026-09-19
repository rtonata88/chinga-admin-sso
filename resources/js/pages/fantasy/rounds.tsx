// resources/js/pages/fantasy/rounds.tsx
//
// Fantasy round history, brass-on-ink to match /tenant-overview.
// KPI strip (page totals) → tenant filter → rounds table → pager.
// Each row links to the round detail page; the page totals are
// derived from the visible rows so they reflect the active filter.

import { KpiCard, formatCount, formatCurrencyCompact } from '@/components/operator/kpi-card';
import UserLayout from '@/layouts/user-layout';
import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

interface Round {
    id: number;
    round_number: number;
    tenant_uuid: string | null;
    start_time: string;
    end_time: string | null;
    winning_team_ids: number[] | null;
    bet_count: number;
    total_wagered: string;
    total_paid_out: string;
}

interface TenantOption {
    uuid: string;
    name: string;
    slug: string;
}

interface Filters {
    tenant_uuid: string | null;
    page: number;
    per_page: number;
}

interface Props {
    rounds: Round[];
    tenants: TenantOption[];
    filters: Filters;
    error: string | null;
    lockedTenantUuid?: string | null;
    listHref?: string;
    detailHrefBase?: string;
}

const DATETIME_FMT = new Intl.DateTimeFormat('en-GB', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
});

function formatDateTime(iso: string): string {
    return DATETIME_FMT.format(new Date(iso));
}

function formatNAD(amount: string | number): string {
    const n = typeof amount === 'string' ? parseFloat(amount) : amount;
    return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function roundStatus(round: Round): { label: string; pill: string } {
    if (round.end_time && round.winning_team_ids && round.winning_team_ids.length > 0) {
        return { label: 'completed', pill: 'settled' };
    }
    if (round.end_time) {
        return { label: 'finished', pill: 'pending' };
    }
    return { label: 'in progress', pill: 'live' };
}

export default function Rounds({
    rounds = [],
    tenants = [],
    filters,
    error,
    lockedTenantUuid = null,
    listHref = '/fantasy/rounds',
    detailHrefBase = '/fantasy/rounds',
}: Props) {
    const [tenantUuid, setTenantUuid] = useState<string | null>(filters?.tenant_uuid ?? null);
    const tenantLocked = !!lockedTenantUuid;

    const applyFilters = (next: { tenant_uuid?: string | null; page?: number }) => {
        const params: Record<string, string | number> = {};
        const nextTenant = next.tenant_uuid !== undefined ? next.tenant_uuid : tenantUuid;
        if (nextTenant && !tenantLocked) params.tenant_uuid = nextTenant;
        if (next.page && next.page > 1) params.page = next.page;
        router.get(listHref, params, { preserveState: true, preserveScroll: true });
    };

    const isEmpty = !rounds || rounds.length === 0;
    const page = filters?.page ?? 1;
    const perPage = filters?.per_page ?? 25;
    const hasNext = rounds.length === perPage;

    // Page-level totals derived from the visible rounds. They reflect
    // the active filter (tenant scope, current page) — not platform-
    // wide history. Cheap and matches what the user is looking at.
    const totals = useMemo(() => {
        let bets = 0;
        let wagered = 0;
        let paidOut = 0;
        for (const r of rounds) {
            bets += r.bet_count || 0;
            wagered += parseFloat(r.total_wagered) || 0;
            paidOut += parseFloat(r.total_paid_out) || 0;
        }
        return { bets, wagered, paidOut, ggr: wagered - paidOut };
    }, [rounds]);

    return (
        <UserLayout title="Fantasy rounds">
            <Head title="Fantasy rounds · Admin" />

            <div className="cgo-page">
                {/* Page header */}
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Fantasy</div>
                        <h1 className="cgo-title">Rounds</h1>
                        <div className="cgo-subtitle">
                            Game round history — bets, results, and tenant attribution.
                        </div>
                    </div>
                </div>

                {error && (
                    <div
                        style={{
                            background: 'var(--cg-ink-card)',
                            border: '1px solid var(--cg-neg)',
                            borderRadius: 6,
                            padding: 14,
                            marginBottom: 18,
                            fontSize: 13,
                            color: 'var(--cg-fg-1)',
                        }}
                    >
                        <strong style={{ color: 'var(--cg-neg)' }}>Error:</strong>{' '}
                        {error}
                    </div>
                )}

                {/* KPI strip — page totals. */}
                <div className="cgo-kpis cgo-kpis--5">
                    <KpiCard
                        label="Rounds shown"
                        value={formatCount(rounds.length)}
                        meta={`page ${page}`}
                    />
                    <KpiCard
                        label="Total bets"
                        value={formatCount(totals.bets)}
                        meta="across visible rounds"
                    />
                    <KpiCard
                        label="Total wagered"
                        value={formatCurrencyCompact(totals.wagered)}
                        meta="staked"
                    />
                    <KpiCard
                        label="Total paid out"
                        value={formatCurrencyCompact(totals.paidOut)}
                        meta="to winners"
                    />
                    <KpiCard
                        label="GGR"
                        value={formatCurrencyCompact(totals.ggr)}
                        brass
                        meta="wagered − paid out"
                    />
                </div>

                {/* Filter bar — tenant select only when not locked. */}
                {!tenantLocked && (
                    <div className="cgo-filterbar">
                        <span className="cgo-sort-label">Tenant</span>
                        <label className="cgo-input" style={{ minWidth: 220 }}>
                            <select
                                value={tenantUuid ?? ''}
                                onChange={(e) => {
                                    const v = e.target.value || null;
                                    setTenantUuid(v);
                                    applyFilters({ tenant_uuid: v, page: 1 });
                                }}
                                style={{
                                    all: 'unset', flex: 1,
                                    color: 'inherit', font: 'inherit', cursor: 'pointer',
                                }}
                            >
                                <option value="">All tenants</option>
                                {tenants.map((t) => (
                                    <option key={t.uuid} value={t.uuid}>{t.name}</option>
                                ))}
                            </select>
                        </label>
                        {tenantUuid && (
                            <button
                                type="button"
                                className="cg-btn cg-btn--text cg-btn--sm"
                                onClick={() => { setTenantUuid(null); applyFilters({ tenant_uuid: null, page: 1 }); }}
                            >
                                Clear
                            </button>
                        )}
                    </div>
                )}

                {/* Rounds table */}
                <div
                    className="cgo-table-wrap cgo-table-wrap--scroll"
                    style={{ borderRadius: tenantLocked ? 8 : '0 0 8px 8px', borderTop: tenantLocked ? undefined : 0 }}
                >
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ width: 110 }}>Round</th>
                                <th style={{ width: 170 }}>Started</th>
                                <th className="cgo-r" style={{ width: 90 }}>Bets</th>
                                <th className="cgo-r" style={{ width: 130 }}>Wagered</th>
                                <th className="cgo-r" style={{ width: 130 }}>Paid out</th>
                                <th className="cgo-r" style={{ width: 130 }}>GGR</th>
                                <th style={{ width: 120 }}>Status</th>
                                <th style={{ width: 60 }} />
                            </tr>
                        </thead>
                        <tbody>
                            {isEmpty ? (
                                <tr>
                                    <td colSpan={8} style={{ textAlign: 'center', color: 'var(--cg-fg-3)', padding: '32px 0' }}>
                                        No rounds match the current filters.
                                    </td>
                                </tr>
                            ) : (
                                rounds.map((r) => {
                                    const status = roundStatus(r);
                                    const wagered = parseFloat(r.total_wagered) || 0;
                                    const paidOut = parseFloat(r.total_paid_out) || 0;
                                    const ggr = wagered - paidOut;
                                    return (
                                        <tr
                                            key={r.id}
                                            onClick={() => router.get(`${detailHrefBase}/${r.id}`)}
                                            style={{ cursor: 'pointer' }}
                                        >
                                            <td>
                                                <span className="cgo-name" style={{ fontFamily: 'var(--cg-mono)' }}>
                                                    #{r.round_number}
                                                </span>
                                            </td>
                                            <td>
                                                <span className="cgo-uid">{formatDateTime(r.start_time)}</span>
                                            </td>
                                            <td className="cgo-r">
                                                <span className="cgo-odds">{formatCount(r.bet_count)}</span>
                                            </td>
                                            <td className="cgo-r">
                                                <span className="cgo-stake">
                                                    <span className="cgo-ccy">NAD</span>
                                                    {formatNAD(wagered)}
                                                </span>
                                            </td>
                                            <td className="cgo-r">
                                                <span className="cgo-payout">
                                                    {formatNAD(paidOut)}
                                                </span>
                                            </td>
                                            <td className="cgo-r">
                                                <span
                                                    className="cgo-payout"
                                                    style={{ color: ggr < 0 ? 'var(--cg-neg)' : 'var(--cg-fg-1)' }}
                                                >
                                                    {formatNAD(ggr)}
                                                </span>
                                            </td>
                                            <td>
                                                <span className={`cgo-pill ${status.pill}`}>
                                                    {status.label}
                                                </span>
                                            </td>
                                            <td>
                                                <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
                                                    <span style={{ color: 'var(--cg-fg-3)' }}>›</span>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>

                    {/* Footer + pager */}
                    <div className="cgo-table-foot">
                        <span>
                            {isEmpty ? 'No rounds' : (
                                <>
                                    Page <b className="cgo-mono">{page}</b> ·{' '}
                                    Showing <b className="cgo-mono">{rounds.length}</b> rounds
                                </>
                            )}
                        </span>
                        <div className="cgo-pager">
                            <button
                                type="button"
                                disabled={page <= 1}
                                onClick={() => applyFilters({ page: page - 1 })}
                                aria-label="Previous"
                            >
                                ‹
                            </button>
                            <button type="button" className="curr" disabled>
                                {page}
                            </button>
                            <button
                                type="button"
                                disabled={!hasNext}
                                onClick={() => applyFilters({ page: page + 1 })}
                                aria-label="Next"
                            >
                                ›
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </UserLayout>
    );
}
