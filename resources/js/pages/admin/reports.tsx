// resources/js/pages/admin/reports.tsx
//
// Platform reports & analytics, brass-on-ink to match
// /tenant-overview. KPI strip → two side-by-side detail panels
// (Registrations / Login activity). Window is fixed to last 30 days
// to match the controller — add a date range later if needed.

import { KpiCard, formatCount } from '@/components/operator/kpi-card';
import UserLayout from '@/layouts/user-layout';
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

interface InfoItem {
    label: string;
    value: React.ReactNode;
}

function InfoPanel({ title, items }: { title: string; items: InfoItem[] }) {
    return (
        <div
            style={{
                border: '1px solid var(--cg-rule)',
                borderRadius: 8,
                overflow: 'hidden',
                background: 'var(--cg-ink-card)',
            }}
        >
            <div className="cgo-table-bar">
                <div className="cgo-table-bar-title">{title}</div>
            </div>
            <div style={{ padding: 4 }}>
                {items.map((it, i) => (
                    <div
                        key={i}
                        style={{
                            display: 'flex',
                            justifyContent: 'space-between',
                            alignItems: 'baseline',
                            padding: '12px 16px',
                            borderBottom:
                                i === items.length - 1 ? 'none' : '1px solid var(--cg-rule)',
                        }}
                    >
                        <span style={{ fontSize: 12, color: 'var(--cg-fg-3)' }}>{it.label}</span>
                        <span
                            style={{
                                fontSize: 14,
                                color: 'var(--cg-fg-1)',
                                fontFamily: 'var(--cg-mono)',
                                fontFeatureSettings: "'tnum' 1",
                                fontWeight: 600,
                            }}
                        >
                            {it.value}
                        </span>
                    </div>
                ))}
            </div>
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

    const verifiedRate = registrations?.email_verified_rate ?? 0;
    const verifiedColor =
        verifiedRate >= 70 ? 'var(--cg-pos)'
        : verifiedRate >= 40 ? 'var(--cg-warn)'
        : 'var(--cg-neg)';

    const failureRate = logins && logins.total_attempts > 0
        ? (logins.failed / logins.total_attempts) * 100
        : 0;

    return (
        <UserLayout title="Reports">
            <Head title="Reports · Admin" />

            <div className="cgo-page">
                {/* Page header */}
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Admin</div>
                        <h1 className="cgo-title">Reports</h1>
                        <div className="cgo-subtitle">
                            Platform statistics for the last 30 days.
                        </div>
                    </div>
                    <div style={{ display: 'flex', gap: 8 }}>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={fetchReports}
                            disabled={loading}
                        >
                            {loading ? 'Loading…' : 'Refresh'}
                        </button>
                    </div>
                </div>

                {/* KPI strip · 5-up. */}
                <div className="cgo-kpis cgo-kpis--5">
                    <KpiCard
                        label="Registrations"
                        value={registrations ? formatCount(registrations.total) : '—'}
                        brass
                        meta="last 30 days"
                    />
                    <KpiCard
                        label="Email verified"
                        value={
                            registrations
                                ? `${registrations.email_verified_rate.toFixed(1)}%`
                                : '—'
                        }
                        meta={
                            verifiedRate >= 70
                                ? 'healthy'
                                : verifiedRate >= 40
                                ? 'review'
                                : 'low'
                        }
                    />
                    <KpiCard
                        label="Logins · successful"
                        value={logins ? formatCount(logins.successful) : '—'}
                        meta="last 30 days"
                    />
                    <KpiCard
                        label="Logins · failed"
                        value={logins ? formatCount(logins.failed) : '—'}
                        meta={
                            logins && logins.failed > 0
                                ? `${failureRate.toFixed(1)}% of attempts`
                                : 'all clear'
                        }
                    />
                    <KpiCard
                        label="Active sessions"
                        value={logins ? formatCount(logins.active_sessions_24h) : '—'}
                        meta="last 24 hours"
                    />
                </div>

                {/* Detail panels */}
                <div
                    style={{
                        display: 'grid',
                        gridTemplateColumns: '1fr 1fr',
                        gap: 16,
                        marginBottom: 24,
                    }}
                >
                    <InfoPanel
                        title="Registrations · last 30 days"
                        items={[
                            {
                                label: 'Total new accounts',
                                value: registrations ? formatCount(registrations.total) : '—',
                            },
                            {
                                label: 'Email verified rate',
                                value: registrations ? (
                                    <span style={{ color: verifiedColor }}>
                                        {registrations.email_verified_rate.toFixed(1)}%
                                    </span>
                                ) : '—',
                            },
                        ]}
                    />
                    <InfoPanel
                        title="Login activity · last 30 days"
                        items={[
                            {
                                label: 'Total attempts',
                                value: logins ? formatCount(logins.total_attempts) : '—',
                            },
                            {
                                label: 'Successful',
                                value: logins ? (
                                    <span style={{ color: 'var(--cg-pos)' }}>
                                        {formatCount(logins.successful)}
                                    </span>
                                ) : '—',
                            },
                            {
                                label: 'Failed',
                                value: logins && logins.failed > 0 ? (
                                    <span style={{ color: 'var(--cg-neg)' }}>
                                        {formatCount(logins.failed)}
                                    </span>
                                ) : (logins ? '0' : '—'),
                            },
                            {
                                label: 'Active sessions (24h)',
                                value: logins ? (
                                    <span style={{ color: 'var(--cg-brass-hi)' }}>
                                        {formatCount(logins.active_sessions_24h)}
                                    </span>
                                ) : '—',
                            },
                        ]}
                    />
                </div>
            </div>
        </UserLayout>
    );
}
