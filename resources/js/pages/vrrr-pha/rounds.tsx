// resources/js/pages/vrrr-pha/rounds.tsx
//
// Vrrr Pha round history (PRD §8.3), brass-on-ink like /fantasy/rounds.
// KPI strip (page totals) → tenant filter → rounds table → pager. Each
// row opens the round detail with its seed audit. The crash point is
// blank while a round is in play: the engine does not send it. Each row
// is in its tenant's currency; the page totals are one figure per currency.

import { KpiCard, formatCount } from '@/components/operator/kpi-card';
import UserLayout from '@/layouts/user-layout';
import { Head, router } from '@inertiajs/react';
import { sumByCurrency } from '@/lib/money';
import { useMemo, useState } from 'react';

import {
    ERROR_BOX, type HasCurrency, SELECT_RESET, TAP, TAP_SQUARE, formatDateTime, formatMultiplier, moneyDiff, num, rowCurrency, statePill,
    tenantLabel, useRowMoney,
} from './format';

interface Round extends HasCurrency {
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
    max_exposure?: string;
    /** 'path' rounds (Dorex): how the path ended and its highest price, null until it has ended. */
    path_end?: 'margin_call' | 'max_price' | 'round_end' | null;
    peak_price?: string | null;
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

/** The noun for one wager, from the console controller: bets for a crash game, tickets for the accumulator. */
interface Terms {
    one: string;
    many: string;
    paid_out_meta: string;
}
const BET_TERMS: Terms = { one: 'bet', many: 'bets', paid_out_meta: 'to cashed-out bets' };

const PATH_END: Record<'margin_call' | 'max_price' | 'round_end', string> = {
    margin_call: 'margin call',
    max_price: 'price ceiling',
    round_end: 'full length',
};

interface GameProps {
    name: string;
    base: string;
    terms?: Terms;
    /** 'crash' rounds have a crash point; 'ladder' rounds (Kulipi Kuna) do not; 'path' rounds (Dorex) end and peak. Defaults to 'crash'. */
    kind?: 'crash' | 'ladder' | 'path';
}

interface Props {
    game?: GameProps;
    rounds: Round[];
    tenants: TenantOption[];
    tenantNames: Record<string, string>;
    filters: Filters;
    error: string | null;
}

export default function Rounds({ game = { name: 'Vrrr Pha', base: '/vrrr-pha', terms: BET_TERMS }, rounds = [], tenants = [], tenantNames = {}, filters, error }: Props) {
    const [tenantUuid, setTenantUuid] = useState<string | null>(filters?.tenant_uuid ?? null);

    const applyFilters = (next: { tenant_uuid?: string | null; page?: number }) => {
        const params: Record<string, string | number> = {};
        const nextTenant = next.tenant_uuid !== undefined ? next.tenant_uuid : tenantUuid;
        if (nextTenant) params.tenant_uuid = nextTenant;
        if (next.page && next.page > 1) params.page = next.page;
        router.get(`${game.base}/rounds`, params, { preserveState: true, preserveScroll: true });
    };

    const isEmpty = rounds.length === 0;
    const crash = (game.kind ?? 'crash') === 'crash';
    const path = game.kind === 'path';
    const extraCol = crash || path;
    const page = filters?.page ?? 1;
    const perPage = filters?.per_page ?? 25;
    const total = filters?.total ?? null;
    const hasNext = total === null ? rounds.length === perPage : page * perPage < total;

    // Page totals: what is on screen under the active filter, not platform history.
    // Money is one figure per currency: tenants in different currencies are never added together.
    const money = useRowMoney();
    const totals = useMemo(
        () => ({
            bets: rounds.reduce((s, r) => s + (r.bet_count || 0), 0),
            byCurrency: sumByCurrency(rounds, rowCurrency, ['wagered', 'paid_out', 'ggr'] as const, (r, f) =>
                f === 'wagered' ? r.total_wagered : f === 'paid_out' ? r.total_paid_out : moneyDiff(r.total_wagered, r.total_paid_out),
            ),
        }),
        [rounds],
    );

    return (
        <UserLayout title={`${game.name} rounds`}>
            <Head title={`${game.name} rounds · Admin`} />
            <div className="cgo-page">
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">{game.name}</div>
                        <h1 className="cgo-title">Rounds</h1>
                        <div className="cgo-subtitle">
                            {crash
                                ? 'Round history — crash points, bets, and the seed audit for every settled round.'
                                : path
                                  ? 'Round history — how each path ended, its peak, and the path audit for every ended round.'
                                  : `Round history — the ${(game.terms ?? BET_TERMS).many} placed in every round.`}
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
                    <KpiCard label="Wagered" value={money.totals(totals.byCurrency, 'wagered')} meta="staked" />
                    <KpiCard label="Paid out" value={money.totals(totals.byCurrency, 'paid_out')} meta={(game.terms ?? BET_TERMS).paid_out_meta} />
                    <KpiCard label="GGR" value={money.totals(totals.byCurrency, 'ggr')} brass meta="wagered − paid out" />
                </div>

                <div className="cgo-filterbar">
                    <span className="cgo-sort-label">Tenant</span>
                    {/* Below sm the select takes the rest of the row; from sm it keeps its 220px floor. */}
                    <label className={`cgo-input max-sm:flex-1 sm:min-w-[220px] ${TAP}`}>
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
                            className={`cg-btn cg-btn--text cg-btn--sm ${TAP}`}
                            onClick={() => { setTenantUuid(null); applyFilters({ tenant_uuid: null, page: 1 }); }}
                        >
                            Clear
                        </button>
                    )}
                </div>

                <div className="cgo-table-wrap cgo-table-wrap--scroll" style={{ borderRadius: '0 0 8px 8px', borderTop: 0 }}>
                    {/* Phones scroll the table sideways inside the wrap rather than crushing its columns. */}
                    <table className="cgo-wagers max-md:min-w-[960px]">
                        <thead>
                            <tr>
                                <th style={{ width: 90 }}>Round</th>
                                <th style={{ width: 180 }}>Opened</th>
                                <th style={{ width: 160 }}>Tenant</th>
                                {crash && <th className="cgo-r" style={{ width: 100 }}>Crash</th>}
                                {path && <th style={{ width: 170 }}>Ended</th>}
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
                                    <td colSpan={extraCol ? 10 : 9} style={{ textAlign: 'center', color: 'var(--cg-fg-3)', padding: '32px 0' }}>
                                        No rounds match the current filters.
                                    </td>
                                </tr>
                            ) : (
                                rounds.map((r) => {
                                    const state = statePill(r.state);
                                    const ggr = moneyDiff(r.total_wagered, r.total_paid_out);
                                    return (
                                        <tr
                                            key={r.id}
                                            onClick={() => router.get(`${game.base}/rounds/${r.id}`)}
                                            style={{ cursor: 'pointer' }}
                                        >
                                            <td>
                                                <span className="cgo-name" style={{ fontFamily: 'var(--cg-mono)' }}>#{r.sequence}</span>
                                            </td>
                                            <td><span className="cgo-uid">{formatDateTime(r.opened_at)}</span></td>
                                            <td>{tenantLabel(r.tenant_uuid, tenantNames)}</td>
                                            {crash && (
                                                <td className="cgo-r">
                                                    <span className="cgo-odds" style={{ color: r.crash_point === null ? 'var(--cg-fg-3)' : undefined }}>
                                                        {r.crash_point === null ? 'in play' : formatMultiplier(r.crash_point)}
                                                    </span>
                                                </td>
                                            )}
                                            {path && (
                                                <td>
                                                    <span className="cgo-uid" style={{ color: r.path_end ? undefined : 'var(--cg-fg-3)' }}>
                                                        {r.path_end ? `${PATH_END[r.path_end]} · peak ${num(r.peak_price).toFixed(4)}` : 'in play'}
                                                    </span>
                                                </td>
                                            )}
                                            <td className="cgo-r"><span className="cgo-odds">{formatCount(r.bet_count)}</span></td>
                                            <td className="cgo-r">
                                                <span className="cgo-stake"><span className="cgo-ccy">{money.code(r.currency)}</span>{money.figure(r.total_wagered, r.currency)}</span>
                                            </td>
                                            <td className="cgo-r"><span className="cgo-payout">{money.figure(r.total_paid_out, r.currency)}</span></td>
                                            <td className="cgo-r">
                                                <span className="cgo-payout" style={{ color: num(ggr) < 0 ? 'var(--cg-neg)' : 'var(--cg-fg-1)' }}>
                                                    {money.figure(ggr, r.currency)}
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
                            <button type="button" className={TAP_SQUARE} disabled={page <= 1} onClick={() => applyFilters({ page: page - 1 })} aria-label="Previous">‹</button>
                            <button type="button" className={`curr ${TAP_SQUARE}`} disabled>{page}</button>
                            <button type="button" className={TAP_SQUARE} disabled={!hasNext} onClick={() => applyFilters({ page: page + 1 })} aria-label="Next">›</button>
                        </div>
                    </div>
                </div>
            </div>
        </UserLayout>
    );
}
