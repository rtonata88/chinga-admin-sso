// resources/js/pages/vrrr-pha/rtp.tsx
//
// Realised RTP (RTS 3, PRD §8.3): paid out ÷ wagered over the window,
// against the theoretical RTP the rounds were drawn with (1 − house
// edge). Day rows below show how the figure moved. The engine does the
// arithmetic; this page only presents it.
//
// Money never adds across currencies. Wagered and paid out come per
// currency (the controller totals the engine's per-tenant figures); the day
// rows are summed across tenants by the engine, so their money shows only
// when the scope has one currency, and asks for a tenant otherwise. RTP is a
// ratio and stays.

import { KpiCard, formatCount } from '@/components/operator/kpi-card';
import type { CurrencyTotal } from '@/lib/money';
import UserLayout from '@/layouts/user-layout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { ERROR_BOX, PANEL, SELECT_RESET, TAP, formatRatioPct, num, useRowMoney } from './format';

interface Rtp {
    period: { from: string; to: string; tenant_uuid: string | null };
    bets_placed: number;
    depth_histogram?: { level: number; ladders: number }[];
    rounds: number;
    total_wagered: string;
    total_paid_out: string;
    realised_rtp: string | null;
    theoretical_rtp: string | null;
    house_edge: string | null;
    /** Standard error of the realised RTP, when the engine sends one (Dorex): drift is then judged at 3.5σ. */
    standard_error?: string | null;
}

// Engines differ slightly in what they send per day; a missing count shows as 0, never a crashed page.
interface Day {
    day: string;
    bets_placed?: number;
    active_players?: number;
    total_wagered: string;
    total_paid_out: string;
    ggr: string;
    real_ggr?: string;
    rounds?: number;
}

interface TenantOption {
    uuid: string;
    name: string;
    slug: string;
}

/** The noun for one wager, from the console controller: bets for a crash game, tickets for the accumulator. */
interface Terms {
    one: string;
    many: string;
    paid_out_meta: string;
}
const BET_TERMS: Terms = { one: 'bet', many: 'bets', paid_out_meta: 'to cashed-out bets' };

interface GameProps {
    name: string;
    base: string;
    terms?: Terms;
}

interface Props {
    game?: GameProps;
    rtp: Rtp | null;
    days: Day[];
    /** The scope's one currency; null when its tenants span several, and the day money is withheld. */
    currency?: string | null;
    /** Wagered, paid out and GGR per currency over the window. */
    totals?: CurrencyTotal<'total_wagered' | 'total_paid_out' | 'ggr'>[];
    tenants: TenantOption[];
    filters: { tenant_uuid: string | null; from: string; to: string };
    error: string | null;
}

const DAY_FMT = new Intl.DateTimeFormat('en-GB', { weekday: 'short', day: '2-digit', month: 'short' });

function formatDay(iso: string): string {
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? iso : DAY_FMT.format(d);
}

/**
 * Realised RTP swings on small samples; the deviation reads red only when the sample is large
 * enough to mean something. With a standard error from the engine, "large enough" is exact: red
 * past 3.5σ, whatever the count. Without one, the old rule of thumb: 1,000 bets and 3 points.
 */
function deviation(realised: number | null, theoretical: number | null, bets: number, se: number | null): { text: string; color: string } {
    if (realised === null || theoretical === null) return { text: 'no data', color: 'var(--cg-fg-3)' };
    const d = (realised - theoretical) * 100;
    const text = `${d >= 0 ? '+' : ''}${d.toFixed(2)} pts vs theoretical`;
    if (se !== null && se > 0) {
        const band = 3.5 * se * 100;
        const banded = `${text} · band ±${band.toFixed(2)}`;
        return { text: banded, color: Math.abs(d) > band ? 'var(--cg-neg)' : 'var(--cg-pos)' };
    }
    if (bets < 1000) return { text: `${text} · small sample`, color: 'var(--cg-fg-3)' };
    if (Math.abs(d) >= 3) return { text, color: 'var(--cg-neg)' };
    return { text, color: 'var(--cg-pos)' };
}

export default function RtpPage({ game = { name: 'Vrrr Pha', base: '/vrrr-pha', terms: BET_TERMS }, rtp, days = [], currency = null, totals = [], tenants = [], filters, error }: Props) {
    const money = useRowMoney();
    const mixed = !currency && totals.length > 1;
    const [tenantUuid, setTenantUuid] = useState<string | null>(filters?.tenant_uuid ?? null);
    const [from, setFrom] = useState(filters?.from ?? '');
    const [to, setTo] = useState(filters?.to ?? '');

    const apply = (next: { tenant_uuid?: string | null; from?: string; to?: string }) => {
        const params: Record<string, string> = {};
        const t = next.tenant_uuid !== undefined ? next.tenant_uuid : tenantUuid;
        const f = next.from ?? from;
        const u = next.to ?? to;
        if (t) params.tenant_uuid = t;
        if (f) params.from = f;
        if (u) params.to = u;
        router.get(`${game.base}/rtp`, params, { preserveState: true, preserveScroll: true });
    };

    const realised = rtp?.realised_rtp === null || rtp?.realised_rtp === undefined ? null : num(rtp.realised_rtp);
    const theoretical = rtp?.theoretical_rtp === null || rtp?.theoretical_rtp === undefined ? null : num(rtp.theoretical_rtp);
    const se = rtp?.standard_error === null || rtp?.standard_error === undefined ? null : num(rtp.standard_error);
    const dev = deviation(realised, theoretical, rtp?.bets_placed ?? 0, se);

    return (
        <UserLayout title={`${game.name} RTP`}>
            <Head title={`${game.name} RTP · Admin`} />
            <div className="cgo-page">
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">{game.name}</div>
                        <h1 className="cgo-title">Return to player</h1>
                        <div className="cgo-subtitle">
                            Realised RTP over the window against the theoretical figure the rounds were drawn with.
                        </div>
                    </div>
                </div>

                {error && (
                    <div style={ERROR_BOX}>
                        <strong style={{ color: 'var(--cg-neg)' }}>Error:</strong> {error}
                    </div>
                )}

                <div className="cgo-kpis cgo-kpis--5">
                    <KpiCard label="Realised RTP" value={formatRatioPct(realised)} brass meta={dev.text} />
                    <KpiCard
                        label="Theoretical RTP"
                        value={formatRatioPct(theoretical)}
                        meta={rtp?.house_edge ? `house edge ${formatRatioPct(rtp.house_edge)}` : 'from the latest round in scope'}
                    />
                    <KpiCard label="Wagered" value={money.totals(totals, 'total_wagered')} meta={mixed ? 'staked in window, per currency' : 'staked in window'} />
                    <KpiCard label="Paid out" value={money.totals(totals, 'total_paid_out')} meta={(game.terms ?? BET_TERMS).paid_out_meta} />
                    <KpiCard
                        label="Sample"
                        value={formatCount(rtp?.bets_placed ?? 0)}
                        meta={`bets over ${formatCount(rtp?.rounds ?? 0)} rounds`}
                    />
                </div>

                <div className="cgo-filterbar" style={{ flexWrap: 'wrap', gap: 10 }}>
                    <span className="cgo-sort-label max-sm:w-16">Tenant</span>
                    {/* Below sm each label + control pair takes a row of its own; from sm they keep their floors. */}
                    <label className={`cgo-input max-sm:grow max-sm:basis-[calc(100%-74px)] sm:min-w-[200px] ${TAP}`}>
                        <select
                            value={tenantUuid ?? ''}
                            onChange={(e) => {
                                const v = e.target.value || null;
                                setTenantUuid(v);
                                apply({ tenant_uuid: v });
                            }}
                            style={SELECT_RESET}
                        >
                            <option value="">All tenants</option>
                            {tenants.map((t) => (
                                <option key={t.uuid} value={t.uuid}>{t.name}</option>
                            ))}
                        </select>
                    </label>
                    <span className="cgo-sort-label max-sm:w-16">From</span>
                    <label className={`cgo-input max-sm:grow max-sm:basis-[calc(100%-74px)] sm:min-w-[150px] ${TAP}`}>
                        <input type="date" value={from} max={to || undefined} onChange={(e) => setFrom(e.target.value)} onBlur={() => apply({})} />
                    </label>
                    <span className="cgo-sort-label max-sm:w-16">To</span>
                    <label className={`cgo-input max-sm:grow max-sm:basis-[calc(100%-74px)] sm:min-w-[150px] ${TAP}`}>
                        <input type="date" value={to} min={from || undefined} onChange={(e) => setTo(e.target.value)} onBlur={() => apply({})} />
                    </label>
                    <button type="button" className={`cg-btn cg-btn--sm max-sm:w-full ${TAP}`} onClick={() => apply({})}>Apply</button>
                </div>

                <div className="cgo-table-wrap cgo-table-wrap--scroll" style={{ borderRadius: '0 0 8px 8px', borderTop: 0 }}>
                    {/* Phones scroll the table sideways inside the wrap rather than crushing its columns. */}
                    <table className="cgo-wagers max-md:min-w-[900px]">
                        <thead>
                            <tr>
                                <th style={{ width: 150 }}>Day</th>
                                <th className="cgo-r" style={{ width: 80 }}>Rounds</th>
                                <th className="cgo-r" style={{ width: 80 }}>Bets</th>
                                <th className="cgo-r" style={{ width: 80 }}>Players</th>
                                <th className="cgo-r" style={{ width: 130 }}>Wagered</th>
                                <th className="cgo-r" style={{ width: 130 }}>Paid out</th>
                                <th className="cgo-r" style={{ width: 130 }}>GGR</th>
                                <th className="cgo-r" style={{ width: 130 }}>Real GGR</th>
                                <th className="cgo-r" style={{ width: 100 }}>Day RTP</th>
                            </tr>
                        </thead>
                        <tbody>
                            {days.length === 0 ? (
                                <tr>
                                    <td colSpan={9} style={{ textAlign: 'center', color: 'var(--cg-fg-3)', padding: '32px 0' }}>
                                        No bets in this window.
                                    </td>
                                </tr>
                            ) : (
                                days.map((d) => {
                                    const wagered = num(d.total_wagered);
                                    const paidOut = num(d.total_paid_out);
                                    const dayRtp = wagered > 0 ? paidOut / wagered : null;
                                    return (
                                        <tr key={d.day}>
                                            <td><span className="cgo-uid">{formatDay(d.day)}</span></td>
                                            <td className="cgo-r"><span className="cgo-odds">{formatCount(d.rounds ?? 0)}</span></td>
                                            <td className="cgo-r"><span className="cgo-odds">{formatCount(d.bets_placed ?? 0)}</span></td>
                                            <td className="cgo-r"><span className="cgo-odds">{formatCount(d.active_players ?? 0)}</span></td>
                                            {currency ? (
                                                <>
                                                    <td className="cgo-r">
                                                        <span className="cgo-stake"><span className="cgo-ccy">{currency}</span>{money.figure(d.total_wagered, currency)}</span>
                                                    </td>
                                                    <td className="cgo-r"><span className="cgo-payout">{money.figure(d.total_paid_out, currency)}</span></td>
                                                    <td className="cgo-r">
                                                        <span className="cgo-payout" style={{ color: num(d.ggr) < 0 ? 'var(--cg-neg)' : 'var(--cg-fg-1)' }}>{money.figure(d.ggr, currency)}</span>
                                                    </td>
                                                    <td className="cgo-r"><span className="cgo-payout">{money.figure(d.real_ggr, currency)}</span></td>
                                                </>
                                            ) : (
                                                // Summed across currencies by the engine: no figure to show.
                                                [0, 1, 2, 3].map((i) => (
                                                    <td key={i} className="cgo-r" title="Tenants in more than one currency: pick a tenant">
                                                        <span className="cgo-payout" style={{ color: 'var(--cg-fg-3)' }}>—</span>
                                                    </td>
                                                ))
                                            )}
                                            <td className="cgo-r"><span className="cgo-odds">{formatRatioPct(dayRtp)}</span></td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                    <div className="cgo-table-foot">
                        <span>
                            Real GGR counts only the deposit-funded part of each stake; GGR counts every stake including re-staked winnings.
                            {!currency && days.length > 0 && ' The tenants here use more than one currency, so the daily money is not added up: pick a tenant to see it.'}
                        </span>
                    </div>
                </div>

                {rtp?.depth_histogram && rtp.depth_histogram.length > 0 && (
                    <div data-testid="depth-histogram" className="cgo-table-wrap" style={{ marginTop: 18, borderRadius: 8 }}>
                        <table className="cgo-wagers">
                            <thead>
                                <tr>
                                    <th style={{ width: 140 }}>Ended at level</th>
                                    <th className="cgo-r" style={{ width: 140 }}>{(game.terms ?? BET_TERMS).many}</th>
                                    <th className="cgo-r" style={{ width: 120 }}>Share</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(() => {
                                    const total = rtp.depth_histogram.reduce((s, d) => s + d.ladders, 0);
                                    return rtp.depth_histogram.map((d) => (
                                        <tr key={d.level}>
                                            <td><span className="cgo-name" style={{ fontFamily: 'var(--cg-mono)' }}>{d.level}</span></td>
                                            <td className="cgo-r"><span className="cgo-odds">{formatCount(d.ladders)}</span></td>
                                            <td className="cgo-r"><span className="cgo-odds">{total > 0 ? formatRatioPct(d.ladders / total) : '—'}</span></td>
                                        </tr>
                                    ));
                                })()}
                            </tbody>
                        </table>
                        <div className="cgo-table-foot"><span>How deep finished {(game.terms ?? BET_TERMS).many} went: the level each collected at or lost on.</span></div>
                    </div>
                )}
                <section style={{ ...PANEL, marginTop: 18, fontSize: 13, color: 'var(--cg-fg-2)', lineHeight: 1.5 }}>
                    <div className="cgo-eyebrow" style={{ marginBottom: 6 }}>Reading this page</div>
                    Realised RTP converges on the theoretical figure only over a large sample. A few hundred bets can sit several points either side of it without anything being wrong; the deviation reads red only once the window holds at least a thousand bets and sits three or more points away. A persistent gap on a large sample is the signal to audit rounds.
                </section>
            </div>
        </UserLayout>
    );
}
