import PageHeader from '@/components/acumatica/Common/PageHeader';
import StatusBadge from '@/components/acumatica/Common/StatusBadge';
import UserLayout from '@/layouts/user-layout';
import type { StatusVariant } from '@/types/acumatica';
import { Head, router } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { InputText } from 'primereact/inputtext';
import { Tag } from 'primereact/tag';
import { Toast } from 'primereact/toast';
import { useEffect, useRef, useState } from 'react';

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

const ROLE_LABELS: Record<string, string> = {
    platform_super_admin: 'Super Admin',
    platform_admin: 'Platform Admin',
    tenant_admin: 'Admin',
    tenant_manager: 'Manager',
    player: 'Player',
};

const ROLE_SEVERITIES: Record<string, 'danger' | 'warning' | 'info' | 'success'> = {
    platform_super_admin: 'danger',
    platform_admin: 'danger',
    tenant_admin: 'warning',
    tenant_manager: 'info',
    player: 'success',
};

function mapStatus(status: string): StatusVariant {
    const map: Record<string, StatusVariant> = {
        active: 'active',
        suspended: 'suspended',
        banned: 'error',
        self_excluded: 'inactive',
    };
    return map[status] || 'inactive';
}

function getCsrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

export default function UserShow({ uuid }: { uuid: string }) {
    const [user, setUser] = useState<UserDetail | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
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

    useEffect(() => { fetchUser(); }, [uuid]);

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
                toast.current?.show({ severity: 'success', summary: 'Password Reset', detail: 'Password has been reset successfully.', life: 5000 });
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
        if (!confirm('Are you sure you want to suspend this user?')) return;
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
            <UserLayout>
                <Head title="Loading..." />
                <div className="flex items-center justify-center p-12">
                    <i className="pi pi-spin pi-spinner" style={{ fontSize: '2rem', color: 'var(--acu-text-muted)' }} />
                </div>
            </UserLayout>
        );
    }

    if (!user) {
        return (
            <UserLayout>
                <Head title="User Not Found" />
                <div className="p-12 text-center">
                    <i className="pi pi-exclamation-circle mb-4" style={{ fontSize: '3rem', color: 'var(--acu-text-muted)' }} />
                    <p className="mb-6" style={{ color: 'var(--acu-text-muted)', fontFamily: 'var(--font-body)' }}>{error || 'User not found.'}</p>
                    <Button label="Back to Users" icon="pi pi-arrow-left" severity="secondary" outlined onClick={() => router.visit('/admin/users')} />
                </div>
            </UserLayout>
        );
    }

    return (
        <UserLayout>
            <Head title={user.name} />
            <Toast ref={toast} />

            <PageHeader title={user.name} subtitle={user.email}>
                <div className="flex gap-2 flex-wrap">
                    <Button label="Back" icon="pi pi-arrow-left" severity="secondary" outlined size="small" onClick={() => router.visit('/admin/users')} />
                    <Button label="Reset Password" icon="pi pi-key" severity="warning" size="small" onClick={() => setPasswordDialog(true)} />
                    {user.status === 'active' ? (
                        <Button label="Suspend" icon="pi pi-ban" severity="danger" outlined size="small" onClick={handleSuspend} />
                    ) : (
                        <Button label="Activate" icon="pi pi-check" severity="success" size="small" onClick={handleActivate} />
                    )}
                </div>
            </PageHeader>

            {/* Profile Fieldset */}
            <div className="space-y-6">
                <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-blue)' } as React.CSSProperties}>
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-user" />
                            Profile
                        </div>
                    </div>
                    <div className="acu-fieldset-content">
                        <div className="grid grid-cols-12 gap-4">
                            <div className="col-span-12 md:col-span-6 lg:col-span-4">
                                <label className="acu-field-label">Full Name</label>
                                <div className="acu-field-value">{user.name}</div>
                            </div>
                            <div className="col-span-12 md:col-span-6 lg:col-span-4">
                                <label className="acu-field-label">Username</label>
                                <div className="acu-field-value">{user.username || '—'}</div>
                            </div>
                            <div className="col-span-12 md:col-span-6 lg:col-span-4">
                                <label className="acu-field-label">Display Name</label>
                                <div className="acu-field-value">{user.display_name || '—'}</div>
                            </div>
                            <div className="col-span-12 md:col-span-6 lg:col-span-4">
                                <label className="acu-field-label">Email</label>
                                <div className="acu-field-value">{user.email}</div>
                            </div>
                            <div className="col-span-12 md:col-span-6 lg:col-span-4">
                                <label className="acu-field-label">Phone</label>
                                <div className="acu-field-value">{user.phone || '—'}</div>
                            </div>
                            <div className="col-span-12 md:col-span-6 lg:col-span-4">
                                <label className="acu-field-label">Date of Birth</label>
                                <div className="acu-field-value">{user.date_of_birth || '—'}</div>
                            </div>
                            <div className="col-span-12 md:col-span-6 lg:col-span-4">
                                <label className="acu-field-label">Country</label>
                                <div className="acu-field-value">{user.country_code || '—'}</div>
                            </div>
                            <div className="col-span-12 md:col-span-6 lg:col-span-4">
                                <label className="acu-field-label">Timezone</label>
                                <div className="acu-field-value">{user.timezone}</div>
                            </div>
                            <div className="col-span-12 md:col-span-6 lg:col-span-4">
                                <label className="acu-field-label">Language</label>
                                <div className="acu-field-value">{user.language}</div>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Account Fieldset */}
                <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-gold)' } as React.CSSProperties}>
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-shield" />
                            Account
                        </div>
                    </div>
                    <div className="acu-fieldset-content">
                        <div className="grid grid-cols-12 gap-4">
                            <div className="col-span-12 md:col-span-6 lg:col-span-3">
                                <label className="acu-field-label">Status</label>
                                <div className="mt-1">
                                    <StatusBadge status={mapStatus(user.status)} label={user.status.toUpperCase()} />
                                </div>
                            </div>
                            <div className="col-span-12 md:col-span-6 lg:col-span-3">
                                <label className="acu-field-label">User Type</label>
                                <div className="mt-1">
                                    <Tag
                                        value={user.user_type === 'voucher' ? 'Voucher' : 'Direct'}
                                        severity={user.user_type === 'voucher' ? 'warning' : 'info'}
                                    />
                                </div>
                            </div>
                            <div className="col-span-12 md:col-span-6 lg:col-span-3">
                                <label className="acu-field-label">Email Verified</label>
                                <div className="mt-1">
                                    {user.email_verified_at ? (
                                        <StatusBadge status="completed" label={new Date(user.email_verified_at).toLocaleDateString()} />
                                    ) : (
                                        <StatusBadge status="pending" label="Not Verified" />
                                    )}
                                </div>
                            </div>
                            <div className="col-span-12 md:col-span-6 lg:col-span-3">
                                <label className="acu-field-label">Last Login</label>
                                <div className="acu-field-value">
                                    {user.last_login_at ? new Date(user.last_login_at).toLocaleString() : '—'}
                                </div>
                            </div>
                            <div className="col-span-12 md:col-span-6">
                                <label className="acu-field-label">Roles</label>
                                <div className="flex gap-1.5 flex-wrap mt-1">
                                    {user.roles.map((r) => (
                                        <Tag
                                            key={r}
                                            value={ROLE_LABELS[r] || r}
                                            severity={ROLE_SEVERITIES[r] || 'info'}
                                        />
                                    ))}
                                    {user.roles.length === 0 && (
                                        <span style={{ color: 'var(--acu-text-muted)', fontSize: '0.85rem', fontFamily: 'var(--font-body)' }}>No roles assigned</span>
                                    )}
                                </div>
                            </div>
                            <div className="col-span-12 md:col-span-6">
                                <label className="acu-field-label">UUID</label>
                                <div className="acu-field-value">
                                    <code style={{ fontSize: '0.8rem', color: 'var(--acu-text-light)', letterSpacing: '0.02em' }}>{user.uuid}</code>
                                </div>
                            </div>
                            <div className="col-span-12 md:col-span-6 lg:col-span-3">
                                <label className="acu-field-label">Created</label>
                                <div className="acu-field-value">
                                    {user.created_at ? new Date(user.created_at).toLocaleDateString() : '—'}
                                </div>
                            </div>
                            <div className="col-span-12 md:col-span-6 lg:col-span-3">
                                <label className="acu-field-label">Updated</label>
                                <div className="acu-field-value">
                                    {user.updated_at ? new Date(user.updated_at).toLocaleDateString() : '—'}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* Reset Password Dialog */}
            <Dialog
                header="Reset Password"
                visible={passwordDialog}
                style={{ width: '28rem' }}
                onHide={closePasswordDialog}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button label={tempPassword ? 'Close' : 'Cancel'} icon="pi pi-times" severity="secondary" outlined onClick={closePasswordDialog} />
                        {!tempPassword && (
                            <Button
                                label={resetting ? 'Resetting...' : 'Reset Password'}
                                icon="pi pi-key"
                                severity="warning"
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
                        <div className="flex items-center gap-2" style={{ color: 'var(--green-500)' }}>
                            <i className="pi pi-check-circle" />
                            <span style={{ fontWeight: 500, fontFamily: 'var(--font-body)' }}>Password reset successfully!</span>
                        </div>
                        <div className="rounded-lg p-4" style={{ background: 'var(--acu-surface-elevated)', border: '1px solid var(--acu-border)' }}>
                            <label className="acu-field-label">New Password</label>
                            <code style={{ display: 'block', fontSize: '1.2rem', color: 'var(--acu-primary)', letterSpacing: '0.05em', marginTop: '0.25rem', fontWeight: 600 }}>
                                {tempPassword}
                            </code>
                        </div>
                        <p style={{ fontSize: '0.8rem', color: 'var(--acu-text-muted)', fontFamily: 'var(--font-body)' }}>
                            Make sure to copy this password. It will not be shown again.
                        </p>
                    </div>
                ) : (
                    <div className="space-y-4">
                        <p style={{ fontSize: '0.875rem', color: 'var(--acu-text-muted)', fontFamily: 'var(--font-body)' }}>
                            Set a new password for <strong style={{ color: 'var(--acu-text)' }}>{user.name}</strong> ({user.email}).
                            Leave blank to generate a random password.
                        </p>
                        <div>
                            <label className="acu-field-label">New Password (optional)</label>
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
