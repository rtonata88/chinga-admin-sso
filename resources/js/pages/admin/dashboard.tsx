import UserLayout from '@/layouts/user-layout';
import PageHeader from '@/components/acumatica/Common/PageHeader';
import StatusBadge from '@/components/acumatica/Common/StatusBadge';
import type { StatusVariant } from '@/types/acumatica';
import { Head, Link } from '@inertiajs/react';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';

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

interface FantasySummary {
    bets_placed: number;
    active_players: number;
    total_wagered: string;
    total_paid_out: string;
    ggr: string;
    wins: number;
    losses: number;
    pending: number;
}

interface FantasyRound {
    id: number;
    round_number: number;
    start_time: string;
    end_time: string | null;
    bet_count: number;
    total_wagered: string;
    total_paid_out: string;
}

interface FantasyData {
    period: { from: string; to: string };
    summary: FantasySummary;
    recent_rounds: FantasyRound[];
}

interface DashboardProps {
    stats: Stats;
    recent_users: RecentUser[];
    fantasy: FantasyData | null;
}

function formatCurrency(amount: number): string {
    return `NAD ${amount.toLocaleString()}`;
}

const defaultStats: Stats = {
    users: { total: 0, today: 0, this_week: 0, active: 0 },
    venues: { total: 0, active: 0 },
    vouchers: { active: 0, total_balance: 0 },
    security: { failed_logins_today: 0, locked_accounts: 0 },
};

interface StatCardProps {
    icon: string;
    accentColor: string;
    title: string;
    value: string | number;
    subtitle: string;
    glowColor?: string;
}

function StatCard({ icon, accentColor, title, value, subtitle, glowColor }: StatCardProps) {
    return (
        <div
            className="relative overflow-hidden rounded-xl p-5 transition-all duration-300"
            style={{
                background: 'var(--acu-surface-card)',
                border: '1px solid var(--acu-border)',
            }}
            onMouseEnter={(e) => {
                e.currentTarget.style.borderColor = `${accentColor}30`;
                e.currentTarget.style.boxShadow = `0 8px 32px ${glowColor || accentColor}15`;
            }}
            onMouseLeave={(e) => {
                e.currentTarget.style.borderColor = 'var(--acu-border)';
                e.currentTarget.style.boxShadow = 'none';
            }}
        >
            {/* Subtle gradient overlay */}
            <div
                className="absolute inset-0 opacity-[0.03]"
                style={{
                    background: `radial-gradient(circle at top right, ${accentColor}, transparent 70%)`,
                }}
            />

            <div className="relative">
                <div className="flex items-center justify-between mb-4">
                    <span
                        className="text-[10px] font-semibold uppercase tracking-[0.1em]"
                        style={{ color: 'var(--acu-text-light)', fontFamily: 'var(--font-body)' }}
                    >
                        {title}
                    </span>
                    <div
                        className="w-9 h-9 rounded-lg flex items-center justify-center"
                        style={{
                            background: `${accentColor}12`,
                            border: `1px solid ${accentColor}20`,
                        }}
                    >
                        <i className={`${icon} text-sm`} style={{ color: accentColor }} />
                    </div>
                </div>
                <div
                    className="text-[1.75rem] font-bold leading-none"
                    style={{ color: 'var(--acu-text)', fontFamily: 'var(--font-display)' }}
                >
                    {value}
                </div>
                <p
                    className="text-xs mt-2"
                    style={{ color: 'var(--acu-text-light)', fontFamily: 'var(--font-body)' }}
                >
                    {subtitle}
                </p>
            </div>
        </div>
    );
}

export default function Dashboard({
    stats: propStats,
    recent_users = [],
    fantasy = null,
}: Partial<DashboardProps>) {
    const stats = propStats ?? defaultStats;

    return (
        <UserLayout title="Dashboard">
            <Head title="Admin Dashboard" />

            <div className="space-y-8">
                <PageHeader title="Dashboard" subtitle="Overview of platform activity" />

                {/* Stats Cards */}
                <div className="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                    <StatCard
                        icon="pi pi-users"
                        accentColor="#58A6FF"
                        title="Total Users"
                        value={(stats.users?.total ?? 0).toLocaleString()}
                        subtitle={`+${stats.users?.today ?? 0} today, +${stats.users?.this_week ?? 0} this week`}
                    />
                    <StatCard
                        icon="pi pi-map-marker"
                        accentColor="#3FB950"
                        title="Active Venues"
                        value={stats.venues?.active ?? 0}
                        subtitle={`of ${stats.venues?.total ?? 0} total venues`}
                    />
                    <StatCard
                        icon="pi pi-wallet"
                        accentColor="#C9A84C"
                        title="Voucher Balance"
                        value={formatCurrency(stats.vouchers?.total_balance ?? 0)}
                        subtitle={`across ${stats.vouchers?.active ?? 0} active codes`}
                    />
                </div>

                {/* Security Alerts */}
                {((stats.security?.failed_logins_today ?? 0) > 10 ||
                    (stats.security?.locked_accounts ?? 0) > 0) && (
                    <div
                        className="rounded-xl overflow-hidden"
                        style={{
                            background: 'rgba(248, 81, 73, 0.04)',
                            border: '1px solid rgba(248, 81, 73, 0.15)',
                        }}
                    >
                        <div
                            className="flex items-center gap-3 px-5 py-3"
                            style={{ borderBottom: '1px solid rgba(248, 81, 73, 0.1)' }}
                        >
                            <i className="pi pi-exclamation-triangle text-sm" style={{ color: '#F85149' }} />
                            <span
                                className="text-sm font-semibold"
                                style={{ color: '#F85149', fontFamily: 'var(--font-body)' }}
                            >
                                Security Alerts
                            </span>
                        </div>
                        <div className="px-5 py-4 space-y-2">
                            {(stats.security?.failed_logins_today ?? 0) > 10 && (
                                <p className="text-sm flex items-center gap-2" style={{ color: 'var(--acu-text)' }}>
                                    <span className="w-1.5 h-1.5 rounded-full" style={{ background: '#F85149' }} />
                                    {stats.security?.failed_logins_today ?? 0} failed login attempts today
                                </p>
                            )}
                            {(stats.security?.locked_accounts ?? 0) > 0 && (
                                <p className="text-sm flex items-center gap-2" style={{ color: 'var(--acu-text)' }}>
                                    <span className="w-1.5 h-1.5 rounded-full" style={{ background: '#D29922' }} />
                                    {stats.security?.locked_accounts ?? 0} accounts currently locked
                                </p>
                            )}
                        </div>
                    </div>
                )}

                {/* Chinga Fantasy — last 30 days (tenant-scoped) */}
                {fantasy ? (
                    <div className="space-y-5">
                        <div className="flex items-center justify-between">
                            <div>
                                <h2 className="text-lg font-semibold" style={{ color: 'var(--acu-text)' }}>
                                    Chinga Fantasy
                                </h2>
                                <p className="text-xs" style={{ color: 'var(--acu-text-light)' }}>
                                    Last 30 days
                                </p>
                            </div>
                        </div>
                        <div className="grid gap-5 md:grid-cols-2 lg:grid-cols-4">
                            <StatCard
                                icon="pi pi-ticket"
                                accentColor="#8B5CF6"
                                title="Bets Placed"
                                value={(fantasy.summary.bets_placed ?? 0).toLocaleString()}
                                subtitle={`${fantasy.summary.active_players ?? 0} active players`}
                            />
                            <StatCard
                                icon="pi pi-dollar"
                                accentColor="#3FB950"
                                title="Total Wagered"
                                value={formatCurrency(parseFloat(fantasy.summary.total_wagered || '0'))}
                                subtitle={`${fantasy.summary.wins ?? 0} wins · ${fantasy.summary.losses ?? 0} losses`}
                            />
                            <StatCard
                                icon="pi pi-money-bill"
                                accentColor="#F85149"
                                title="Total Paid Out"
                                value={formatCurrency(parseFloat(fantasy.summary.total_paid_out || '0'))}
                                subtitle="To winners"
                            />
                            <StatCard
                                icon="pi pi-chart-line"
                                accentColor="#58A6FF"
                                title="GGR"
                                value={formatCurrency(parseFloat(fantasy.summary.ggr || '0'))}
                                subtitle="Gross gaming revenue"
                            />
                        </div>
                        {fantasy.recent_rounds.length > 0 && (
                            <div className="acu-fieldset">
                                <div className="acu-fieldset-header">
                                    <div className="acu-fieldset-title">
                                        <i className="pi pi-history" />
                                        <span>Recent Rounds</span>
                                    </div>
                                </div>
                                <div className="acu-fieldset-body p-0">
                                    <DataTable
                                        value={fantasy.recent_rounds}
                                        size="small"
                                        emptyMessage="No rounds yet"
                                        showGridlines={false}
                                    >
                                        <Column field="round_number" header="Round" />
                                        <Column
                                            field="start_time"
                                            header="Started"
                                            body={(row: FantasyRound) => new Date(row.start_time).toLocaleString()}
                                        />
                                        <Column field="bet_count" header="Bets" />
                                        <Column
                                            field="total_wagered"
                                            header="Wagered"
                                            body={(row: FantasyRound) => formatCurrency(parseFloat(row.total_wagered))}
                                        />
                                        <Column
                                            field="total_paid_out"
                                            header="Paid Out"
                                            body={(row: FantasyRound) => formatCurrency(parseFloat(row.total_paid_out))}
                                        />
                                    </DataTable>
                                </div>
                            </div>
                        )}
                    </div>
                ) : (
                    <div
                        className="rounded-xl px-5 py-4"
                        style={{
                            background: 'rgba(210, 153, 34, 0.04)',
                            border: '1px solid rgba(210, 153, 34, 0.15)',
                            color: 'var(--acu-text-light)',
                        }}
                    >
                        <i className="pi pi-info-circle mr-2" />
                        Chinga Fantasy metrics are unavailable. Check that the fantasy backend is reachable and SSO_INTERNAL_CLIENT_ID/SECRET are set.
                    </div>
                )}

                {/* Recent Users */}
                <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-gold)' } as React.CSSProperties}>
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-user-plus" />
                            <span>Recent Registrations</span>
                            <span
                                className="text-xs font-normal ml-1"
                                style={{ color: 'var(--acu-text-light)' }}
                            >
                                ({recent_users.length})
                            </span>
                        </div>
                        <Link
                            href="/admin/users"
                            className="text-xs font-semibold flex items-center gap-1.5 transition-colors"
                            style={{ color: 'var(--acu-primary)', fontFamily: 'var(--font-body)' }}
                        >
                            View all <i className="pi pi-arrow-right text-[10px]" />
                        </Link>
                    </div>
                    <div className="acu-fieldset-body p-0">
                        <DataTable
                            value={recent_users}
                            size="small"
                            emptyMessage="No recent registrations"
                            showGridlines={false}
                            rows={5}
                        >
                            <Column field="name" header="Name" />
                            <Column field="email" header="Email" />
                            <Column
                                field="status"
                                header="Status"
                                body={(row) => (
                                    <StatusBadge status={(row.status || 'pending') as StatusVariant} />
                                )}
                            />
                        </DataTable>
                    </div>
                </div>
            </div>
        </UserLayout>
    );
}
