import UserLayout from '@/layouts/user-layout';
import PageHeader from '@/components/acumatica/Common/PageHeader';
import StatusBadge from '@/components/acumatica/Common/StatusBadge';
import type { StatusVariant } from '@/types/acumatica';
import { Head, Link } from '@inertiajs/react';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { Button } from 'primereact/button';

interface UserStats {
    total: number;
    today: number;
    this_week: number;
    active: number;
}

interface KycStats {
    pending: number;
    approved_today: number;
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
    kyc: KycStats;
    venues: VenueStats;
    vouchers: VoucherStats;
    security: SecurityStats;
}

interface RecentUser {
    uuid: string;
    name: string;
    email: string;
    status: string;
    kyc_level: number;
    created_at: string;
}

interface PendingKyc {
    uuid: string;
    document_type: string;
    user: {
        uuid: string;
        name: string;
        email: string;
    };
    created_at: string;
}

interface DashboardProps {
    stats: Stats;
    recent_users: RecentUser[];
    pending_kyc: PendingKyc[];
}

function formatCurrency(amount: number): string {
    return `NAD ${amount.toLocaleString()}`;
}

function getKycLevelName(level: number): string {
    return ['Unverified', 'Basic', 'Enhanced', 'Full'][level] || 'Unknown';
}

const defaultStats: Stats = {
    users: { total: 0, today: 0, this_week: 0, active: 0 },
    kyc: { pending: 0, approved_today: 0 },
    venues: { total: 0, active: 0 },
    vouchers: { active: 0, total_balance: 0 },
    security: { failed_logins_today: 0, locked_accounts: 0 },
};

interface StatCardProps {
    icon: string;
    iconColor: string;
    title: string;
    value: string | number;
    subtitle: string;
}

function StatCard({ icon, iconColor, title, value, subtitle }: StatCardProps) {
    return (
        <div className="acu-fieldset">
            <div className="p-4">
                <div className="flex items-center justify-between mb-3">
                    <span className="text-xs font-semibold uppercase tracking-wide text-[var(--acu-text-muted)]">
                        {title}
                    </span>
                    <div
                        className="w-8 h-8 rounded-lg flex items-center justify-center"
                        style={{ backgroundColor: `${iconColor}15`, color: iconColor }}
                    >
                        <i className={`${icon} text-sm`} />
                    </div>
                </div>
                <div className="text-2xl font-bold text-[var(--acu-text)]">{value}</div>
                <p className="text-xs text-[var(--acu-text-light)] mt-1">{subtitle}</p>
            </div>
        </div>
    );
}

export default function Dashboard({
    stats: propStats,
    recent_users = [],
    pending_kyc = [],
}: Partial<DashboardProps>) {
    const stats = propStats ?? defaultStats;

    return (
        <UserLayout title="Dashboard">
            <Head title="Admin Dashboard" />

            <div className="space-y-6">
                <PageHeader title="Dashboard" subtitle="Overview of platform activity" />

                {/* Stats Cards */}
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        icon="pi pi-users"
                        iconColor="#3B82F6"
                        title="Total Users"
                        value={(stats.users?.total ?? 0).toLocaleString()}
                        subtitle={`+${stats.users?.today ?? 0} today, +${stats.users?.this_week ?? 0} this week`}
                    />
                    <StatCard
                        icon="pi pi-shield"
                        iconColor="#F59E0B"
                        title="Pending KYC"
                        value={stats.kyc?.pending ?? 0}
                        subtitle={`${stats.kyc?.approved_today ?? 0} approved today`}
                    />
                    <StatCard
                        icon="pi pi-map-marker"
                        iconColor="#10B981"
                        title="Active Venues"
                        value={stats.venues?.active ?? 0}
                        subtitle={`of ${stats.venues?.total ?? 0} total venues`}
                    />
                    <StatCard
                        icon="pi pi-wallet"
                        iconColor="#8B5CF6"
                        title="Voucher Balance"
                        value={formatCurrency(stats.vouchers?.total_balance ?? 0)}
                        subtitle={`across ${stats.vouchers?.active ?? 0} active codes`}
                    />
                </div>

                {/* Security Alerts */}
                {((stats.security?.failed_logins_today ?? 0) > 10 ||
                    (stats.security?.locked_accounts ?? 0) > 0) && (
                    <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-warning)' } as React.CSSProperties}>
                        <div className="acu-fieldset-header">
                            <div className="acu-fieldset-title">
                                <i className="pi pi-exclamation-triangle" />
                                <span>Security Alerts</span>
                            </div>
                        </div>
                        <div className="acu-fieldset-body space-y-2">
                            {(stats.security?.failed_logins_today ?? 0) > 10 && (
                                <p className="text-sm text-[var(--acu-text)]">
                                    <i className="pi pi-times-circle text-red-500 mr-2" />
                                    {stats.security?.failed_logins_today ?? 0} failed login attempts today
                                </p>
                            )}
                            {(stats.security?.locked_accounts ?? 0) > 0 && (
                                <p className="text-sm text-[var(--acu-text)]">
                                    <i className="pi pi-lock text-amber-500 mr-2" />
                                    {stats.security?.locked_accounts ?? 0} accounts currently locked
                                </p>
                            )}
                        </div>
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Recent Users */}
                    <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-blue)' } as React.CSSProperties}>
                        <div className="acu-fieldset-header">
                            <div className="acu-fieldset-title">
                                <i className="pi pi-user-plus" />
                                <span>Recent Registrations</span>
                                <span className="text-xs font-normal text-[var(--acu-text-light)] ml-1">
                                    ({recent_users.length})
                                </span>
                            </div>
                            <Link href="/admin/users" className="text-xs font-semibold text-[var(--acu-primary)] hover:underline flex items-center gap-1">
                                View all <i className="pi pi-arrow-right text-xs" />
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
                                <Column
                                    field="kyc_level"
                                    header="KYC"
                                    body={(row) => (
                                        <span className="text-xs font-medium">{getKycLevelName(row.kyc_level)}</span>
                                    )}
                                />
                            </DataTable>
                        </div>
                    </div>

                    {/* Pending KYC */}
                    <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-amber)' } as React.CSSProperties}>
                        <div className="acu-fieldset-header">
                            <div className="acu-fieldset-title">
                                <i className="pi pi-file" />
                                <span>Pending KYC Review</span>
                                <span className="text-xs font-normal text-[var(--acu-text-light)] ml-1">
                                    ({pending_kyc.length})
                                </span>
                            </div>
                            <Link href="/admin/kyc" className="text-xs font-semibold text-[var(--acu-primary)] hover:underline flex items-center gap-1">
                                Review all <i className="pi pi-arrow-right text-xs" />
                            </Link>
                        </div>
                        <div className="acu-fieldset-body p-0">
                            <DataTable
                                value={pending_kyc}
                                size="small"
                                emptyMessage="No pending documents"
                                showGridlines={false}
                                rows={5}
                            >
                                <Column
                                    field="document_type"
                                    header="Document"
                                    body={(row) => (
                                        <span className="capitalize">{row.document_type?.replace(/_/g, ' ')}</span>
                                    )}
                                />
                                <Column
                                    header="User"
                                    body={(row) => (
                                        <div>
                                            <div className="font-medium text-sm">{row.user?.name}</div>
                                            <div className="text-xs text-[var(--acu-text-light)]">{row.user?.email}</div>
                                        </div>
                                    )}
                                />
                                <Column
                                    field="created_at"
                                    header="Submitted"
                                    body={(row) => (
                                        <span className="text-sm">{row.created_at ? new Date(row.created_at).toLocaleDateString() : '\u2014'}</span>
                                    )}
                                />
                            </DataTable>
                        </div>
                    </div>
                </div>
            </div>
        </UserLayout>
    );
}
