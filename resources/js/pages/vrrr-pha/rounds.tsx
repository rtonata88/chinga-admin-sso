// resources/js/pages/vrrr-pha/rounds.tsx
//
// Vrrr Pha round history (PRD §8.3), brass-on-ink like /fantasy/rounds.
// KPI strip (page totals) → tenant filter → rounds table → pager. Each
// row opens the round detail with its seed audit. The crash point is
// blank while a round is in play: the engine does not send it.

import { KpiCard, formatCount, formatCurrencyCompact } from '@/components/operator/kpi-card';
import UserLayout from '@/layouts/user-layout';
import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import {
    ERROR_BOX, SELECT_RESET, formatDateTime, formatMultiplier, formatNAD, num, statePill, tenantLabel,
} from './format';

interface Round {
    id: number;
    sequence: number;
    tenant_uuid: string;
    state: string;
    crash_point: string | null;
    commitment: string;
    opened_at: string;
    pulling_at: string | null;
    crashed_at: string | null;
    settled_at: string | null;
    bet_count: number;
    total_wagered: string;
    total_paid_out: string;
    max_exposure: string;
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
    total: number | null;
}

interface Props {
    rounds: Round[];
    tenants: TenantOption[];
    tenantNames: Record<string, string>;
    filters: Filters;
    error: string | null;
}

export default function Rounds({ rounds = [], tenants = [], tenantNames = {}, filters, error }: Props) {
    const [tenantUuid, setTenantUuid] = useState<string | null>(filters?.tenant_uuid ?? null);

    const applyFilters = (next: { tenant_uuid?: string | null; page?: number }) => {
        const params: Record<string, string | number> = {};
        const nextTenant = next.tenant_uuid !== undefined ? next.tenant_uuid : tenantUuid;
        if (nextTenant) params.tenant_uuid = nextTenant;
        if (next.page && next.page > 1) params.page = next.page;
        router.get('/vrrr-pha/rounds', params, { preserveState: true, preserveScroll: true });
    };

    const isEmpty = rounds.length === 0;
    const page = filters?.page ?? 1;
    const perPage = filters?.per_page ?? 25;
    const total = filters?.total ?? null;
    const hasNext = total === null ? rounds.length === perPage : page * perPage < total;

    // Page totals: what is on screen under the active filter, not platform history.
    const totals = useMemo(() => {
        let bets = 0;
        let wagered = 0;
        let paidOut = 0;
        for (const r of rounds) {
            bets += r.bet_count || 0;
            wagered += num(r.total_wagered);
            paidOut += num(r.total_paid_out);
        }
        return { bets, wagered, paidOut, ggr: wagered - paidOut };
    }, [rounds]);

    return (
        <UserLayout title="Vrrr Pha rounds">
            <Head title="Vrrr Pha rounds · Admin" />
            <div className="cgo-page">
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Vrrr Pha</div>
                        <h1 className="cgo-title">Rounds</h1>
                        <div className="cgo-subtitle">
                            Round history — crash points, bets, and the seed audit for every settled round.
                        </div>
                    </div>
                </div>

                {error && (
                    <div style={ERROR_BOX}>
                        <strong style={{ color: 'var(--cg-neg)' }}>Error:</strong> {error}
                    </div>
                )}

                <div className="cgo-kpis cgo-kpis--5">
                    <KpiCard
                        label="Rounds shown"
                        value={formatCount(rounds.length)}
                        meta={total !== null ? `of ${formatCount(total)} · page ${page}` : `page ${page}`}
                    />
                    <KpiCard label="Bets" value={formatCount(totals.bets)} meta="across visible rounds" />
                    <KpiCard label="Wagered" value={formatCurrencyCompact(totals.wagered)} meta="staked" />
                    <KpiCard label="Paid out" value={formatCurrencyCompact(totals.paidOut)} meta="to cashed-out bets" />
                    <KpiCard label="GGR" value={formatCurrencyCompact(totals.ggr)} brass meta="wagered − paid out" />
                </div>

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
                            style={SELECT_RESET}
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

                <div className="cgo-table-wrap cgo-table-wrap--scroll" style={{ borderRadius: '0 0 8px 8px', borderTop: 0 }}>
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ width: 90 }}>Round</th>
                                <th style={{ width: 180 }}>Opened</th>
                                <th style={{ width: 160 }}>Tenant</th>
                                <th className="cgo-r" style={{ width: 100 }}>Crash</th>
                                <th className="cgo-r" style={{ width: 70 }}>Bets</th>
                                <th className="cgo-r" style={{ width: 130 }}>Wagered</th>
                                <th className="cgo-r" style={{ width: 130 }}>Paid out</th>
                                <th className="cgo-r" style={{ width: 130 }}>GGR</th>
                                <th style={{ width: 110 }}>State</th>
                                <th style={{ width: 40 }} />
                            </tr>
                        </thead>
                        <tbody>
                            {isEmpty ? (
                                <tr>
                                    <td colSpan={10} style={{ textAlign: 'center', color: 'var(--cg-fg-3)', padding: '32px 0' }}>
                                        No rounds match the current filters.
                                    </td>
                                </tr>
                            ) : (
                                rounds.map((r) => {
                                    const state = statePill(r.state);
                                    const wagered = num(r.total_wagered);
                                    const paidOut = num(r.total_paid_out);
                                    const ggr = wagered - paidOut;
                                    return (
                                        <tr
                                            key={r.id}
                                            onClick={() => router.get(`/vrrr-pha/rounds/${r.id}`)}
                                            style={{ cursor: 'pointer' }}
                                        >
                                            <td>
                                                <span className="cgo-name" style={{ fontFamily: 'var(--cg-mono)' }}>#{r.sequence}</span>
                                            </td>
                                            <td><span className="cgo-uid">{formatDateTime(r.opened_at)}</span></td>
                                            <td>{tenantLabel(r.tenant_uuid, tenantNames)}</td>
                                            <td className="cgo-r">
                                                <span className="cgo-odds" style={{ color: r.crash_point === null ? 'var(--cg-fg-3)' : undefined }}>
                                                    {r.crash_point === null ? 'in play' : formatMultiplier(r.crash_point)}
                                                </span>
                                            </td>
                                            <td className="cgo-r"><span className="cgo-odds">{formatCount(r.bet_count)}</span></td>
                                            <td className="cgo-r">
                                                <span className="cgo-stake"><span className="cgo-ccy">NAD</span>{formatNAD(wagered)}</span>
                                            </td>
                                            <td className="cgo-r"><span className="cgo-payout">{formatNAD(paidOut)}</span></td>
                                            <td className="cgo-r">
                                                <span className="cgo-payout" style={{ color: ggr < 0 ? 'var(--cg-neg)' : 'var(--cg-fg-1)' }}>
                                                    {formatNAD(ggr)}
                                                </span>
                                            </td>
                                            <td><span className={`cgo-pill ${state.pill}`}>{state.label}</span></td>
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

                    <div className="cgo-table-foot">
                        <span>
                            {isEmpty ? 'No rounds' : (
                                <>
                                    Page <b className="cgo-mono">{page}</b> · Showing <b className="cgo-mono">{rounds.length}</b> rounds
                                </>
                            )}
                        </span>
                        <div className="cgo-pager">
                            <button type="button" disabled={page <= 1} onClick={() => applyFilters({ page: page - 1 })} aria-label="Previous">‹</button>
                            <button type="button" className="curr" disabled>{page}</button>
                            <button type="button" disabled={!hasNext} onClick={() => applyFilters({ page: page + 1 })} aria-label="Next">›</button>
                        </div>
                    </div>
                </div>
            </div>
        </UserLayout>
    );
}
