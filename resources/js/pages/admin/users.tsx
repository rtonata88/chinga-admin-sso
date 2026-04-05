import PageHeader from '@/components/acumatica/Common/PageHeader';
import StatusBadge from '@/components/acumatica/Common/StatusBadge';
import UserLayout from '@/layouts/user-layout';
import type { StatusVariant } from '@/types/acumatica';
import { Head } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Checkbox } from 'primereact/checkbox';
import { Column } from 'primereact/column';
import { DataTable } from 'primereact/datatable';
import { Dialog } from 'primereact/dialog';
import { Dropdown } from 'primereact/dropdown';
import { InputText } from 'primereact/inputtext';
import { Tag } from 'primereact/tag';
import { Toast } from 'primereact/toast';
import { useEffect, useRef, useState } from 'react';

interface User {
    uuid: string;
    name: string;
    email: string;
    username: string | null;
    status: string;
    user_type: string | null;
    roles: string[];
    email_verified_at: string | null;
    created_at: string;
    last_login_at: string | null;
}

interface Meta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Stats {
    total_users: number;
    active_users: number;
    suspended_users: number;
    banned_users: number;
}

interface RoleOption {
    name: string;
    display_name: string;
    description: string;
}

function mapUserStatusToVariant(status: string): StatusVariant {
    const map: Record<string, StatusVariant> = {
        active: 'active',
        suspended: 'suspended',
        banned: 'error',
        self_excluded: 'inactive',
    };
    return map[status] || 'inactive';
}

interface StatCardProps {
    icon: string;
    accentColor: string;
    title: string;
    value: string | number;
}

function StatCard({ icon, accentColor, title, value }: StatCardProps) {
    return (
        <div
            className="relative overflow-hidden rounded-xl p-5 transition-all duration-300"
            style={{
                background: 'var(--acu-surface-card)',
                border: '1px solid var(--acu-border)',
            }}
            onMouseEnter={(e) => {
                e.currentTarget.style.borderColor = `${accentColor}30`;
                e.currentTarget.style.boxShadow = `0 8px 32px ${accentColor}15`;
            }}
            onMouseLeave={(e) => {
                e.currentTarget.style.borderColor = 'var(--acu-border)';
                e.currentTarget.style.boxShadow = 'none';
            }}
        >
            <div
                className="absolute inset-0 opacity-[0.03]"
                style={{ background: `radial-gradient(circle at top right, ${accentColor}, transparent 70%)` }}
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
                        style={{ background: `${accentColor}12`, border: `1px solid ${accentColor}20` }}
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
            </div>
        </div>
    );
}

const statusOptions = [
    { label: 'All Status', value: '' },
    { label: 'Active', value: 'active' },
    { label: 'Suspended', value: 'suspended' },
    { label: 'Banned', value: 'banned' },
    { label: 'Self-Excluded', value: 'self_excluded' },
];

export default function Users() {
    const [users, setUsers] = useState<User[]>([]);
    const [meta, setMeta] = useState<Meta | null>(null);
    const [stats, setStats] = useState<Stats | null>(null);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [userTypeFilter, setUserTypeFilter] = useState('');
    const [page, setPage] = useState(1);
    const [createOpen, setCreateOpen] = useState(false);
    const [createForm, setCreateForm] = useState({ name: '', email: '', username: '', password: '', role: 'player' });
    const [creating, setCreating] = useState(false);

    const toast = useRef<Toast>(null);
    const [roleDialogOpen, setRoleDialogOpen] = useState(false);
    const [roleDialogUser, setRoleDialogUser] = useState<User | null>(null);
    const [availableRoles, setAvailableRoles] = useState<RoleOption[]>([]);
    const [selectedRoles, setSelectedRoles] = useState<string[]>([]);
    const [originalRoles, setOriginalRoles] = useState<string[]>([]);
    const [roleLoading, setRoleLoading] = useState(false);
    const [roleSaving, setRoleSaving] = useState(false);

    const fetchUsers = async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams();
            if (search) params.append('search', search);
            if (statusFilter) params.append('status', statusFilter);
            if (userTypeFilter) params.append('user_type', userTypeFilter);
            params.append('page', page.toString());

            const response = await fetch(`/api/v1/admin/users?${params}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            if (data.success) {
                setUsers(data.data);
                setMeta(data.meta);
            }
        } catch (error) {
            console.error('Failed to fetch users:', error);
        } finally {
            setLoading(false);
        }
    };

    const fetchStats = async () => {
        try {
            const response = await fetch('/api/v1/admin/users/stats', {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            if (data.success) {
                setStats(data.data);
            }
        } catch (error) {
            console.error('Failed to fetch stats:', error);
        }
    };

    useEffect(() => {
        fetchUsers();
        fetchStats();
    }, [page, statusFilter, userTypeFilter]);

    const getCsrfToken = () => {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    };

    const openRoleDialog = async (user: User) => {
        setRoleDialogUser(user);
        setRoleDialogOpen(true);
        setRoleLoading(true);

        try {
            const [rolesRes, userRolesRes] = await Promise.all([
                fetch('/api/v1/admin/roles', { headers: { Accept: 'application/json' } }),
                fetch(`/api/v1/admin/users/${user.uuid}/roles`, { headers: { Accept: 'application/json' } }),
            ]);

            const rolesData = await rolesRes.json();
            const userRolesData = await userRolesRes.json();

            if (rolesData.success) setAvailableRoles(rolesData.data);
            if (userRolesData.success) {
                const names = userRolesData.data.map((r: RoleOption) => r.name);
                setSelectedRoles(names);
                setOriginalRoles(names);
            }
        } catch (error) {
            console.error('Failed to fetch roles:', error);
        } finally {
            setRoleLoading(false);
        }
    };

    const saveRoles = async () => {
        if (!roleDialogUser) return;
        setRoleSaving(true);

        try {
            const toAdd = selectedRoles.filter((r) => !originalRoles.includes(r));
            const toRemove = originalRoles.filter((r) => !selectedRoles.includes(r));

            const headers = {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            };

            for (const role of toAdd) {
                const res = await fetch(`/api/v1/admin/users/${roleDialogUser.uuid}/roles`, {
                    method: 'POST',
                    headers,
                    body: JSON.stringify({ role }),
                });
                if (!res.ok) {
                    const data = await res.json();
                    toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || `Failed to assign ${role}` });
                    return;
                }
            }

            for (const role of toRemove) {
                const res = await fetch(`/api/v1/admin/users/${roleDialogUser.uuid}/roles/${role}`, {
                    method: 'DELETE',
                    headers,
                });
                if (!res.ok) {
                    const data = await res.json();
                    toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || `Failed to remove ${role}` });
                    return;
                }
            }

            toast.current?.show({ severity: 'success', summary: 'Success', detail: 'Roles updated successfully.' });
            setRoleDialogOpen(false);
            fetchUsers();
        } catch (error) {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to update roles.' });
        } finally {
            setRoleSaving(false);
        }
    };

    const handleCreateUser = async () => {
        if (!createForm.name || !createForm.email || !createForm.password) return;
        setCreating(true);
        try {
            const response = await fetch('/api/v1/admin/users', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify(createForm),
            });
            const data = await response.json();
            if (data.success) {
                toast.current?.show({ severity: 'success', summary: 'Created', detail: `User ${createForm.email} created.`, life: 3000 });
                setCreateOpen(false);
                setCreateForm({ name: '', email: '', username: '', password: '', role: 'player' });
                fetchUsers();
            } else {
                toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message, life: 5000 });
            }
        } catch (error) {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to create user.', life: 5000 });
        } finally {
            setCreating(false);
        }
    };

    const handleSearch = () => {
        setPage(1);
        fetchUsers();
    };

    const userTemplate = (row: User) => (
        <div>
            <div className="font-medium text-sm" style={{ color: 'var(--acu-text)' }}>{row.name}</div>
            <div className="text-xs" style={{ color: 'var(--acu-text-light)' }}>{row.email}</div>
        </div>
    );

    const statusTemplate = (row: User) => (
        <StatusBadge status={mapUserStatusToVariant(row.status)} label={row.status} />
    );

    const verifiedTemplate = (row: User) => (
        <span className="text-sm">
            {row.email_verified_at ? (
                <i className="pi pi-check-circle" style={{ color: 'var(--acu-success)' }} />
            ) : (
                <i className="pi pi-times-circle" style={{ color: 'var(--acu-text-light)' }} />
            )}
        </span>
    );

    const registeredTemplate = (row: User) => (
        <span className="text-sm" style={{ color: 'var(--acu-text-muted)' }}>
            {new Date(row.created_at).toLocaleDateString()}
        </span>
    );

    const lastLoginTemplate = (row: User) => (
        <span className="text-sm" style={{ color: 'var(--acu-text-muted)' }}>
            {row.last_login_at
                ? new Date(row.last_login_at).toLocaleDateString()
                : '\u2014'}
        </span>
    );

    const actionsTemplate = (row: User) => (
        <div className="flex gap-1">
            <Button
                icon="pi pi-shield"
                text
                severity="secondary"
                size="small"
                tooltip="Manage roles"
                onClick={() => openRoleDialog(row)}
            />
            <Button
                icon="pi pi-eye"
                text
                severity="secondary"
                size="small"
                tooltip="View user"
                onClick={() => window.location.href = `/admin/users/${row.uuid}`}
            />
        </div>
    );

    return (
        <UserLayout title="User Management">
            <Head title="User Management" />
            <Toast ref={toast} />

            <div className="space-y-8">
                <PageHeader title="User Management" subtitle="Manage platform users and their accounts">
                    <Button
                        label="Refresh"
                        icon="pi pi-refresh"
                        outlined
                        size="small"
                        onClick={() => {
                            fetchUsers();
                            fetchStats();
                        }}
                    />
                    <Button label="Create User" icon="pi pi-user-plus" onClick={() => setCreateOpen(true)} />
                </PageHeader>

                {/* Stats */}
                {stats && (
                    <div className="grid gap-5 md:grid-cols-4">
                        <StatCard icon="pi pi-users" accentColor="#58A6FF" title="Total Users" value={stats.total_users.toLocaleString()} />
                        <StatCard icon="pi pi-check-circle" accentColor="#3FB950" title="Active" value={stats.active_users.toLocaleString()} />
                        <StatCard icon="pi pi-pause-circle" accentColor="#D29922" title="Suspended" value={stats.suspended_users} />
                        <StatCard icon="pi pi-ban" accentColor="#F85149" title="Banned" value={stats.banned_users} />
                    </div>
                )}

                {/* Filters */}
                <div
                    className="rounded-xl p-4"
                    style={{
                        background: 'var(--acu-surface-card)',
                        border: '1px solid var(--acu-border)',
                    }}
                >
                    <div className="flex flex-wrap gap-3 items-end">
                        <div className="flex flex-1 gap-2">
                            <span className="p-input-icon-left flex-1" style={{ maxWidth: '24rem' }}>
                                <i className="pi pi-search" />
                                <InputText
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                                    placeholder="Search by name, email, username..."
                                    className="w-full"
                                />
                            </span>
                            <Button label="Search" icon="pi pi-search" size="small" onClick={handleSearch} />
                        </div>
                        <Dropdown
                            value={statusFilter}
                            onChange={(e) => { setStatusFilter(e.value); setPage(1); }}
                            options={statusOptions}
                            placeholder="Status"
                            className="w-40"
                        />
                        <Dropdown
                            value={userTypeFilter}
                            options={[
                                { label: 'All Types', value: '' },
                                { label: 'Players', value: 'direct' },
                                { label: 'Voucher Users', value: 'voucher' },
                            ]}
                            onChange={(e) => { setUserTypeFilter(e.value); setPage(1); }}
                            placeholder="All Types"
                            className="w-full md:w-48"
                        />
                    </div>
                </div>

                {/* Users Table */}
                <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-gold)' } as React.CSSProperties}>
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-users" />
                            <span>Users</span>
                            <span className="text-xs font-normal ml-1" style={{ color: 'var(--acu-text-light)' }}>
                                {meta?.total ? `(${users.length} of ${meta.total})` : ''}
                            </span>
                        </div>
                    </div>
                    <div className="acu-fieldset-body p-0">
                        <DataTable
                            value={users}
                            loading={loading}
                            size="small"
                            showGridlines={false}
                            emptyMessage="No users found"
                        >
                            <Column header="User" body={userTemplate} />
                            <Column header="Status" body={statusTemplate} />
                            <Column
                                header="Role"
                                body={(row: User) => (
                                    <div className="flex gap-1 flex-wrap">
                                        {(row.roles || []).map((r) => {
                                            const labels: Record<string, string> = {
                                                platform_super_admin: 'Super Admin',
                                                platform_admin: 'Platform Admin',
                                                tenant_admin: 'Admin',
                                                tenant_manager: 'Manager',
                                                player: 'Player',
                                            };
                                            const severities: Record<string, 'danger' | 'warning' | 'info' | 'success'> = {
                                                platform_super_admin: 'danger',
                                                platform_admin: 'danger',
                                                tenant_admin: 'warning',
                                                tenant_manager: 'info',
                                                player: 'success',
                                            };
                                            return (
                                                <Tag key={r} value={labels[r] || r} severity={severities[r] || 'info'} style={{ fontSize: '0.7rem' }} />
                                            );
                                        })}
                                    </div>
                                )}
                            />
                            <Column
                                header="Type"
                                body={(row: User) => (
                                    <Tag
                                        value={row.user_type === 'voucher' ? 'Voucher' : 'Direct'}
                                        severity={row.user_type === 'voucher' ? 'warning' : 'info'}
                                        style={{ fontSize: '0.7rem' }}
                                    />
                                )}
                            />
                            <Column header="Verified" body={verifiedTemplate} style={{ textAlign: 'center' }} />
                            <Column header="Registered" body={registeredTemplate} />
                            <Column header="Last Login" body={lastLoginTemplate} />
                            <Column header="" body={actionsTemplate} style={{ width: '4rem' }} />
                        </DataTable>

                        {/* Pagination */}
                        {meta && meta.last_page > 1 && (
                            <div
                                className="flex items-center justify-between px-5 py-3"
                                style={{ borderTop: '1px solid var(--acu-border)' }}
                            >
                                <span className="text-xs" style={{ color: 'var(--acu-text-light)', fontFamily: 'var(--font-body)' }}>
                                    Page {meta.current_page} of {meta.last_page}
                                </span>
                                <div className="flex gap-2">
                                    <Button
                                        label="Previous"
                                        icon="pi pi-angle-left"
                                        outlined
                                        size="small"
                                        disabled={meta.current_page === 1}
                                        onClick={() => setPage((p) => Math.max(1, p - 1))}
                                    />
                                    <Button
                                        label="Next"
                                        icon="pi pi-angle-right"
                                        iconPos="right"
                                        outlined
                                        size="small"
                                        disabled={meta.current_page === meta.last_page}
                                        onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
                                    />
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            <Dialog
                header={`Manage Roles \u2014 ${roleDialogUser?.name || ''}`}
                visible={roleDialogOpen}
                style={{ width: '28rem' }}
                onHide={() => setRoleDialogOpen(false)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button
                            label="Cancel"
                            icon="pi pi-times"
                            severity="secondary"
                            outlined
                            onClick={() => setRoleDialogOpen(false)}
                        />
                        <Button
                            label={roleSaving ? 'Saving...' : 'Save'}
                            icon="pi pi-check"
                            onClick={saveRoles}
                            disabled={roleSaving || roleLoading}
                            loading={roleSaving}
                        />
                    </div>
                }
            >
                {roleLoading ? (
                    <div className="flex justify-center py-6">
                        <i className="pi pi-spin pi-spinner text-2xl" style={{ color: 'var(--acu-primary)' }} />
                    </div>
                ) : (
                    <div className="space-y-2">
                        {availableRoles.length === 0 ? (
                            <p className="text-sm" style={{ color: 'var(--acu-text-muted)' }}>No roles available to assign.</p>
                        ) : (
                            availableRoles.map((role) => (
                                <div
                                    key={role.name}
                                    className="flex items-start gap-3 p-3 rounded-lg transition-colors"
                                    style={{ cursor: 'pointer' }}
                                    onMouseEnter={(e) => (e.currentTarget.style.background = 'var(--acu-surface-hover)')}
                                    onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
                                >
                                    <Checkbox
                                        inputId={role.name}
                                        checked={selectedRoles.includes(role.name)}
                                        onChange={(e) => {
                                            if (e.checked) {
                                                setSelectedRoles([...selectedRoles, role.name]);
                                            } else {
                                                setSelectedRoles(selectedRoles.filter((r) => r !== role.name));
                                            }
                                        }}
                                    />
                                    <label htmlFor={role.name} className="cursor-pointer">
                                        <div className="text-sm font-medium" style={{ color: 'var(--acu-text)' }}>{role.display_name}</div>
                                        <div className="text-xs" style={{ color: 'var(--acu-text-light)' }}>{role.description}</div>
                                    </label>
                                </div>
                            ))
                        )}
                    </div>
                )}
            </Dialog>

            <Dialog
                header="Create User"
                visible={createOpen}
                style={{ width: '28rem' }}
                onHide={() => setCreateOpen(false)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button label="Cancel" severity="secondary" outlined onClick={() => setCreateOpen(false)} />
                        <Button label={creating ? 'Creating...' : 'Create'} onClick={handleCreateUser} disabled={creating} loading={creating} />
                    </div>
                }
            >
                <div className="space-y-4">
                    <div className="flex flex-col gap-1">
                        <label style={{ fontSize: '0.875rem', fontWeight: 500 }}>Name *</label>
                        <InputText value={createForm.name} onChange={(e) => setCreateForm({...createForm, name: e.target.value})} className="w-full" />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label style={{ fontSize: '0.875rem', fontWeight: 500 }}>Email *</label>
                        <InputText type="email" value={createForm.email} onChange={(e) => setCreateForm({...createForm, email: e.target.value})} className="w-full" />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label style={{ fontSize: '0.875rem', fontWeight: 500 }}>Username</label>
                        <InputText value={createForm.username} onChange={(e) => setCreateForm({...createForm, username: e.target.value})} className="w-full" />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label style={{ fontSize: '0.875rem', fontWeight: 500 }}>Password *</label>
                        <InputText type="password" value={createForm.password} onChange={(e) => setCreateForm({...createForm, password: e.target.value})} className="w-full" />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label style={{ fontSize: '0.875rem', fontWeight: 500 }}>Role *</label>
                        <Dropdown
                            value={createForm.role}
                            options={[
                                { label: 'Player', value: 'player' },
                                { label: 'Tenant Manager', value: 'tenant_manager' },
                                { label: 'Tenant Admin', value: 'tenant_admin' },
                            ]}
                            onChange={(e) => setCreateForm({...createForm, role: e.value})}
                            className="w-full"
                        />
                    </div>
                </div>
            </Dialog>
        </UserLayout>
    );
}
