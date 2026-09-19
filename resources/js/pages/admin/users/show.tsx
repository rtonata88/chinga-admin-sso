// resources/js/pages/admin/users/show.tsx
//
// User detail page, brass-on-ink to match /tenant-overview. Layout
// is: page head (with admin actions) → KPI strip (computed from
// fantasy bet history) → Profile + Account info panels → Fantasy
// bets table. The Reset Password flow stays on PrimeReact Dialog —
// it's a functional modal, not chrome.

import { KpiCard, formatCount, formatNAD } from '@/components/operator/kpi-card';
import UserLayout from '@/layouts/user-layout';
import { Head, router } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { InputText } from 'primereact/inputtext';
import { Toast } from 'primereact/toast';
import { useEffect, useMemo, useRef, useState } from 'react';

interface UserDetail {
    uuid: string;
    name: string;
    email: string;
    username: string | null;
    phone: string | null;
    display_name: string | null;
    date_of_birth: string | null;
    country_code: string | null;
    timezone: string;
    language: string;
    status: string;
    user_type?: string;
    roles: string[];
    email_verified_at: string | null;
    last_login_at: string | null;
    created_at: string;
    updated_at: string;
}

interface Pick {
    team_id: number;
    team_name: string | null;
    odds: string;
    won: boolean;
}

interface Bet {
    id: number;
    round_id: number;
    round_number: number;
    bet_amount: string;
    winning_amount: string;
    outcome: 'pending' | 'win' | 'lost';
    credit_status: string | null;
    placed_at: string;
    picks: Pick[];
}

const ROLE_LABELS: Record<string, string> = {
    platform_super_admin: 'Super Admin',
    platform_admin: 'Platform Admin',
    tenant_admin: 'Admin',
    tenant_manager: 'Manager',
    player: 'Player',
};

function statusPill(status: string): string {
    switch (status) {
        case 'active': return 'live';
        case 'suspended': return 'pending';
        case 'banned': return 'flagged';
        case 'self_excluded': return 'void';
        default: return 'void';
    }
}

function outcomePill(outcome: Bet['outcome']): string {
    if (outcome === 'win') return 'live';
    if (outcome === 'lost') return 'flagged';
    return 'pending';
}

const DATE_FMT = new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
const DATETIME_FMT = new Intl.DateTimeFormat('en-GB', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
});

function formatDate(iso: string | null): string {
    return iso ? DATE_FMT.format(new Date(iso)) : '—';
}

function formatDateTime(iso: string | null): string {
    return iso ? DATETIME_FMT.format(new Date(iso)) : '—';
}

function getCsrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

interface InfoItem {
    label: string;
    value: React.ReactNode;
    mono?: boolean;
    span?: number;
}

function InfoPanel({ title, items }: { title: string; items: InfoItem[] }) {
    return (
        <div style={{ border: '1px solid var(--cg-rule)', borderRadius: 8, overflow: 'hidden', background: 'var(--cg-ink-card)' }}>
            <div className="cgo-table-bar">
                <div className="cgo-table-bar-title">{title}</div>
            </div>
            <div
                style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(12, 1fr)',
                    gap: '14px 18px',
                    padding: '18px',
                }}
            >
                {items.map((it, i) => (
                    <div key={i} style={{ gridColumn: `span ${it.span ?? 4}` }}>
                        <div
                            style={{
                                fontSize: 10,
                                textTransform: 'uppercase',
                                letterSpacing: '0.16em',
                                color: 'var(--cg-fg-3)',
                                fontWeight: 600,
                                marginBottom: 4,
                            }}
                        >
                            {it.label}
                        </div>
                        <div
                            style={{
                                fontSize: 13,
                                color: 'var(--cg-fg-1)',
                                fontFamily: it.mono ? 'var(--cg-mono)' : undefined,
                                fontFeatureSettings: it.mono ? "'tnum' 1" : undefined,
                                wordBreak: 'break-word',
                            }}
                        >
                            {it.value}
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

export default function UserShow({ uuid }: { uuid: string }) {
    const [user, setUser] = useState<UserDetail | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [bets, setBets] = useState<Bet[]>([]);
    const [betsLoading, setBetsLoading] = useState(true);
    const [passwordDialog, setPasswordDialog] = useState(false);
    const [newPassword, setNewPassword] = useState('');
    const [tempPassword, setTempPassword] = useState<string | null>(null);
    const [resetting, setResetting] = useState(false);
    const toast = useRef<Toast>(null);

    const fetchUser = async () => {
        try {
            const response = await fetch(`/api/v1/admin/users/${uuid}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                setError(`Failed to load user (${response.status})`);
                return;
            }
            const data = await response.json();
            if (data.success) {
                setUser(data.data);
            } else {
                setError(data.message || 'Failed to load user');
            }
        } catch {
            setError('Failed to connect to the server');
        } finally {
            setLoading(false);
        }
    };

    const fetchBets = async () => {
        setBetsLoading(true);
        try {
            const response = await fetch(`/api/v1/admin/users/${uuid}/fantasy-bets?limit=50`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (response.ok && data.success) {
                setBets(data.data ?? []);
            }
        } catch {
            // Soft failure — bets section just shows empty.
        } finally {
            setBetsLoading(false);
        }
    };

    useEffect(() => {
        fetchUser();
        fetchBets();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [uuid]);

    const kpis = useMemo(() => {
        const total = bets.length;
        let wagered = 0;
        let won = 0;
        for (const b of bets) {
            wagered += parseFloat(b.bet_amount) || 0;
            if (b.outcome === 'win') {
                won += parseFloat(b.winning_amount) || 0;
            }
        }
        return { total, wagered, won, net: won - wagered };
    }, [bets]);

    const apiCall = async (url: string, method: string = 'POST', body?: Record<string, unknown>) => {
        const response = await fetch(url, {
            method,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
            credentials: 'same-origin',
            body: body ? JSON.stringify(body) : undefined,
        });
        return response.json();
    };

    const handleResetPassword = async () => {
        setResetting(true);
        setTempPassword(null);
        try {
            const data = await apiCall(`/api/v1/admin/users/${uuid}/reset-password`, 'POST', {
                new_password: newPassword || undefined,
                send_email: false,
            });
            if (data.success) {
                setTempPassword(data.temporary_password || null);
                toast.current?.show({ severity: 'success', summary: 'Password reset', detail: 'Password has been reset.', life: 5000 });
            } else {
                toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || 'Failed to reset password.', life: 5000 });
            }
        } catch {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to reset password.', life: 5000 });
        } finally {
            setResetting(false);
        }
    };

    const handleSuspend = async () => {
        if (!confirm('Suspend this user?')) return;
        const data = await apiCall(`/api/v1/admin/users/${uuid}/suspend`);
        if (data.success) {
            fetchUser();
            toast.current?.show({ severity: 'warn', summary: 'Suspended', detail: 'User has been suspended.', life: 3000 });
        }
    };

    const handleActivate = async () => {
        const data = await apiCall(`/api/v1/admin/users/${uuid}/activate`);
        if (data.success) {
            fetchUser();
            toast.current?.show({ severity: 'success', summary: 'Activated', detail: 'User has been activated.', life: 3000 });
        }
    };

    const closePasswordDialog = () => {
        setPasswordDialog(false);
        setNewPassword('');
        setTempPassword(null);
    };

    if (loading) {
        return (
            <UserLayout title="User">
                <Head title="Loading…" />
                <div className="cgo-page">
                    <div style={{ color: 'var(--cg-fg-3)', padding: '40px 0' }}>Loading user…</div>
                </div>
            </UserLayout>
        );
    }

    if (!user) {
        return (
            <UserLayout title="User">
                <Head title="User not found" />
                <div className="cgo-page">
                    <div className="cgo-page-head">
                        <div>
                            <div className="cgo-eyebrow">Admin</div>
                            <h1 className="cgo-title">User not found</h1>
                            <div className="cgo-subtitle">{error || 'This user does not exist.'}</div>
                        </div>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={() => router.visit('/admin/users')}
                        >
                            ← Back to users
                        </button>
                    </div>
                </div>
            </UserLayout>
        );
    }

    const profileItems: InfoItem[] = [
        { label: 'Full name', value: user.name },
        { label: 'Username', value: user.username || '—' },
        { label: 'Display name', value: user.display_name || '—' },
        { label: 'Email', value: user.email },
        { label: 'Phone', value: user.phone || '—' },
        { label: 'Date of birth', value: user.date_of_birth || '—' },
        { label: 'Country', value: user.country_code || '—' },
        { label: 'Timezone', value: user.timezone, mono: true },
        { label: 'Language', value: user.language, mono: true },
    ];

    const accountItems: InfoItem[] = [
        {
            label: 'Status',
            value: (
                <span className={`cgo-pill ${statusPill(user.status)}`}>
                    {user.status.replace('_', ' ')}
                </span>
            ),
            span: 3,
        },
        {
            label: 'Type',
            value: <span style={{ textTransform: 'capitalize' }}>{user.user_type || 'direct'}</span>,
            span: 3,
        },
        {
            label: 'Email verified',
            value: user.email_verified_at
                ? <span style={{ color: 'var(--cg-pos)' }}>✓ {formatDate(user.email_verified_at)}</span>
                : <span style={{ color: 'var(--cg-fg-3)' }}>not verified</span>,
            span: 3,
        },
        {
            label: 'Last login',
            value: formatDateTime(user.last_login_at),
            span: 3,
        },
        {
            label: 'Roles',
            value: user.roles.length === 0
                ? <span style={{ color: 'var(--cg-fg-3)' }}>none assigned</span>
                : (
                    <div style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
                        {user.roles.map((r) => (
                            <span
                                key={r}
                                style={{
                                    display: 'inline-block',
                                    padding: '2px 8px',
                                    border: '1px solid var(--cg-rule-strong)',
                                    borderRadius: 999,
                                    fontSize: 10,
                                    color: 'var(--cg-fg-2)',
                                    fontFamily: 'var(--cg-mono)',
                                    letterSpacing: '0.04em',
                                }}
                            >
                                {ROLE_LABELS[r] || r}
                            </span>
                        ))}
                    </div>
                ),
            span: 6,
        },
        { label: 'UUID', value: user.uuid, mono: true, span: 6 },
        { label: 'Created', value: formatDate(user.created_at), span: 3 },
        { label: 'Updated', value: formatDate(user.updated_at), span: 3 },
    ];

    return (
        <UserLayout title={user.name}>
            <Head title={`${user.name} · User`} />
            <Toast ref={toast} />

            <div className="cgo-page">
                {/* Page header */}
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">User</div>
                        <h1 className="cgo-title">{user.name}</h1>
                        <div className="cgo-subtitle" style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                            <span>{user.email}</span>
                            <span className={`cgo-pill ${statusPill(user.status)}`}>
                                {user.status.replace('_', ' ')}
                            </span>
                        </div>
                    </div>
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={() => router.visit('/admin/users')}
                        >
                            ← Back
                        </button>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={() => setPasswordDialog(true)}
                        >
                            Reset password
                        </button>
                        {user.status === 'active' ? (
                            <button
                                type="button"
                                className="cg-btn cg-btn--ghost cg-btn--sm"
                                style={{ borderColor: 'var(--cg-neg)', color: 'var(--cg-neg)' }}
                                onClick={handleSuspend}
                            >
                                Suspend
                            </button>
                        ) : (
                            <button
                                type="button"
                                className="cg-btn cg-btn--primary cg-btn--sm"
                                onClick={handleActivate}
                            >
                                Activate
                            </button>
                        )}
                    </div>
                </div>

                {/* KPI strip — derived from fantasy bet history */}
                <div className="cgo-kpis">
                    <KpiCard
                        label="Bets placed"
                        value={betsLoading ? '—' : formatCount(kpis.total)}
                        meta="lifetime"
                    />
                    <KpiCard
                        label="Total wagered"
                        value={betsLoading ? '—' : `N$${formatNAD(kpis.wagered)}`}
                        meta="lifetime"
                    />
                    <KpiCard
                        label="Total wins"
                        value={betsLoading ? '—' : `N$${formatNAD(kpis.won)}`}
                        meta="winning bets only"
                    />
                    <KpiCard
                        label="Net P/L"
                        value={betsLoading ? '—' : `${kpis.net >= 0 ? '+' : '−'}N$${formatNAD(Math.abs(kpis.net))}`}
                        brass={kpis.net >= 0}
                        meta={kpis.net >= 0 ? 'player up' : 'player down'}
                    />
                </div>

                {/* Profile + Account */}
                <div
                    style={{
                        display: 'grid',
                        gridTemplateColumns: '1fr 1fr',
                        gap: 16,
                        marginBottom: 24,
                    }}
                >
                    <InfoPanel title="Profile" items={profileItems} />
                    <InfoPanel title="Account" items={accountItems} />
                </div>

                {/* Fantasy bets */}
                <div
                    className="cgo-table-bar"
                    style={{
                        borderRadius: '8px 8px 0 0',
                        borderTop: '1px solid var(--cg-rule)',
                        borderLeft: '1px solid var(--cg-rule)',
                        borderRight: '1px solid var(--cg-rule)',
                    }}
                >
                    <div className="cgo-table-bar-title">Fantasy bets · last 50</div>
                </div>
                <div
                    className="cgo-table-wrap cgo-table-wrap--scroll"
                    style={{ borderRadius: '0 0 8px 8px', marginBottom: 32 }}
                >
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ width: 150 }}>Placed</th>
                                <th style={{ width: 90 }}>Round</th>
                                <th>Picks</th>
                                <th className="cgo-r" style={{ width: 110 }}>Stake</th>
                                <th className="cgo-r" style={{ width: 110 }}>Payout</th>
                                <th style={{ width: 90 }}>Outcome</th>
                            </tr>
                        </thead>
                        <tbody>
                            {betsLoading ? (
                                <tr>
                                    <td colSpan={6} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        Loading bets…
                                    </td>
                                </tr>
                            ) : bets.length === 0 ? (
                                <tr>
                                    <td colSpan={6} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        No bets placed yet.
                                    </td>
                                </tr>
                            ) : (
                                bets.map((b) => (
                                    <tr key={b.id}>
                                        <td>
                                            <span className="cgo-uid">{formatDateTime(b.placed_at)}</span>
                                        </td>
                                        <td>
                                            <a
                                                href={`/admin/fantasy/rounds/${b.round_id}`}
                                                className="cgo-uid"
                                                style={{ color: 'var(--cg-fg-2)', textDecoration: 'none' }}
                                            >
                                                #{b.round_number}
                                            </a>
                                        </td>
                                        <td style={{ maxWidth: 320 }}>
                                            <div
                                                className="cgo-cell-clip"
                                                title={b.picks.map((p) => `${p.team_name || `Team ${p.team_id}`} (${p.odds})`).join(', ')}
                                                style={{ fontSize: 12, color: 'var(--cg-fg-2)' }}
                                            >
                                                {b.picks.map((p) => `${p.team_name || `Team ${p.team_id}`} (${p.odds})`).join(', ')}
                                            </div>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-stake">
                                                <span className="cgo-ccy">NAD</span>
                                                {formatNAD(parseFloat(b.bet_amount))}
                                            </span>
                                        </td>
                                        <td className="cgo-r">
                                            {b.outcome === 'win' ? (
                                                <span className="cgo-payout" style={{ color: 'var(--cg-pos)' }}>
                                                    {formatNAD(parseFloat(b.winning_amount))}
                                                </span>
                                            ) : (
                                                <span className="cgo-payout" style={{ color: 'var(--cg-fg-4)' }}>
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td>
                                            <span className={`cgo-pill ${outcomePill(b.outcome)}`}>
                                                {b.outcome}
                                            </span>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Reset password dialog (PrimeReact — functional modal) */}
            <Dialog
                header="Reset password"
                visible={passwordDialog}
                style={{ width: '28rem' }}
                onHide={closePasswordDialog}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button label={tempPassword ? 'Close' : 'Cancel'} severity="secondary" outlined onClick={closePasswordDialog} />
                        {!tempPassword && (
                            <Button
                                label={resetting ? 'Resetting…' : 'Reset password'}
                                onClick={handleResetPassword}
                                disabled={resetting}
                                loading={resetting}
                            />
                        )}
                    </div>
                }
            >
                {tempPassword ? (
                    <div className="space-y-4">
                        <div style={{ fontWeight: 500 }}>Password reset successfully.</div>
                        <div style={{ background: 'var(--cg-ink-elevated)', border: '1px solid var(--cg-rule)', borderRadius: 6, padding: 14 }}>
                            <div style={{ fontSize: 10, textTransform: 'uppercase', letterSpacing: '0.16em', color: 'var(--cg-fg-3)', marginBottom: 4 }}>
                                New password
                            </div>
                            <code style={{ display: 'block', fontSize: '1.1rem', fontFamily: 'var(--cg-mono)', color: 'var(--cg-brass-hi)', letterSpacing: '0.05em' }}>
                                {tempPassword}
                            </code>
                        </div>
                        <p style={{ fontSize: 12, color: 'var(--cg-fg-3)' }}>
                            Copy this password now — it will not be shown again.
                        </p>
                    </div>
                ) : (
                    <div className="space-y-4">
                        <p style={{ fontSize: '0.875rem' }}>
                            Set a new password for <strong>{user.name}</strong> ({user.email}).
                            Leave blank to generate a random password.
                        </p>
                        <div>
                            <label style={{ fontSize: 12, fontWeight: 500 }}>New password (optional)</label>
                            <InputText
                                type="text"
                                placeholder="Leave blank for random password"
                                value={newPassword}
                                onChange={(e) => setNewPassword(e.target.value)}
                                className="w-full mt-1"
                            />
                        </div>
                    </div>
                )}
            </Dialog>
        </UserLayout>
    );
}
