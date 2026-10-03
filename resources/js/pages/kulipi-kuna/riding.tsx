// resources/js/pages/kulipi-kuna/riding.tsx
//
// Riding liability (Kulipi Kuna PRD §5): the value of every live ladder,
// level by level, against the tenant's max_total_riding_per_round. The
// engine computes the 80% alert; this page only shows it. The cap is per
// tenant, so with no tenant picked the page shows the total and asks for one.

import { KpiCard, formatCount, formatCurrencyCompact } from '@/components/operator/kpi-card';
import UserLayout from '@/layouts/user-layout';
import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { ERROR_BOX, SELECT_RESET, TAP, formatDateTime, formatNAD, formatRatioPct, num } from '../vrrr-pha/format';

interface Riding {
    tenant_uuid: string | null;
    total_riding: string;
    cap: string | null;
    used: string | null;
    alert: boolean;
    held_credits?: number;
    levels: { level: number; ladders: number; riding: string }[];
}

interface Props {
    game: { name: string; base: string };
    riding: Riding | null;
    tenants: { uuid: string; name: string; slug: string }[];
    filters: { tenant_uuid: string | null };
    fetchedAt: string;
    error: string | null;
}

const REFRESH_MS = 10_000;

export default function RidingPage({ game, riding, tenants = [], filters, fetchedAt, error }: Props) {
    const [tenantUuid, setTenantUuid] = useState<string | null>(filters?.tenant_uuid ?? null);
    const [live, setLive] = useState(true);

    useEffect(() => {
        if (!live) return;
        const id = setInterval(() => {
            if (document.visibilityState === 'visible') router.reload({ only: ['riding', 'fetchedAt', 'error'] });
        }, REFRESH_MS);
        return () => clearInterval(id);
    }, [live]);

    const applyTenant = (v: string | null) => {
        setTenantUuid(v);
        router.get(`${game.base}/riding`, v ? { tenant_uuid: v } : {}, { preserveState: true, preserveScroll: true });
    };

    const ladders = (riding?.levels ?? []).reduce((s, l) => s + l.ladders, 0);

    return (
        <UserLayout title={`${game.name} riding`}>
            <Head title={`${game.name} riding · Admin`} />
            <div className="cgo-page">
                <div className="cgo-page-head max-sm:flex-wrap">
                    <div>
                        <div className="cgo-eyebrow">{game.name}</div>
                        <h1 className="cgo-title">Riding</h1>
                        <div className="cgo-subtitle">
                            The value of every live ladder, level by level, against the tenant&apos;s riding cap. Every level carries the same
                            liability per entry, so the total grows with depth.
                        </div>
                    </div>
                    <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 10, fontSize: 12, color: 'var(--cg-fg-3)' }}>
                        <span className="cgo-uid">as of {formatDateTime(fetchedAt)}</span>
                        <button type="button" role="switch" aria-checked={live} className={`cg-btn cg-btn--text cg-btn--sm ${TAP}`} onClick={() => setLive((v) => !v)}>
                            {live ? '● Live' : '○ Paused'}
                        </button>
                    </div>
                </div>

                {error && (
                    <div style={ERROR_BOX}>
                        <strong style={{ color: 'var(--cg-neg)' }}>Error:</strong> {error}
                    </div>
                )}

                <div className="cgo-kpis">
                    <KpiCard label="Riding" value={formatCurrencyCompact(num(riding?.total_riding))} brass meta="value of every live ladder" />
                    <KpiCard label="Live ladders" value={formatCount(ladders)} meta="across all levels" />
                    <KpiCard label="Cap" value={riding?.cap ? formatCurrencyCompact(num(riding.cap)) : '—'} meta={riding?.cap ? 'max total riding per round' : filters?.tenant_uuid ? 'No round yet for this tenant, so no cap to compare against.' : 'pick a tenant: the cap is per tenant'} />
                    <KpiCard
                        label="Cap used"
                        value={riding?.used ? formatRatioPct(riding.used, 0) : '—'}
                        meta={riding?.cap ? (riding.alert ? 'at or above 80%: new ladders will soon be refused' : 'below 80%') : filters?.tenant_uuid ? 'no round yet for this tenant' : 'no cap without a tenant'}
                    />
                </div>

                {riding?.alert && (
                    <div data-testid="riding-alert" style={{ ...ERROR_BOX, borderColor: 'var(--cg-neg)' }}>
                        Riding is at {formatRatioPct(riding.used, 0)} of this tenant&apos;s cap. New ladders are refused once it is reached; continues never are.
                    </div>
                )}

                {(riding?.held_credits ?? 0) > 0 && (
                    <div data-testid="held-credits" style={ERROR_BOX}>
                        <strong style={{ color: 'var(--cg-neg)' }}>{riding?.held_credits} held credit{riding?.held_credits === 1 ? '' : 's'}:</strong>{' '}
                        winnings the engine stopped retrying after 8 attempts. Reconcile each by its kk_win_ reference in the wallet ledger.
                    </div>
                )}

                <div className="cgo-filterbar">
                    <span className="cgo-sort-label">Tenant</span>
                    <label className={`cgo-input max-sm:flex-1 sm:min-w-[220px] ${TAP}`}>
                        <select value={tenantUuid ?? ''} onChange={(e) => applyTenant(e.target.value || null)} style={SELECT_RESET}>
                            <option value="">All tenants</option>
                            {tenants.map((t) => (
                                <option key={t.uuid} value={t.uuid}>{t.name}</option>
                            ))}
                        </select>
                    </label>
                    {tenantUuid && (
                        <button type="button" className={`cg-btn cg-btn--text cg-btn--sm ${TAP}`} onClick={() => applyTenant(null)}>Clear</button>
                    )}
                </div>

                <div className="cgo-table-wrap" style={{ borderRadius: '0 0 8px 8px', borderTop: 0 }}>
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ width: 100 }}>Level</th>
                                <th className="cgo-r" style={{ width: 120 }}>Live ladders</th>
                                <th className="cgo-r" style={{ width: 160 }}>Riding</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(riding?.levels ?? []).length === 0 ? (
                                <tr>
                                    <td colSpan={3} style={{ textAlign: 'center', color: 'var(--cg-fg-3)', padding: '32px 0' }}>No live ladders.</td>
                                </tr>
                            ) : (
                                (riding?.levels ?? []).map((l) => (
                                    <tr key={l.level}>
                                        <td><span className="cgo-name" style={{ fontFamily: 'var(--cg-mono)' }}>{l.level}</span></td>
                                        <td className="cgo-r"><span className="cgo-odds">{formatCount(l.ladders)}</span></td>
                                        <td className="cgo-r"><span className="cgo-stake"><span className="cgo-ccy">NAD</span>{formatNAD(l.riding)}</span></td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <div className="cgo-table-foot">
                        <span>A ladder rides at the level it has reached; its value is what Collect would pay now. Payouts are never cut: depth is capped at entry instead.</span>
                    </div>
                </div>
            </div>
        </UserLayout>
    );
}
