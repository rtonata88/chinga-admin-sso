import UserLayout from '@/layouts/user-layout';
import StatusBadge from '@/components/acumatica/Common/StatusBadge';
import PageHeader from '@/components/acumatica/Common/PageHeader';
import type { StatusVariant } from '@/types/acumatica';
import { Head, Link } from '@inertiajs/react';

interface Account {
    name: string;
    email: string;
    display_name: string | null;
    avatar_url: string | null;
    status: string;
    kyc_level: number;
    email_verified: boolean;
    phone_verified: boolean;
    two_factor_enabled: boolean;
    member_since: string;
    last_login_at: string | null;
}

interface KycDocument {
    uuid: string;
    document_type: string;
    status: string;
    rejection_reason: string | null;
    created_at: string;
    verified_at: string | null;
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

interface LoginAlert {
    device_type: string;
    browser: string;
    platform: string;
    city: string | null;
    country_code: string | null;
    is_new_device: boolean;
    is_new_location: boolean;
    created_at: string;
}

interface DashboardProps {
    account: Account;
    kyc_documents: KycDocument[];
    sessions: Session[];
    login_alerts: LoginAlert[];
    active_session_count: number;
}

function getKycLevelInfo(level: number): { name: string; color: string; icon: string } {
    const levels = [
        { name: 'Unverified', color: '#EF4444', icon: 'pi pi-times-circle' },
        { name: 'Basic', color: '#F59E0B', icon: 'pi pi-check-circle' },
        { name: 'Enhanced', color: '#3B82F6', icon: 'pi pi-verified' },
        { name: 'Full', color: '#10B981', icon: 'pi pi-verified' },
    ];
    return levels[level] || levels[0];
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

function getKycStatusVariant(status: string): StatusVariant {
    switch (status) {
        case 'approved': return 'active';
        case 'rejected': return 'error';
        case 'pending': return 'pending';
        default: return 'inactive';
    }
}

const defaultAccount: Account = {
    name: '',
    email: '',
    display_name: null,
    avatar_url: null,
    status: 'active',
    kyc_level: 0,
    email_verified: false,
    phone_verified: false,
    two_factor_enabled: false,
    member_since: new Date().toISOString(),
    last_login_at: null,
};

export default function Dashboard({
    account = defaultAccount,
    kyc_documents = [],
    sessions = [],
    login_alerts = [],
    active_session_count = 0,
}: Partial<DashboardProps>) {
    const kycInfo = getKycLevelInfo(account.kyc_level);

    const verificationChecks = [
        { label: 'Email verified', done: account.email_verified, icon: 'pi pi-envelope' },
        { label: 'Phone verified', done: account.phone_verified, icon: 'pi pi-phone' },
        { label: 'Two-factor auth', done: account.two_factor_enabled, icon: 'pi pi-shield' },
        { label: 'KYC verified', done: account.kyc_level >= 1, icon: 'pi pi-id-card' },
    ];

    const completedChecks = verificationChecks.filter(c => c.done).length;
    const completionPercent = Math.round((completedChecks / verificationChecks.length) * 100);

    return (
        <UserLayout title="Dashboard">
            <Head title="Dashboard" />

            <div className="space-y-6">
                <PageHeader title={`Welcome back, ${account.display_name || account.name}`} subtitle="Your account overview" />
                {/* Login Alerts */}
                {login_alerts.length > 0 && (
                    <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-warning)' } as React.CSSProperties}>
                        <div className="acu-fieldset-header">
                            <div className="acu-fieldset-title">
                                <i className="pi pi-exclamation-triangle" />
                                <span>Security Alerts</span>
                            </div>
                        </div>
                        <div className="acu-fieldset-body space-y-2">
                            {login_alerts.map((alert, i) => (
                                <div key={i} className="flex items-center gap-3 text-sm text-[var(--acu-text)]">
                                    <i className={`${getDeviceIcon(alert.device_type)} text-amber-500`} />
                                    <span>
                                        {alert.is_new_device ? 'New device' : 'New location'} detected:{' '}
                                        {alert.browser} on {alert.platform}
                                        {alert.city && ` from ${alert.city}`}
                                        {alert.country_code && `, ${alert.country_code}`}
                                    </span>
                                    <span className="ml-auto text-xs text-[var(--acu-text-light)]">
                                        {formatRelativeTime(alert.created_at)}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Top Stats Row */}
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
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

                    {/* KYC Level */}
                    <div className="acu-fieldset">
                        <div className="p-4">
                            <div className="flex items-center justify-between mb-3">
                                <span className="text-xs font-semibold uppercase tracking-wide text-[var(--acu-text-muted)]">
                                    KYC Level
                                </span>
                                <div
                                    className="w-8 h-8 rounded-lg flex items-center justify-center"
                                    style={{ backgroundColor: `${kycInfo.color}15`, color: kycInfo.color }}
                                >
                                    <i className={`${kycInfo.icon} text-sm`} />
                                </div>
                            </div>
                            <div className="text-2xl font-bold text-[var(--acu-text)]">{kycInfo.name}</div>
                            <p className="text-xs text-[var(--acu-text-light)] mt-1">
                                Level {account.kyc_level} of 3
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

                    {/* KYC Documents */}
                    <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-amber)' } as React.CSSProperties}>
                        <div className="acu-fieldset-header">
                            <div className="acu-fieldset-title">
                                <i className="pi pi-id-card" />
                                <span>Identity Documents</span>
                                <span className="text-xs font-normal text-[var(--acu-text-light)] ml-1">
                                    ({kyc_documents.length})
                                </span>
                            </div>
                            <Link
                                href="/settings/kyc"
                                className="text-xs font-semibold text-[var(--acu-primary)] hover:underline flex items-center gap-1"
                            >
                                Manage <i className="pi pi-arrow-right text-xs" />
                            </Link>
                        </div>
                        <div className="acu-fieldset-body">
                            {kyc_documents.length === 0 ? (
                                <div className="text-center py-6">
                                    <i className="pi pi-id-card text-3xl text-[var(--acu-text-muted)] mb-2" />
                                    <p className="text-sm text-[var(--acu-text-light)]">No documents uploaded yet</p>
                                    <Link
                                        href="/settings/kyc"
                                        className="inline-flex items-center gap-1 mt-2 text-xs font-semibold text-[var(--acu-primary)] hover:underline"
                                    >
                                        Upload document <i className="pi pi-upload text-xs" />
                                    </Link>
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    {kyc_documents.map((doc) => (
                                        <div
                                            key={doc.uuid}
                                            className="flex items-center gap-3 p-3 rounded-lg bg-[var(--acu-bg-alt)]"
                                        >
                                            <div className="w-8 h-8 rounded-lg flex items-center justify-center bg-[var(--acu-bg)] flex-shrink-0">
                                                <i className="pi pi-file text-sm text-[var(--acu-text-muted)]" />
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <div className="text-sm font-medium text-[var(--acu-text)] capitalize">
                                                    {doc.document_type?.replace(/_/g, ' ')}
                                                </div>
                                                <div className="text-xs text-[var(--acu-text-light)]">
                                                    Submitted {new Date(doc.created_at).toLocaleDateString()}
                                                </div>
                                                {doc.rejection_reason && (
                                                    <div className="text-xs text-red-500 mt-0.5">{doc.rejection_reason}</div>
                                                )}
                                            </div>
                                            <StatusBadge status={getKycStatusVariant(doc.status)} label={doc.status} />
                                        </div>
                                    ))}
                                </div>
                            )}
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

                {/* Quick Actions */}
                <div className="acu-fieldset">
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-bolt" />
                            <span>Quick Actions</span>
                        </div>
                    </div>
                    <div className="acu-fieldset-body">
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
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
                                href="/settings/kyc"
                                className="flex items-center gap-3 p-3 rounded-lg bg-[var(--acu-bg-alt)] hover:bg-[var(--acu-bg-hover)] transition-colors"
                            >
                                <div className="w-9 h-9 rounded-lg flex items-center justify-center" style={{ backgroundColor: '#F59E0B15', color: '#F59E0B' }}>
                                    <i className="pi pi-id-card text-sm" />
                                </div>
                                <div>
                                    <div className="text-sm font-medium text-[var(--acu-text)]">Verify Identity</div>
                                    <div className="text-xs text-[var(--acu-text-light)]">Upload KYC documents</div>
                                </div>
                            </Link>
                            <Link
                                href="/settings/responsible-gambling"
                                className="flex items-center gap-3 p-3 rounded-lg bg-[var(--acu-bg-alt)] hover:bg-[var(--acu-bg-hover)] transition-colors"
                            >
                                <div className="w-9 h-9 rounded-lg flex items-center justify-center" style={{ backgroundColor: '#8B5CF615', color: '#8B5CF6' }}>
                                    <i className="pi pi-heart text-sm" />
                                </div>
                                <div>
                                    <div className="text-sm font-medium text-[var(--acu-text)]">Responsible Gaming</div>
                                    <div className="text-xs text-[var(--acu-text-light)]">Limits & controls</div>
                                </div>
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </UserLayout>
    );
}
