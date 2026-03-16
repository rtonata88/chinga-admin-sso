import UserLayout from '@/layouts/user-layout';
import PageHeader from '@/components/acumatica/Common/PageHeader';
import { Head, Link } from '@inertiajs/react';

interface Account {
    name: string;
    email: string;
    display_name: string | null;
    avatar_url: string | null;
    status: string;
    email_verified: boolean;
    two_factor_enabled: boolean;
    member_since: string;
    last_login_at: string | null;
}

interface Session {
    device_type: string;
    browser: string;
    platform: string;
    ip_address: string;
    city: string | null;
    country_code: string | null;
    is_current: boolean;
    last_active_at: string | null;
}

interface DashboardProps {
    account: Account;
    sessions: Session[];
    active_session_count: number;
}

function getDeviceIcon(deviceType: string): string {
    switch (deviceType?.toLowerCase()) {
        case 'mobile': return 'pi pi-mobile';
        case 'tablet': return 'pi pi-tablet';
        default: return 'pi pi-desktop';
    }
}

function formatRelativeTime(dateStr: string): string {
    const date = new Date(dateStr);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;
    return date.toLocaleDateString();
}

const defaultAccount: Account = {
    name: '',
    email: '',
    display_name: null,
    avatar_url: null,
    status: 'active',
    email_verified: false,
    two_factor_enabled: false,
    member_since: new Date().toISOString(),
    last_login_at: null,
};

export default function Dashboard({
    account = defaultAccount,
    sessions = [],
    active_session_count = 0,
}: Partial<DashboardProps>) {
    const verificationChecks = [
        { label: 'Email verified', done: account.email_verified, icon: 'pi pi-envelope' },
        { label: 'Two-factor auth', done: account.two_factor_enabled, icon: 'pi pi-shield' },
    ];

    const completedChecks = verificationChecks.filter(c => c.done).length;
    const completionPercent = Math.round((completedChecks / verificationChecks.length) * 100);

    return (
        <UserLayout title="Dashboard">
            <Head title="Dashboard" />

            <div className="space-y-6">
                <PageHeader title={`Welcome back, ${account.display_name || account.name}`} subtitle="Your account overview" />

                {/* Top Stats Row */}
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    {/* Account Status */}
                    <div className="acu-fieldset">
                        <div className="p-4">
                            <div className="flex items-center justify-between mb-3">
                                <span className="text-xs font-semibold uppercase tracking-wide text-[var(--acu-text-muted)]">
                                    Account Status
                                </span>
                                <div
                                    className="w-8 h-8 rounded-lg flex items-center justify-center"
                                    style={{ backgroundColor: '#10B98115', color: '#10B981' }}
                                >
                                    <i className="pi pi-user text-sm" />
                                </div>
                            </div>
                            <div className="text-2xl font-bold text-[var(--acu-text)] capitalize">{account.status}</div>
                            <p className="text-xs text-[var(--acu-text-light)] mt-1">
                                Member since {new Date(account.member_since).toLocaleDateString()}
                            </p>
                        </div>
                    </div>

                    {/* Security Score */}
                    <div className="acu-fieldset">
                        <div className="p-4">
                            <div className="flex items-center justify-between mb-3">
                                <span className="text-xs font-semibold uppercase tracking-wide text-[var(--acu-text-muted)]">
                                    Security
                                </span>
                                <div
                                    className="w-8 h-8 rounded-lg flex items-center justify-center"
                                    style={{ backgroundColor: '#3B82F615', color: '#3B82F6' }}
                                >
                                    <i className="pi pi-shield text-sm" />
                                </div>
                            </div>
                            <div className="text-2xl font-bold text-[var(--acu-text)]">{completionPercent}%</div>
                            <p className="text-xs text-[var(--acu-text-light)] mt-1">
                                {completedChecks} of {verificationChecks.length} checks passed
                            </p>
                        </div>
                    </div>

                    {/* Active Sessions */}
                    <div className="acu-fieldset">
                        <div className="p-4">
                            <div className="flex items-center justify-between mb-3">
                                <span className="text-xs font-semibold uppercase tracking-wide text-[var(--acu-text-muted)]">
                                    Active Sessions
                                </span>
                                <div
                                    className="w-8 h-8 rounded-lg flex items-center justify-center"
                                    style={{ backgroundColor: '#8B5CF615', color: '#8B5CF6' }}
                                >
                                    <i className="pi pi-desktop text-sm" />
                                </div>
                            </div>
                            <div className="text-2xl font-bold text-[var(--acu-text)]">{active_session_count}</div>
                            <p className="text-xs text-[var(--acu-text-light)] mt-1">
                                {account.last_login_at
                                    ? `Last login ${formatRelativeTime(account.last_login_at)}`
                                    : 'First session'}
                            </p>
                        </div>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Verification Checklist */}
                    <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-blue)' } as React.CSSProperties}>
                        <div className="acu-fieldset-header">
                            <div className="acu-fieldset-title">
                                <i className="pi pi-check-square" />
                                <span>Account Verification</span>
                            </div>
                            <span className="text-xs font-semibold text-[var(--acu-text-light)]">
                                {completedChecks}/{verificationChecks.length}
                            </span>
                        </div>
                        <div className="acu-fieldset-body">
                            {/* Progress bar */}
                            <div className="w-full bg-[var(--acu-bg-alt)] rounded-full h-2 mb-4">
                                <div
                                    className="h-2 rounded-full transition-all duration-500"
                                    style={{
                                        width: `${completionPercent}%`,
                                        backgroundColor: completionPercent === 100 ? '#10B981' : '#3B82F6',
                                    }}
                                />
                            </div>

                            <div className="space-y-3">
                                {verificationChecks.map((check) => (
                                    <div key={check.label} className="flex items-center gap-3">
                                        <div
                                            className="w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0"
                                            style={{
                                                backgroundColor: check.done ? '#10B98120' : '#6B728020',
                                                color: check.done ? '#10B981' : '#9CA3AF',
                                            }}
                                        >
                                            <i className={check.done ? 'pi pi-check text-xs' : `${check.icon} text-xs`} />
                                        </div>
                                        <span
                                            className="text-sm font-medium"
                                            style={{ color: check.done ? 'var(--acu-text)' : 'var(--acu-text-light)' }}
                                        >
                                            {check.label}
                                        </span>
                                        {check.done ? (
                                            <span className="ml-auto text-xs text-green-600 font-medium">Completed</span>
                                        ) : (
                                            <Link
                                                href="/settings"
                                                className="ml-auto text-xs font-semibold text-[var(--acu-primary)] hover:underline"
                                            >
                                                Set up
                                            </Link>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* Recent Sessions */}
                    <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-purple)' } as React.CSSProperties}>
                        <div className="acu-fieldset-header">
                            <div className="acu-fieldset-title">
                                <i className="pi pi-history" />
                                <span>Recent Sessions</span>
                            </div>
                            <Link
                                href="/settings/security/log"
                                className="text-xs font-semibold text-[var(--acu-primary)] hover:underline flex items-center gap-1"
                            >
                                View all <i className="pi pi-arrow-right text-xs" />
                            </Link>
                        </div>
                        <div className="acu-fieldset-body">
                            {sessions.length === 0 ? (
                                <p className="text-sm text-[var(--acu-text-light)] text-center py-4">No recent sessions</p>
                            ) : (
                                <div className="space-y-2">
                                    {sessions.map((session, i) => (
                                        <div
                                            key={i}
                                            className="flex items-center gap-3 p-3 rounded-lg bg-[var(--acu-bg-alt)]"
                                        >
                                            <div
                                                className="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
                                                style={{
                                                    backgroundColor: session.is_current ? '#10B98120' : '#6B728015',
                                                    color: session.is_current ? '#10B981' : 'var(--acu-text-muted)',
                                                }}
                                            >
                                                <i className={`${getDeviceIcon(session.device_type)} text-sm`} />
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center gap-2">
                                                    <span className="text-sm font-medium text-[var(--acu-text)]">
                                                        {session.browser} on {session.platform}
                                                    </span>
                                                    {session.is_current && (
                                                        <span className="text-[10px] font-semibold uppercase px-1.5 py-0.5 rounded bg-green-100 text-green-700">
                                                            Current
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="text-xs text-[var(--acu-text-light)]">
                                                    {session.ip_address}
                                                    {session.city && ` \u2022 ${session.city}`}
                                                    {session.country_code && `, ${session.country_code}`}
                                                </div>
                                            </div>
                                            <span className="text-xs text-[var(--acu-text-light)] flex-shrink-0">
                                                {session.last_active_at
                                                    ? formatRelativeTime(session.last_active_at)
                                                    : '\u2014'}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                {/* Quick Actions */}
                <div className="acu-fieldset">
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-bolt" />
                            <span>Quick Actions</span>
                        </div>
                    </div>
                    <div className="acu-fieldset-body">
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <Link
                                href="/settings/profile"
                                className="flex items-center gap-3 p-3 rounded-lg bg-[var(--acu-bg-alt)] hover:bg-[var(--acu-bg-hover)] transition-colors"
                            >
                                <div className="w-9 h-9 rounded-lg flex items-center justify-center" style={{ backgroundColor: '#3B82F615', color: '#3B82F6' }}>
                                    <i className="pi pi-user-edit text-sm" />
                                </div>
                                <div>
                                    <div className="text-sm font-medium text-[var(--acu-text)]">Edit Profile</div>
                                    <div className="text-xs text-[var(--acu-text-light)]">Update your details</div>
                                </div>
                            </Link>
                            <Link
                                href="/settings/security/log"
                                className="flex items-center gap-3 p-3 rounded-lg bg-[var(--acu-bg-alt)] hover:bg-[var(--acu-bg-hover)] transition-colors"
                            >
                                <div className="w-9 h-9 rounded-lg flex items-center justify-center" style={{ backgroundColor: '#10B98115', color: '#10B981' }}>
                                    <i className="pi pi-shield text-sm" />
                                </div>
                                <div>
                                    <div className="text-sm font-medium text-[var(--acu-text)]">Security Settings</div>
                                    <div className="text-xs text-[var(--acu-text-light)]">Password & 2FA</div>
                                </div>
                            </Link>
                            <Link
                                href="/settings/sessions"
                                className="flex items-center gap-3 p-3 rounded-lg bg-[var(--acu-bg-alt)] hover:bg-[var(--acu-bg-hover)] transition-colors"
                            >
                                <div className="w-9 h-9 rounded-lg flex items-center justify-center" style={{ backgroundColor: '#8B5CF615', color: '#8B5CF6' }}>
                                    <i className="pi pi-desktop text-sm" />
                                </div>
                                <div>
                                    <div className="text-sm font-medium text-[var(--acu-text)]">Manage Sessions</div>
                                    <div className="text-xs text-[var(--acu-text-light)]">View active sessions</div>
                                </div>
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </UserLayout>
    );
}
