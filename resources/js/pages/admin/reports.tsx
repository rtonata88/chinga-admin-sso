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

interface StatRow {
    label: string;
    value: string | number;
    color?: string;
    icon?: string;
}

function StatList({ items }: { items: StatRow[] }) {
    return (
        <div className="space-y-4">
            {items.map((item) => (
                <div
                    key={item.label}
                    className="flex items-center justify-between rounded-lg px-4 py-3"
                    style={{
                        background: 'linear-gradient(135deg, rgba(201, 168, 76, 0.04) 0%, transparent 100%)',
                        border: '1px solid var(--acu-border)',
                    }}
                >
                    <div className="flex items-center gap-3">
                        {item.icon && (
                            <i
                                className={`${item.icon} text-sm`}
                                style={{ color: item.color || 'var(--acu-text-muted)' }}
                            />
                        )}
                        <span
                            className="text-sm"
                            style={{ color: 'var(--acu-text-muted)', fontFamily: 'var(--font-body)' }}
                        >
                            {item.label}
                        </span>
                    </div>
                    <span
                        className="text-base font-bold tracking-wide"
                        style={{
                            color: item.color || 'var(--acu-text)',
                            fontFamily: 'var(--font-display)',
                        }}
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
    const [loading, setLoading] = useState(true);

    const fetchReports = async () => {
        setLoading(true);
        try {
            const [regRes, loginRes] = await Promise.all([
                fetch('/api/v1/admin/reports/registrations', {
                    headers: { Accept: 'application/json' },
                }),
                fetch('/api/v1/admin/reports/logins', {
                    headers: { Accept: 'application/json' },
                }),
            ]);

            const [regData, loginData] = await Promise.all([
                regRes.json(),
                loginRes.json(),
            ]);

            if (regData.success) setRegistrations(regData.data);
            if (loginData.success) setLogins(loginData.data);
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

            <div className="space-y-8">
                <PageHeader
                    title="Reports & Analytics"
                    subtitle="Platform statistics and insights (last 30 days)"
                />

                {loading ? (
                    <div
                        className="text-center py-16 text-base"
                        style={{ color: 'var(--acu-text-muted)', fontFamily: 'var(--font-body)' }}
                    >
                        Loading reports...
                    </div>
                ) : (
                    <div className="grid gap-5 md:grid-cols-2">
                        {/* Registrations */}
                        <div
                            className="rounded-xl overflow-hidden transition-all duration-300"
                            style={{
                                background: 'var(--acu-surface-card)',
                                border: '1px solid var(--acu-border)',
                                boxShadow: '0 0 0 0 rgba(201, 168, 76, 0)',
                            }}
                            onMouseEnter={(e) => {
                                e.currentTarget.style.boxShadow = '0 0 20px rgba(201, 168, 76, 0.08)';
                                e.currentTarget.style.borderColor = 'rgba(201, 168, 76, 0.3)';
                            }}
                            onMouseLeave={(e) => {
                                e.currentTarget.style.boxShadow = '0 0 0 0 rgba(201, 168, 76, 0)';
                                e.currentTarget.style.borderColor = 'var(--acu-border)';
                            }}
                        >
                            <div
                                className="px-5 py-4 flex items-center gap-3"
                                style={{
                                    background: 'linear-gradient(135deg, rgba(88, 166, 255, 0.08) 0%, transparent 100%)',
                                    borderBottom: '1px solid var(--acu-border)',
                                }}
                            >
                                <i
                                    className="pi pi-users text-lg"
                                    style={{ color: '#58A6FF' }}
                                />
                                <h3
                                    className="text-base font-semibold tracking-wide"
                                    style={{ color: 'var(--acu-text)', fontFamily: 'var(--font-display)' }}
                                >
                                    Registrations
                                </h3>
                            </div>
                            <div className="p-5">
                                {registrations ? (
                                    <StatList
                                        items={[
                                            {
                                                label: 'Total Registrations',
                                                value: registrations.total.toLocaleString(),
                                                icon: 'pi pi-user-plus',
                                                color: '#58A6FF',
                                            },
                                            {
                                                label: 'Email Verification Rate',
                                                value: `${registrations.email_verified_rate.toFixed(1)}%`,
                                                icon: 'pi pi-check-circle',
                                                color: registrations.email_verified_rate >= 70
                                                    ? '#3FB950'
                                                    : registrations.email_verified_rate >= 40
                                                        ? '#D29922'
                                                        : '#F85149',
                                            },
                                        ]}
                                    />
                                ) : (
                                    <p
                                        className="text-sm text-center py-4"
                                        style={{ color: 'var(--acu-text-muted)', fontFamily: 'var(--font-body)' }}
                                    >
                                        No data available
                                    </p>
                                )}
                            </div>
                        </div>

                        {/* Login Activity */}
                        <div
                            className="rounded-xl overflow-hidden transition-all duration-300"
                            style={{
                                background: 'var(--acu-surface-card)',
                                border: '1px solid var(--acu-border)',
                                boxShadow: '0 0 0 0 rgba(201, 168, 76, 0)',
                            }}
                            onMouseEnter={(e) => {
                                e.currentTarget.style.boxShadow = '0 0 20px rgba(201, 168, 76, 0.08)';
                                e.currentTarget.style.borderColor = 'rgba(201, 168, 76, 0.3)';
                            }}
                            onMouseLeave={(e) => {
                                e.currentTarget.style.boxShadow = '0 0 0 0 rgba(201, 168, 76, 0)';
                                e.currentTarget.style.borderColor = 'var(--acu-border)';
                            }}
                        >
                            <div
                                className="px-5 py-4 flex items-center gap-3"
                                style={{
                                    background: 'linear-gradient(135deg, rgba(63, 185, 80, 0.08) 0%, transparent 100%)',
                                    borderBottom: '1px solid var(--acu-border)',
                                }}
                            >
                                <i
                                    className="pi pi-sign-in text-lg"
                                    style={{ color: '#3FB950' }}
                                />
                                <h3
                                    className="text-base font-semibold tracking-wide"
                                    style={{ color: 'var(--acu-text)', fontFamily: 'var(--font-display)' }}
                                >
                                    Login Activity
                                </h3>
                            </div>
                            <div className="p-5">
                                {logins ? (
                                    <StatList
                                        items={[
                                            {
                                                label: 'Total Attempts',
                                                value: logins.total_attempts.toLocaleString(),
                                                icon: 'pi pi-arrow-right-arrow-left',
                                            },
                                            {
                                                label: 'Successful',
                                                value: logins.successful.toLocaleString(),
                                                icon: 'pi pi-check',
                                                color: '#3FB950',
                                            },
                                            {
                                                label: 'Failed',
                                                value: logins.failed.toLocaleString(),
                                                icon: 'pi pi-times',
                                                color: '#F85149',
                                            },
                                            {
                                                label: 'Active Sessions (24h)',
                                                value: logins.active_sessions_24h.toLocaleString(),
                                                icon: 'pi pi-bolt',
                                                color: '#C9A84C',
                                            },
                                        ]}
                                    />
                                ) : (
                                    <p
                                        className="text-sm text-center py-4"
                                        style={{ color: 'var(--acu-text-muted)', fontFamily: 'var(--font-body)' }}
                                    >
                                        No data available
                                    </p>
                                )}
                            </div>
                        </div>

                    </div>
                )}
            </div>
        </UserLayout>
    );
}
