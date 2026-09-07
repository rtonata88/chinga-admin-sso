// resources/js/pages/admin/dashboard.tsx
//
// Tenant overview, brass-on-ink. The KPI strip is scope-aware:
// platform admins see cross-tenant aggregates (Total Tenants, Total
// Wagered, Total Wins, Platform Profit); tenant admins see their
// tenant's slice (Bets Placed, Total Wagered, Total Wins, Your
// share). Server decides scope so a tenant admin can never accidentally
// be shown platform-wide numbers.

import {
    KpiCard,
    formatCount,
    formatCurrencyCompact,
    formatNAD,
} from '@/components/operator/kpi-card';
import UserLayout from '@/layouts/user-layout';
import { Head } from '@inertiajs/react';

interface PlatformKpis {
    total_tenants: number;
    total_wagered: number;
    total_wins: number;
    platform_profit: number;
}

interface TenantKpis {
    bets_placed: number;
    total_wagered: number;
    total_wins: number;
    tenant_profit: number;
}

interface Period {
    from: string;
    to: string;
}

interface TenantBreakdown {
    tenant_id: number | null;
    tenant_uuid: string | null;
    tenant_name: string;
    business_model: string;
    revenue_share_pct: number;
    bets_placed: number;
    active_players: number;
    total_wagered: number;
    total_paid_out: number;
    ggr: number;
    ngr: number;
    tenant_profit: number;
    platform_profit: number;
}

interface GameBreakdown {
    game_uuid: string;
    game_name: string;
    bets_placed: number;
    active_players: number;
    total_wagered: number;
    total_paid_out: number;
    ggr: number;
    ngr: number;
    tenant_profit: number;
    platform_profit: number;
}

interface AdminDashboardProps {
    period: Period;
    kpis: PlatformKpis | TenantKpis;
    scope: 'platform' | 'tenant';
    tenant_name?: string | null;
    tenants?: TenantBreakdown[];
    by_game?: GameBreakdown[];
}

const MONTH_FORMATTER = new Intl.DateTimeFormat('en-GB', { month: 'long', year: 'numeric' });

export default function AdminDashboard({ period, kpis, scope, tenant_name, tenants = [], by_game = [] }: AdminDashboardProps) {
    const monthLabel = MONTH_FORMATTER.format(new Date(period.from));
    const isTenantScope = scope === 'tenant';

    // Narrow union per scope so TS lets us read scope-specific keys.
    const platformKpis = isTenantScope ? null : (kpis as PlatformKpis);
    const tenantKpis = isTenantScope ? (kpis as TenantKpis) : null;

    return (
        <UserLayout title={isTenantScope ? 'Overview' : 'Admin'}>
            <Head title={isTenantScope ? `${tenant_name ?? 'Tenant'} · Overview` : 'Admin · Dashboard'} />
            <div className="cgo-page">
                {/* Page header */}
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">{isTenantScope ? 'Tenant' : 'Admin'}</div>
                        <h1 className="cgo-title">
                            {isTenantScope ? (tenant_name ?? 'Tenant overview') : 'Tenant overview'}
                        </h1>
                        <div className="cgo-subtitle">
                            {isTenantScope
                                ? `Activity for ${monthLabel}.`
                                : `Cross-tenant activity for ${monthLabel}.`}
                        </div>
                    </div>
                </div>

                {/* KPI strip — month-to-date, scope-aware. */}
                <div className="cgo-kpis">
                    {isTenantScope ? (
                        <>
                            <KpiCard
                                label="Bets placed"
                                value={formatCount(tenantKpis!.bets_placed)}
                                meta={monthLabel}
                            />
                            <KpiCard
                                label="Total wagered"
                                value={formatCurrencyCompact(tenantKpis!.total_wagered)}
                                meta="staked by your players"
                            />
                            <KpiCard
                                label="Total wins"
                                value={formatCurrencyCompact(tenantKpis!.total_wins)}
                                meta="paid out to winners"
                            />
                            <KpiCard
                                label="Your share"
                                value={formatCurrencyCompact(tenantKpis!.tenant_profit)}
                                brass
                                meta="after tax + platform split"
                            />
                        </>
                    ) : (
                        <>
                            <KpiCard
                                label="Total tenants"
                                value={formatCount(platformKpis!.total_tenants)}
                                meta="all tenants on platform"
                            />
                            <KpiCard
                                label="Total wagered"
                                value={formatCurrencyCompact(platformKpis!.total_wagered)}
                                meta={monthLabel}
                            />
                            <KpiCard
                                label="Total wins"
                                value={formatCurrencyCompact(platformKpis!.total_wins)}
                                meta={monthLabel}
                            />
                            <KpiCard
                                label="Platform profit"
                                value={formatCurrencyCompact(platformKpis!.platform_profit)}
                                brass
                                meta={monthLabel}
                            />
                        </>
                    )}
                </div>

                {/* By game · month-to-date. One row per game backend in the
                    catalogue, summed over the tenants visible to this user. */}
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
                    <div className="cgo-table-bar-title">By game · {monthLabel}</div>
                </div>
                <div
                    className="cgo-table-wrap cgo-table-wrap--scroll"
                    style={{ borderRadius: '0 0 8px 8px', marginBottom: 24 }}
                >
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ minWidth: 200 }}>Game</th>
                                <th className="cgo-r" style={{ width: 100 }}>Total bets</th>
                                <th className="cgo-r" style={{ width: 110 }}>Players</th>
                                <th className="cgo-r" style={{ width: 130 }}>Total wagered</th>
                                <th className="cgo-r" style={{ width: 130 }}>Wins</th>
                                <th className="cgo-r" style={{ width: 130 }}>GGR</th>
                                <th className="cgo-r" style={{ width: 140 }}>
                                    {isTenantScope ? 'Your share' : 'Platform profit'}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {by_game.length === 0 ? (
                                <tr>
                                    <td colSpan={7} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        No game activity in {monthLabel}.
                                    </td>
                                </tr>
                            ) : (
                                by_game.map((g) => {
                                    const profit = isTenantScope ? g.tenant_profit : g.platform_profit;
                                    return (
                                        <tr key={g.game_uuid}>
                                            <td style={{ maxWidth: 240 }}>
                                                <div className="cgo-name cgo-cell-clip" title={g.game_name}>
                                                    {g.game_name}
                                                </div>
                                            </td>
                                            <td className="cgo-r">
                                                <span className="cgo-odds">{formatCount(g.bets_placed)}</span>
                                            </td>
                                            <td className="cgo-r">
                                                <span className="cgo-odds">{formatCount(g.active_players)}</span>
                                            </td>
                                            <td className="cgo-r">
                                                <span className="cgo-stake">
                                                    <span className="cgo-ccy">NAD</span>
                                                    {formatNAD(g.total_wagered)}
                                                </span>
                                            </td>
                                            <td className="cgo-r">
                                                <span className="cgo-stake">
                                                    <span className="cgo-ccy">NAD</span>
                                                    {formatNAD(g.total_paid_out)}
                                                </span>
                                            </td>
                                            <td className="cgo-r">
                                                <span
                                                    className="cgo-payout"
                                                    style={{ color: g.ggr < 0 ? 'var(--cg-neg)' : undefined }}
                                                >
                                                    {formatNAD(g.ggr)}
                                                </span>
                                            </td>
                                            <td className="cgo-r">
                                                <span
                                                    className="cgo-payout"
                                                    style={{ color: profit < 0 ? 'var(--cg-neg)' : undefined }}
                                                >
                                                    {formatNAD(profit)}
                                                </span>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Tenant breakdown · month-to-date */}
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
                    <div className="cgo-table-bar-title">
                        {isTenantScope ? `Breakdown · ${monthLabel}` : `Tenants · ${monthLabel}`}
                    </div>
                </div>
                <div
                    className="cgo-table-wrap cgo-table-wrap--scroll"
                    style={{ borderRadius: '0 0 8px 8px', marginBottom: 32 }}
                >
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ minWidth: 200 }}>Tenant</th>
                                <th className="cgo-r" style={{ width: 100 }}>Total bets</th>
                                <th className="cgo-r" style={{ width: 130 }}>Total wagered</th>
                                <th className="cgo-r" style={{ width: 130 }}>Wins</th>
                                <th className="cgo-r" style={{ width: 140 }}>Tenant profit</th>
                                <th className="cgo-r" style={{ width: 140 }}>Platform profit</th>
                                <th className="cgo-r" style={{ width: 110 }}></th>
                            </tr>
                        </thead>
                        <tbody>
                            {tenants.length === 0 ? (
                                <tr>
                                    <td colSpan={7} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        No tenant activity in {monthLabel}.
                                    </td>
                                </tr>
                            ) : (
                                tenants.map((t) => (
                                    <tr key={t.tenant_uuid ?? t.tenant_name}>
                                        <td style={{ maxWidth: 240 }}>
                                            <div
                                                className="cgo-name cgo-cell-clip"
                                                title={t.tenant_name}
                                            >
                                                {t.tenant_name}
                                            </div>
                                            <div className="cgo-uid">
                                                {t.business_model.toUpperCase()}
                                                {t.business_model === 'reseller' && t.revenue_share_pct > 0
                                                    ? ` · ${t.revenue_share_pct.toFixed(0)}% share`
                                                    : ''}
                                            </div>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-odds">{formatCount(t.bets_placed)}</span>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-stake">
                                                <span className="cgo-ccy">NAD</span>
                                                {formatNAD(t.total_wagered)}
                                            </span>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-stake">
                                                <span className="cgo-ccy">NAD</span>
                                                {formatNAD(t.total_paid_out)}
                                            </span>
                                        </td>
                                        <td className="cgo-r">
                                            <span
                                                className="cgo-payout"
                                                style={{ color: t.tenant_profit < 0 ? 'var(--cg-neg)' : undefined }}
                                            >
                                                {formatNAD(t.tenant_profit)}
                                            </span>
                                        </td>
                                        <td className="cgo-r">
                                            <span
                                                className="cgo-payout"
                                                style={{ color: t.platform_profit < 0 ? 'var(--cg-neg)' : undefined }}
                                            >
                                                {formatNAD(t.platform_profit)}
                                            </span>
                                        </td>
                                        <td className="cgo-r">
                                            {t.business_model === 'reseller' && t.tenant_uuid ? (
                                                <a
                                                    href={`/tenant-overview/${t.tenant_uuid}/invoice?from=${encodeURIComponent(period.from)}&to=${encodeURIComponent(period.to)}`}
                                                    target="_blank"
                                                    rel="noopener"
                                                    className="cg-btn cg-btn--ghost cg-btn--sm"
                                                >
                                                    Invoice
                                                </a>
                                            ) : null}
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
