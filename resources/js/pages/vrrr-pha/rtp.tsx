// resources/js/pages/vrrr-pha/rtp.tsx
//
// Realised RTP (RTS 3, PRD §8.3): paid out ÷ wagered over the window,
// against the theoretical RTP the rounds were drawn with (1 − house
// edge). Day rows below show how the figure moved. The engine does the
// arithmetic; this page only presents it.

import { KpiCard, formatCount, formatCurrencyCompact } from '@/components/operator/kpi-card';
import UserLayout from '@/layouts/user-layout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { ERROR_BOX, PANEL, SELECT_RESET, formatNAD, formatRatioPct, num } from './format';

interface Rtp {
    period: { from: string; to: string; tenant_uuid: string | null };
    bets_placed: number;
    rounds: number;
    total_wagered: string;
    total_paid_out: string;
    realised_rtp: string | null;
    theoretical_rtp: string | null;
    house_edge: string | null;
}

interface Day {
    day: string;
    bets_placed: number;
    active_players: number;
    total_wagered: string;
    total_paid_out: string;
    ggr: string;
    real_ggr: string;
    rounds: number;
}

interface TenantOption {
    uuid: string;
    name: string;
    slug: string;
}

interface Props {
    rtp: Rtp | null;
    days: Day[];
    tenants: TenantOption[];
    filters: { tenant_uuid: string | null; from: string; to: string };
    error: string | null;
}

const DAY_FMT = new Intl.DateTimeFormat('en-GB', { weekday: 'short', day: '2-digit', month: 'short' });

function formatDay(iso: string): string {
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? iso : DAY_FMT.format(d);
}

/** Realised RTP swings on small samples; the deviation reads red only when the sample is large enough to mean something. */
function deviation(realised: number | null, theoretical: number | null, bets: number): { text: string; color: string } {
    if (realised === null || theoretical === null) return { text: 'no data', color: 'var(--cg-fg-3)' };
    const d = (realised - theoretical) * 100;
    const text = `${d >= 0 ? '+' : ''}${d.toFixed(2)} pts vs theoretical`;
    if (bets < 1000) return { text: `${text} · small sample`, color: 'var(--cg-fg-3)' };
    if (Math.abs(d) >= 3) return { text, color: 'var(--cg-neg)' };
    return { text, color: 'var(--cg-pos)' };
}

export default function RtpPage({ rtp, days = [], tenants = [], filters, error }: Props) {
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
        router.get('/vrrr-pha/rtp', params, { preserveState: true, preserveScroll: true });
    };

    const realised = rtp?.realised_rtp === null || rtp?.realised_rtp === undefined ? null : num(rtp.realised_rtp);
    const theoretical = rtp?.theoretical_rtp === null || rtp?.theoretical_rtp === undefined ? null : num(rtp.theoretical_rtp);
    const dev = deviation(realised, theoretical, rtp?.bets_placed ?? 0);

    return (
        <UserLayout title="Vrrr Pha RTP">
            <Head title="Vrrr Pha RTP · Admin" />
            <div className="cgo-page">
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Vrrr Pha</div>
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
                    <KpiCard label="Wagered" value={formatCurrencyCompact(num(rtp?.total_wagered))} meta="staked in window" />
                    <KpiCard label="Paid out" value={formatCurrencyCompact(num(rtp?.total_paid_out))} meta="to cashed-out bets" />
                    <KpiCard
                        label="Sample"
                        value={formatCount(rtp?.bets_placed ?? 0)}
                        meta={`bets over ${formatCount(rtp?.rounds ?? 0)} rounds`}
                    />
                </div>

                <div className="cgo-filterbar" style={{ flexWrap: 'wrap', gap: 10 }}>
                    <span className="cgo-sort-label">Tenant</span>
                    <label className="cgo-input" style={{ minWidth: 200 }}>
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
                    <span className="cgo-sort-label">From</span>
                    <label className="cgo-input" style={{ minWidth: 150 }}>
                        <input type="date" value={from} max={to || undefined} onChange={(e) => setFrom(e.target.value)} onBlur={() => apply({})} />
                    </label>
                    <span className="cgo-sort-label">To</span>
                    <label className="cgo-input" style={{ minWidth: 150 }}>
                        <input type="date" value={to} min={from || undefined} onChange={(e) => setTo(e.target.value)} onBlur={() => apply({})} />
                    </label>
                    <button type="button" className="cg-btn cg-btn--sm" onClick={() => apply({})}>Apply</button>
                </div>

                <div className="cgo-table-wrap cgo-table-wrap--scroll" style={{ borderRadius: '0 0 8px 8px', borderTop: 0 }}>
                    <table className="cgo-wagers">
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
                                    const ggr = num(d.ggr);
                                    return (
                                        <tr key={d.day}>
                                            <td><span className="cgo-uid">{formatDay(d.day)}</span></td>
                                            <td className="cgo-r"><span className="cgo-odds">{formatCount(d.rounds)}</span></td>
                                            <td className="cgo-r"><span className="cgo-odds">{formatCount(d.bets_placed)}</span></td>
                                            <td className="cgo-r"><span className="cgo-odds">{formatCount(d.active_players)}</span></td>
                                            <td className="cgo-r">
                                                <span className="cgo-stake"><span className="cgo-ccy">NAD</span>{formatNAD(wagered)}</span>
                                            </td>
                                            <td className="cgo-r"><span className="cgo-payout">{formatNAD(paidOut)}</span></td>
                                            <td className="cgo-r">
                                                <span className="cgo-payout" style={{ color: ggr < 0 ? 'var(--cg-neg)' : 'var(--cg-fg-1)' }}>{formatNAD(ggr)}</span>
                                            </td>
                                            <td className="cgo-r"><span className="cgo-payout">{formatNAD(d.real_ggr)}</span></td>
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
                        </span>
                    </div>
                </div>

                <section style={{ ...PANEL, marginTop: 18, fontSize: 13, color: 'var(--cg-fg-2)', lineHeight: 1.5 }}>
                    <div className="cgo-eyebrow" style={{ marginBottom: 6 }}>Reading this page</div>
                    Realised RTP converges on the theoretical figure only over a large sample. A few hundred bets can sit several points either side of it without anything being wrong; the deviation reads red only once the window holds at least a thousand bets and sits three or more points away. A persistent gap on a large sample is the signal to audit rounds.
                </section>
            </div>
        </UserLayout>
    );
}
