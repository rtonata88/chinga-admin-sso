import UserLayout from '@/layouts/user-layout';
import PageHeader from '@/components/acumatica/Common/PageHeader';
import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface RegistrationStats {
    total: number;
    email_verified_rate: number;
}

interface LoginStats {
    total_attempts: number;
    successful: number;
    failed: number;
    active_sessions_24h: number;
}

interface KycStats {
    documents: {
        total: number;
        by_status: Record<string, number>;
    };
    users_by_level: Record<number, number>;
    completion_rate: number;
}

interface ResponsibleGamblingStats {
    self_exclusions: {
        total_in_period: number;
        currently_active: number;
    };
    users_with_limits: number;
}

interface StatRow {
    label: string;
    value: string | number;
    color?: string;
}

function StatList({ items }: { items: StatRow[] }) {
    return (
        <div className="space-y-3">
            {items.map((item) => (
                <div key={item.label} className="flex items-center justify-between">
                    <span className="text-sm text-[var(--acu-text-muted)]">{item.label}</span>
                    <span
                        className="text-sm font-bold"
                        style={{ color: item.color || 'var(--acu-text)' }}
                    >
                        {item.value}
                    </span>
                </div>
            ))}
        </div>
    );
}

export default function Reports() {
    const [registrations, setRegistrations] = useState<RegistrationStats | null>(null);
    const [logins, setLogins] = useState<LoginStats | null>(null);
    const [kyc, setKyc] = useState<KycStats | null>(null);
    const [responsibleGambling, setResponsibleGambling] = useState<ResponsibleGamblingStats | null>(null);
    const [loading, setLoading] = useState(true);

    const fetchReports = async () => {
        setLoading(true);
        try {
            const [regRes, loginRes, kycRes, rgRes] = await Promise.all([
                fetch('/api/v1/admin/reports/registrations', {
                    headers: { Accept: 'application/json' },
                }),
                fetch('/api/v1/admin/reports/logins', {
                    headers: { Accept: 'application/json' },
                }),
                fetch('/api/v1/admin/reports/kyc', {
                    headers: { Accept: 'application/json' },
                }),
                fetch('/api/v1/admin/reports/responsible-gambling', {
                    headers: { Accept: 'application/json' },
                }),
            ]);

            const [regData, loginData, kycData, rgData] = await Promise.all([
                regRes.json(),
                loginRes.json(),
                kycRes.json(),
                rgRes.json(),
            ]);

            if (regData.success) setRegistrations(regData.data);
            if (loginData.success) setLogins(loginData.data);
            if (kycData.success) setKyc(kycData.data);
            if (rgData.success) setResponsibleGambling(rgData.data);
        } catch (error) {
            console.error('Failed to fetch reports:', error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchReports();
    }, []);

    return (
        <UserLayout title="Reports">
            <Head title="Reports" />

            <div className="space-y-6">
                <PageHeader
                    title="Reports & Analytics"
                    subtitle="Platform statistics and insights (last 30 days)"
                />

                {loading ? (
                    <div className="text-center py-10 text-[var(--acu-text-muted)]">
                        Loading reports...
                    </div>
                ) : (
                    <div className="grid gap-6 md:grid-cols-2">
                        {/* Registrations */}
                        <div
                            className="acu-fieldset"
                            style={{ '--fieldset-color': 'var(--acu-fieldset-blue)' } as React.CSSProperties}
                        >
                            <div className="acu-fieldset-header">
                                <div className="acu-fieldset-title">
                                    <i className="pi pi-users" />
                                    <span>Registrations</span>
                                </div>
                            </div>
                            <div className="acu-fieldset-body">
                                {registrations ? (
                                    <StatList
                                        items={[
                                            {
                                                label: 'Total Registrations',
                                                value: registrations.total,
                                            },
                                            {
                                                label: 'Email Verification Rate',
                                                value: `${registrations.email_verified_rate.toFixed(1)}%`,
                                            },
                                        ]}
                                    />
                                ) : (
                                    <p className="text-sm text-[var(--acu-text-muted)]">No data available</p>
                                )}
                            </div>
                        </div>

                        {/* Login Activity */}
                        <div
                            className="acu-fieldset"
                            style={{ '--fieldset-color': 'var(--acu-fieldset-green)' } as React.CSSProperties}
                        >
                            <div className="acu-fieldset-header">
                                <div className="acu-fieldset-title">
                                    <i className="pi pi-sign-in" />
                                    <span>Login Activity</span>
                                </div>
                            </div>
                            <div className="acu-fieldset-body">
                                {logins ? (
                                    <StatList
                                        items={[
                                            {
                                                label: 'Total Attempts',
                                                value: logins.total_attempts,
                                            },
                                            {
                                                label: 'Successful',
                                                value: logins.successful,
                                                color: 'var(--acu-success)',
                                            },
                                            {
                                                label: 'Failed',
                                                value: logins.failed,
                                                color: 'var(--acu-danger)',
                                            },
                                            {
                                                label: 'Active Sessions (24h)',
                                                value: logins.active_sessions_24h,
                                            },
                                        ]}
                                    />
                                ) : (
                                    <p className="text-sm text-[var(--acu-text-muted)]">No data available</p>
                                )}
                            </div>
                        </div>

                        {/* KYC Verification */}
                        <div
                            className="acu-fieldset"
                            style={{ '--fieldset-color': 'var(--acu-fieldset-amber)' } as React.CSSProperties}
                        >
                            <div className="acu-fieldset-header">
                                <div className="acu-fieldset-title">
                                    <i className="pi pi-shield" />
                                    <span>KYC Verification</span>
                                </div>
                            </div>
                            <div className="acu-fieldset-body">
                                {kyc ? (
                                    <StatList
                                        items={[
                                            {
                                                label: 'Total Documents',
                                                value: kyc.documents.total,
                                            },
                                            {
                                                label: 'Pending',
                                                value: kyc.documents.by_status?.pending || 0,
                                                color: 'var(--acu-warning)',
                                            },
                                            {
                                                label: 'Completion Rate',
                                                value: `${kyc.completion_rate.toFixed(1)}%`,
                                            },
                                        ]}
                                    />
                                ) : (
                                    <p className="text-sm text-[var(--acu-text-muted)]">No data available</p>
                                )}
                            </div>
                        </div>

                        {/* Responsible Gambling */}
                        <div
                            className="acu-fieldset"
                            style={{ '--fieldset-color': 'var(--acu-fieldset-purple)' } as React.CSSProperties}
                        >
                            <div className="acu-fieldset-header">
                                <div className="acu-fieldset-title">
                                    <i className="pi pi-chart-bar" />
                                    <span>Responsible Gambling</span>
                                </div>
                            </div>
                            <div className="acu-fieldset-body">
                                {responsibleGambling ? (
                                    <StatList
                                        items={[
                                            {
                                                label: 'New Exclusions',
                                                value: responsibleGambling.self_exclusions.total_in_period,
                                            },
                                            {
                                                label: 'Currently Excluded',
                                                value: responsibleGambling.self_exclusions.currently_active,
                                            },
                                            {
                                                label: 'Users with Limits',
                                                value: responsibleGambling.users_with_limits,
                                            },
                                        ]}
                                    />
                                ) : (
                                    <p className="text-sm text-[var(--acu-text-muted)]">No data available</p>
                                )}
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </UserLayout>
    );
}
