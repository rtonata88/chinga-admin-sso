import PageHeader from '@/components/acumatica/Common/PageHeader';
import StatusBadge from '@/components/acumatica/Common/StatusBadge';
import UserLayout from '@/layouts/user-layout';
import type { StatusVariant } from '@/types/acumatica';
import { Head, router } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Card } from 'primereact/card';
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
    last_login_at?: string | null;
    created_at?: string;
}

function mapStatus(status: string): StatusVariant {
    switch (status) {
        case 'active': return 'active';
        case 'suspended': return 'warning';
        case 'banned': return 'error';
        default: return 'inactive';
    }
}

export default function UserShow({ uuid }: { uuid: string }) {
    const [user, setUser] = useState<UserDetail | null>(null);
    const [loading, setLoading] = useState(true);
    const [passwordDialog, setPasswordDialog] = useState(false);
    const [newPassword, setNewPassword] = useState('');
    const [tempPassword, setTempPassword] = useState<string | null>(null);
    const [resetting, setResetting] = useState(false);
    const toast = useRef<Toast>(null);

    const [error, setError] = useState<string | null>(null);

    const fetchUser = async () => {
        try {
            const url = `/api/v1/admin/users/${uuid}`;
            console.log('Fetching user from:', url);
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            console.log('Response status:', response.status);

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
        } catch (err) {
            console.error('Failed to fetch user:', err);
            setError('Failed to connect to the server');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchUser();
    }, [uuid]);

    const handleResetPassword = async () => {
        setResetting(true);
        setTempPassword(null);
        try {
            const response = await fetch(`/api/v1/admin/users/${uuid}/reset-password`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    new_password: newPassword || undefined,
                    send_email: false,
                }),
            });
            const data = await response.json();
            if (data.success) {
                if (data.temporary_password) {
                    setTempPassword(data.temporary_password);
                }
                toast.current?.show({
                    severity: 'success',
                    summary: 'Password Reset',
                    detail: 'Password has been reset successfully.',
                    life: 5000,
                });
            }
        } catch (error) {
            toast.current?.show({
                severity: 'error',
                summary: 'Error',
                detail: 'Failed to reset password.',
                life: 5000,
            });
        } finally {
            setResetting(false);
        }
    };

    const handleSuspend = async () => {
        if (!confirm('Are you sure you want to suspend this user?')) return;
        try {
            await fetch(`/api/v1/admin/users/${uuid}/suspend`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                credentials: 'same-origin',
            });
            fetchUser();
            toast.current?.show({ severity: 'warn', summary: 'Suspended', detail: 'User has been suspended.', life: 3000 });
        } catch { /* ignore */ }
    };

    const handleActivate = async () => {
        try {
            await fetch(`/api/v1/admin/users/${uuid}/activate`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                credentials: 'same-origin',
            });
            fetchUser();
            toast.current?.show({ severity: 'success', summary: 'Activated', detail: 'User has been activated.', life: 3000 });
        } catch { /* ignore */ }
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
                    <i className="pi pi-spin pi-spinner" style={{ fontSize: '2rem' }} />
                </div>
            </UserLayout>
        );
    }

    if (!user) {
        return (
            <UserLayout>
                <Head title="User Not Found" />
                <div className="p-8 text-center">
                    <p style={{ color: 'var(--acu-text-muted)' }}>{error || 'User not found.'}</p>
                    <Button label="Back to Users" icon="pi pi-arrow-left" className="mt-4" onClick={() => router.visit('/admin/users')} />
                </div>
            </UserLayout>
        );
    }

    const Field = ({ label, value }: { label: string; value: string | null | undefined }) => (
        <div className="mb-4">
            <div style={{ fontSize: '0.75rem', color: 'var(--acu-text-muted)', fontFamily: 'var(--font-body)', marginBottom: '0.25rem' }}>
                {label}
            </div>
            <div style={{ fontSize: '0.9rem', color: 'var(--acu-text)', fontFamily: 'var(--font-body)' }}>
                {value || '—'}
            </div>
        </div>
    );

    return (
        <UserLayout>
            <Head title={user.name} />
            <Toast ref={toast} />

            <PageHeader
                title={user.name}
                subtitle={user.email}
                actions={
                    <div className="flex gap-2">
                        <Button label="Back" icon="pi pi-arrow-left" severity="secondary" outlined onClick={() => router.visit('/admin/users')} />
                        <Button label="Reset Password" icon="pi pi-key" severity="warning" onClick={() => setPasswordDialog(true)} />
                        {user.status === 'active' ? (
                            <Button label="Suspend" icon="pi pi-ban" severity="danger" outlined onClick={handleSuspend} />
                        ) : (
                            <Button label="Activate" icon="pi pi-check" severity="success" onClick={handleActivate} />
                        )}
                    </div>
                }
            />

            <div className="p-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
                <Card title="Profile">
                    <div className="grid grid-cols-2 gap-x-6">
                        <Field label="Name" value={user.name} />
                        <Field label="Username" value={user.username} />
                        <Field label="Email" value={user.email} />
                        <Field label="Phone" value={user.phone} />
                        <Field label="Display Name" value={user.display_name} />
                        <Field label="Date of Birth" value={user.date_of_birth} />
                        <Field label="Country" value={user.country_code} />
                        <Field label="Timezone" value={user.timezone} />
                    </div>
                </Card>

                <Card title="Account">
                    <div className="grid grid-cols-2 gap-x-6">
                        <div className="mb-4">
                            <div style={{ fontSize: '0.75rem', color: 'var(--acu-text-muted)', marginBottom: '0.25rem' }}>Status</div>
                            <StatusBadge status={user.status} variant={mapStatus(user.status)} />
                        </div>
                        <div className="mb-4">
                            <div style={{ fontSize: '0.75rem', color: 'var(--acu-text-muted)', marginBottom: '0.25rem' }}>User Type</div>
                            <Tag value={user.user_type || 'direct'} severity={user.user_type === 'voucher' ? 'warning' : 'info'} />
                        </div>
                        <div className="mb-4">
                            <div style={{ fontSize: '0.75rem', color: 'var(--acu-text-muted)', marginBottom: '0.25rem' }}>Roles</div>
                            <div className="flex gap-1 flex-wrap">
                                {user.roles.map((r) => (
                                    <Tag key={r} value={r} severity="info" />
                                ))}
                                {user.roles.length === 0 && <span style={{ color: 'var(--acu-text-muted)', fontSize: '0.85rem' }}>No roles</span>}
                            </div>
                        </div>
                        <div className="mb-4">
                            <div style={{ fontSize: '0.75rem', color: 'var(--acu-text-muted)', marginBottom: '0.25rem' }}>Email Verified</div>
                            <div>
                                {user.email_verified_at ? (
                                    <span style={{ color: 'var(--acu-success)' }}><i className="pi pi-check-circle mr-1" />{new Date(user.email_verified_at).toLocaleDateString()}</span>
                                ) : (
                                    <span style={{ color: 'var(--acu-text-muted)' }}>Not verified</span>
                                )}
                            </div>
                        </div>
                        <Field label="UUID" value={user.uuid} />
                        <Field label="Language" value={user.language} />
                    </div>
                </Card>
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
                        <Button label="Cancel" severity="secondary" outlined onClick={closePasswordDialog} />
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
                    <div className="space-y-3">
                        <p style={{ color: 'var(--acu-success)', fontWeight: 500 }}>Password reset successfully!</p>
                        <div className="rounded-lg p-3" style={{ background: 'var(--acu-surface-elevated)', border: '1px solid var(--acu-border)' }}>
                            <div style={{ fontSize: '0.75rem', color: 'var(--acu-text-muted)', marginBottom: '0.25rem' }}>New Password</div>
                            <code style={{ fontSize: '1.1rem', color: 'var(--acu-primary)', letterSpacing: '0.05em' }}>{tempPassword}</code>
                        </div>
                        <p style={{ fontSize: '0.8rem', color: 'var(--acu-text-muted)' }}>
                            Make sure to copy this password. It will not be shown again.
                        </p>
                    </div>
                ) : (
                    <div className="space-y-4">
                        <p style={{ fontSize: '0.875rem', color: 'var(--acu-text-muted)' }}>
                            Set a new password for <strong>{user.name}</strong> ({user.email}).
                            Leave blank to generate a random password.
                        </p>
                        <div className="flex flex-col gap-1">
                            <label style={{ fontSize: '0.875rem', fontWeight: 500, color: 'var(--acu-text)' }}>
                                New Password (optional)
                            </label>
                            <InputText
                                type="text"
                                placeholder="Leave blank for random password"
                                value={newPassword}
                                onChange={(e) => setNewPassword(e.target.value)}
                                className="w-full"
                            />
                        </div>
                    </div>
                )}
            </Dialog>
        </UserLayout>
    );
}
