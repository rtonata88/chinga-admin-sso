// resources/js/pages/admin/dashboard.tsx
//
// Admin overview, brass-on-ink redesign mirroring /dashboard.
// Different metrics — this page surfaces tenant-admin operations
// (players, venues, vouchers, security) instead of live trading
// activity. Same KPI card pattern + hairline-row table.

import {
    KpiCard,
    formatCount,
    formatCurrencyCompact,
} from '@/components/operator/kpi-card';
import UserLayout from '@/layouts/user-layout';
import { Head } from '@inertiajs/react';

interface UserStats {
    total: number;
    today: number;
    this_week: number;
    active: number;
}

interface VenueStats {
    total: number;
    active: number;
}

interface VoucherStats {
    active: number;
    total_balance: number;
}

interface SecurityStats {
    failed_logins_today: number;
    locked_accounts: number;
}

interface Stats {
    users: UserStats;
    venues: VenueStats;
    vouchers: VoucherStats;
    security: SecurityStats;
}

interface RecentUser {
    uuid: string;
    name: string;
    email: string;
    status: string;
    created_at: string;
}

interface AdminDashboardProps {
    stats: Stats;
    recent_users: RecentUser[];
}

function initialsFor(name: string): string {
    return (
        name
            .split(' ')
            .filter(Boolean)
            .slice(0, 2)
            .map((p) => p[0])
            .join('')
            .toUpperCase() || '??'
    );
}

function formatDate(iso: string): string {
    try {
        const d = new Date(iso);
        const date = d.toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' });
        const hh = String(d.getHours()).padStart(2, '0');
        const mm = String(d.getMinutes()).padStart(2, '0');
        return `${hh}:${mm} · ${date}`;
    } catch {
        return iso;
    }
}

function statusPill(status: string): { className: string; label: string } {
    const s = (status || '').toLowerCase();
    if (s === 'active') return { className: 'cgo-pill live', label: 'Active' };
    if (s === 'pending') return { className: 'cgo-pill pending', label: 'Pending' };
    if (s === 'suspended' || s === 'banned') return { className: 'cgo-pill flagged', label: status };
    if (s === 'locked') return { className: 'cgo-pill flagged', label: 'Locked' };
    return { className: 'cgo-pill void', label: status || '—' };
}

export default function AdminDashboard({ stats, recent_users }: AdminDashboardProps) {
    const playersDelta =
        stats.users.today > 0
            ? { sign: 'pos' as const, text: `+${stats.users.today} today` }
            : undefined;

    const securityFailures = stats.security.failed_logins_today;
    const securityLocked = stats.security.locked_accounts;

    return (
        <UserLayout title="Admin">
            <Head title="Admin · Dashboard" />
            <div className="cgo-page">
                {/* Page header */}
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Admin</div>
                        <h1 className="cgo-title">Tenant overview</h1>
                        <div className="cgo-subtitle">
                            Players, venues, voucher pool and security signals for your tenant.
                        </div>
                    </div>
                </div>

                {/* KPI strip — five admin-flavoured cards. */}
                <div className="cgo-kpis cgo-kpis--5">
                    <KpiCard
                        label="Players · total"
                        value={formatCount(stats.users.total)}
                        delta={playersDelta}
                        meta={`${formatCount(stats.users.active)} active`}
                    />
                    <KpiCard
                        label="Active venues"
                        value={formatCount(stats.venues.active)}
                        meta={`${stats.venues.total} total`}
                    />
                    <KpiCard
                        label="Outstanding vouchers"
                        value={formatCurrencyCompact(stats.vouchers.total_balance)}
                        brass
                        meta={`${formatCount(stats.vouchers.active)} active codes`}
                    />
                    <KpiCard
                        label="Failed logins · today"
                        value={formatCount(securityFailures)}
                        delta={
                            securityFailures > 0
                                ? { sign: 'neg', text: `${securityFailures} attempts` }
                                : undefined
                        }
                        meta={securityFailures === 0 ? 'all clear' : 'review'}
                    />
                    <KpiCard
                        label="Locked accounts"
                        value={formatCount(securityLocked)}
                        delta={
                            securityLocked > 0
                                ? { sign: 'neg', text: `${securityLocked} held` }
                                : undefined
                        }
                        meta={securityLocked === 0 ? 'none held' : 'awaiting unlock'}
                    />
                </div>

                {/* Recent registrations — same hairline-row idiom as /dashboard. */}
                <div
                    className="cgo-table-bar"
                    style={{
                        borderRadius: '8px 8px 0 0',
                        borderTop: '1px solid var(--cg-rule)',
                        borderLeft: '1px solid var(--cg-rule)',
                        borderRight: '1px solid var(--cg-rule)',
                    }}
                >
                    <div className="cgo-table-bar-title">Recent registrations</div>
                </div>
                <div
                    className="cgo-table-wrap cgo-table-wrap--scroll"
                    style={{ borderRadius: '0 0 8px 8px' }}
                >
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ minWidth: 200 }}>Player</th>
                                <th style={{ minWidth: 220 }}>Email</th>
                                <th style={{ width: 120 }}>Status</th>
                                <th style={{ width: 180 }}>Joined</th>
                            </tr>
                        </thead>
                        <tbody>
                            {recent_users.length === 0 ? (
                                <tr>
                                    <td colSpan={4} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        No registrations yet.
                                    </td>
                                </tr>
                            ) : (
                                recent_users.map((u) => {
                                    const pill = statusPill(u.status);
                                    return (
                                        <tr key={u.uuid}>
                                            <td style={{ maxWidth: 240 }}>
                                                <div className="cgo-user">
                                                    <div className="cgo-av">{initialsFor(u.name)}</div>
                                                    <div style={{ minWidth: 0 }}>
                                                        <div
                                                            className="cgo-name cgo-cell-clip"
                                                            title={u.name}
                                                        >
                                                            {u.name}
                                                        </div>
                                                        <div className="cgo-uid">
                                                            {u.uuid.slice(0, 6).toUpperCase()}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td style={{ maxWidth: 260 }}>
                                                <span
                                                    className="cgo-cell-clip"
                                                    style={{
                                                        display: 'block',
                                                        color: 'var(--cg-fg-2)',
                                                        fontSize: 12,
                                                    }}
                                                    title={u.email}
                                                >
                                                    {u.email}
                                                </span>
                                            </td>
                                            <td>
                                                <span className={pill.className}>{pill.label}</span>
                                            </td>
                                            <td className="cgo-ts">{formatDate(u.created_at)}</td>
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
