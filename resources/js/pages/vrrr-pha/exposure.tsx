// resources/js/pages/vrrr-pha/exposure.tsx
//
// Live exposure (PRD §8.5): every round the engine has not settled yet,
// with the stake committed against the tenant's per-round cap and the
// worst case against max win. Rows at 80% of the cap or more are
// flagged. The page refreshes itself every ten seconds while open.

import { KpiCard, formatCount, formatCurrencyCompact } from '@/components/operator/kpi-card';
import UserLayout from '@/layouts/user-layout';
import { Head, router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

import { ERROR_BOX, SELECT_RESET, formatDateTime, formatNAD, formatRatioPct, num, statePill, tenantLabel } from './format';

interface Row {
    round_id: number;
    sequence: number;
    tenant_uuid: string;
    state: string;
    staked: string;
    open_stake: string;
    open_bets: number;
    max_exposure: string;
    stake_cap: string;
    stake_cap_used: string | null;
    alert: boolean;
}

interface TenantOption {
    uuid: string;
    name: string;
    slug: string;
}

interface Props {
    rows: Row[];
    tenants: TenantOption[];
    tenantNames: Record<string, string>;
    filters: { tenant_uuid: string | null };
    fetchedAt: string;
    error: string | null;
}

const REFRESH_MS = 10_000;

export default function Exposure({ rows = [], tenants = [], tenantNames = {}, filters, fetchedAt, error }: Props) {
    const [tenantUuid, setTenantUuid] = useState<string | null>(filters?.tenant_uuid ?? null);
    const [live, setLive] = useState(true);

    useEffect(() => {
        if (!live) return;
        const id = setInterval(() => {
            if (document.visibilityState === 'visible') {
                router.reload({ only: ['rows', 'fetchedAt', 'error'] });
            }
        }, REFRESH_MS);
        return () => clearInterval(id);
    }, [live]);

    const applyTenant = (v: string | null) => {
        setTenantUuid(v);
        router.get('/vrrr-pha/exposure', v ? { tenant_uuid: v } : {}, { preserveState: true, preserveScroll: true });
    };

    const totals = useMemo(() => {
        let staked = 0;
        let open = 0;
        let worst = 0;
        let alerts = 0;
        for (const r of rows) {
            staked += num(r.staked);
            open += num(r.open_stake);
            worst += num(r.max_exposure);
            if (r.alert) alerts += 1;
        }
        return { staked, open, worst, alerts };
    }, [rows]);

    return (
        <UserLayout title="Vrrr Pha exposure">
            <Head title="Vrrr Pha exposure · Admin" />
            <div className="cgo-page">
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Vrrr Pha</div>
                        <h1 className="cgo-title">Exposure</h1>
                        <div className="cgo-subtitle">
                            Open rounds right now — stake committed against each tenant's per-round cap, and the worst case against max win.
                        </div>
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 12, color: 'var(--cg-fg-3)' }}>
                        <span className="cgo-uid">as of {formatDateTime(fetchedAt)}</span>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={live}
                            className="cg-btn cg-btn--text cg-btn--sm"
                            onClick={() => setLive((v) => !v)}
                        >
                            {live ? '● Live' : '○ Paused'}
                        </button>
                    </div>
                </div>

                {error && (
                    <div style={ERROR_BOX}>
                        <strong style={{ color: 'var(--cg-neg)' }}>Error:</strong> {error}
                    </div>
                )}

                <div className="cgo-kpis cgo-kpis--5">
                    <KpiCard label="Open rounds" value={formatCount(rows.length)} meta="not yet settled" />
                    <KpiCard label="Staked" value={formatCurrencyCompact(totals.staked)} meta="all live bets" />
                    <KpiCard label="Still in play" value={formatCurrencyCompact(totals.open)} meta="not cashed out or busted" />
                    <KpiCard label="Worst case" value={formatCurrencyCompact(totals.worst)} brass meta="max exposure, capped by max win" />
                    <KpiCard
                        label="Cap alerts"
                        value={formatCount(totals.alerts)}
                        meta={totals.alerts > 0 ? 'rounds at ≥80% of cap' : 'none at ≥80% of cap'}
                    />
                </div>

                <div className="cgo-filterbar">
                    <span className="cgo-sort-label">Tenant</span>
                    <label className="cgo-input" style={{ minWidth: 220 }}>
                        <select value={tenantUuid ?? ''} onChange={(e) => applyTenant(e.target.value || null)} style={SELECT_RESET}>
                            <option value="">All tenants</option>
                            {tenants.map((t) => (
                                <option key={t.uuid} value={t.uuid}>{t.name}</option>
                            ))}
                        </select>
                    </label>
                    {tenantUuid && (
                        <button type="button" className="cg-btn cg-btn--text cg-btn--sm" onClick={() => applyTenant(null)}>
                            Clear
                        </button>
                    )}
                </div>

                <div className="cgo-table-wrap cgo-table-wrap--scroll" style={{ borderRadius: '0 0 8px 8px', borderTop: 0 }}>
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ width: 90 }}>Round</th>
                                <th style={{ width: 160 }}>Tenant</th>
                                <th style={{ width: 100 }}>State</th>
                                <th className="cgo-r" style={{ width: 130 }}>Staked</th>
                                <th className="cgo-r" style={{ width: 130 }}>In play</th>
                                <th className="cgo-r" style={{ width: 80 }}>Open bets</th>
                                <th className="cgo-r" style={{ width: 130 }}>Cap</th>
                                <th className="cgo-r" style={{ width: 90 }}>Cap used</th>
                                <th className="cgo-r" style={{ width: 130 }}>Worst case</th>
                                <th style={{ width: 40 }} />
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={10} style={{ textAlign: 'center', color: 'var(--cg-fg-3)', padding: '32px 0' }}>
                                        No open rounds.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((r) => {
                                    const state = statePill(r.state);
                                    return (
                                        <tr
                                            key={r.round_id}
                                            className={r.alert ? 'flagged' : undefined}
                                            onClick={() => router.get(`/vrrr-pha/rounds/${r.round_id}`)}
                                            style={{ cursor: 'pointer' }}
                                        >
                                            <td><span className="cgo-name" style={{ fontFamily: 'var(--cg-mono)' }}>#{r.sequence}</span></td>
                                            <td>{tenantLabel(r.tenant_uuid, tenantNames)}</td>
                                            <td><span className={`cgo-pill ${state.pill}`}>{state.label}</span></td>
                                            <td className="cgo-r">
                                                <span className="cgo-stake"><span className="cgo-ccy">NAD</span>{formatNAD(r.staked)}</span>
                                            </td>
                                            <td className="cgo-r"><span className="cgo-payout">{formatNAD(r.open_stake)}</span></td>
                                            <td className="cgo-r"><span className="cgo-odds">{formatCount(r.open_bets)}</span></td>
                                            <td className="cgo-r"><span className="cgo-payout">{num(r.stake_cap) > 0 ? formatNAD(r.stake_cap) : '—'}</span></td>
                                            <td className="cgo-r">
                                                <span className="cgo-odds" style={{ color: r.alert ? 'var(--cg-neg)' : undefined }}>
                                                    {formatRatioPct(r.stake_cap_used, 0)}
                                                </span>
                                            </td>
                                            <td className="cgo-r"><span className="cgo-payout">{formatNAD(r.max_exposure)}</span></td>
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
                            Worst case is what the round pays if every open bet cashes out at its auto target or the multiplier ceiling, capped at the tenant's max win per round.
                        </span>
                    </div>
                </div>
            </div>
        </UserLayout>
    );
}
