// resources/js/pages/admin/users.tsx
//
// User management, brass-on-ink redesign mirroring /tenant-overview.
// KPI strip → filter bar (chips + search) → hairline-row table →
// pager. Functional bits (Create User dialog, Manage Roles dialog,
// Toast) stay on PrimeReact for now — those carry form state and
// aren't part of the chrome.

import { KpiCard, formatCount } from '@/components/operator/kpi-card';
import UserLayout from '@/layouts/user-layout';
import { Head } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Checkbox } from 'primereact/checkbox';
import { Dialog } from 'primereact/dialog';
import { Dropdown } from 'primereact/dropdown';
import { InputText } from 'primereact/inputtext';
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

const ROLE_LABELS: Record<string, string> = {
    platform_super_admin: 'Super',
    platform_admin: 'Platform',
    tenant_admin: 'Admin',
    tenant_manager: 'Manager',
    player: 'Player',
};

const STATUS_FILTERS = [
    { label: 'All', value: '' },
    { label: 'Active', value: 'active' },
    { label: 'Suspended', value: 'suspended' },
    { label: 'Banned', value: 'banned' },
    { label: 'Self-excluded', value: 'self_excluded' },
];

const TYPE_FILTERS = [
    { label: 'All types', value: '' },
    { label: 'Direct', value: 'direct' },
    { label: 'Voucher', value: 'voucher' },
];

function statusPill(status: string): string {
    // Map user status to the existing cgo-pill modifier set.
    switch (status) {
        case 'active': return 'live';
        case 'suspended': return 'pending';
        case 'banned': return 'flagged';
        case 'self_excluded': return 'void';
        default: return 'void';
    }
}

const DATE_FMT = new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });

function formatDate(iso: string | null): string {
    return iso ? DATE_FMT.format(new Date(iso)) : '—';
}

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
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [page, statusFilter, userTypeFilter]);

    const getCsrfToken = () =>
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

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

    const submitSearch = () => {
        setPage(1);
        fetchUsers();
    };

    return (
        <UserLayout title="Users">
            <Head title="Users · Admin" />
            <Toast ref={toast} />

            <div className="cgo-page">
                {/* Page header */}
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Admin</div>
                        <h1 className="cgo-title">Users</h1>
                        <div className="cgo-subtitle">
                            Platform users, status, and role assignment.
                        </div>
                    </div>
                    <div style={{ display: 'flex', gap: 8 }}>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={() => { fetchUsers(); fetchStats(); }}
                        >
                            Refresh
                        </button>
                        <button
                            type="button"
                            className="cg-btn cg-btn--primary cg-btn--sm"
                            onClick={() => setCreateOpen(true)}
                        >
                            + New user
                        </button>
                    </div>
                </div>

                {/* KPI strip */}
                <div className="cgo-kpis">
                    <KpiCard
                        label="Total users"
                        value={stats ? formatCount(stats.total_users) : '—'}
                        meta="all roles"
                    />
                    <KpiCard
                        label="Active"
                        value={stats ? formatCount(stats.active_users) : '—'}
                        meta="able to sign in"
                    />
                    <KpiCard
                        label="Suspended"
                        value={stats ? formatCount(stats.suspended_users) : '—'}
                        meta={stats?.suspended_users ? 'review' : 'none'}
                    />
                    <KpiCard
                        label="Banned"
                        value={stats ? formatCount(stats.banned_users) : '—'}
                        meta={stats?.banned_users ? 'permanent' : 'none'}
                    />
                </div>

                {/* Filter bar — chips for status + type, search input on the right */}
                <div className="cgo-filterbar">
                    {STATUS_FILTERS.map((f) => (
                        <button
                            key={f.value || 'all'}
                            type="button"
                            className={`cgo-chip${statusFilter === f.value ? ' active' : ''}`}
                            onClick={() => { setStatusFilter(f.value); setPage(1); }}
                        >
                            {f.label}
                        </button>
                    ))}
                    <div className="cgo-filter-divider" />
                    {TYPE_FILTERS.map((f) => (
                        <button
                            key={f.value || 'all-types'}
                            type="button"
                            className={`cgo-chip${userTypeFilter === f.value ? ' active' : ''}`}
                            onClick={() => { setUserTypeFilter(f.value); setPage(1); }}
                        >
                            {f.label}
                        </button>
                    ))}
                    <div className="cgo-right">
                        <label className="cgo-input">
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && submitSearch()}
                                placeholder="Search name, email, username…"
                                style={{ minWidth: 220 }}
                            />
                        </label>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={submitSearch}
                        >
                            Search
                        </button>
                    </div>
                </div>

                {/* Users table */}
                <div
                    className="cgo-table-wrap cgo-table-wrap--scroll"
                    style={{ borderRadius: '0 0 8px 8px', borderTop: 0 }}
                >
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ minWidth: 220 }}>User</th>
                                <th style={{ width: 110 }}>Status</th>
                                <th style={{ width: 180 }}>Roles</th>
                                <th style={{ width: 90 }}>Type</th>
                                <th style={{ width: 80, textAlign: 'center' }}>Verified</th>
                                <th style={{ width: 110 }}>Registered</th>
                                <th style={{ width: 110 }}>Last login</th>
                                <th style={{ width: 90 }} />
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan={8} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        Loading…
                                    </td>
                                </tr>
                            ) : users.length === 0 ? (
                                <tr>
                                    <td colSpan={8} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        No users match the current filters.
                                    </td>
                                </tr>
                            ) : (
                                users.map((u) => (
                                    <tr key={u.uuid}>
                                        <td style={{ maxWidth: 280 }}>
                                            <div className="cgo-name cgo-cell-clip" title={u.name}>
                                                {u.name}
                                            </div>
                                            <div className="cgo-uid">{u.email}</div>
                                        </td>
                                        <td>
                                            <span className={`cgo-pill ${statusPill(u.status)}`}>
                                                {u.status.replace('_', ' ')}
                                            </span>
                                        </td>
                                        <td>
                                            <div style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
                                                {(u.roles || []).map((r) => (
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
                                        </td>
                                        <td>
                                            <span style={{ color: 'var(--cg-fg-2)', fontSize: 12, textTransform: 'capitalize' }}>
                                                {u.user_type || 'direct'}
                                            </span>
                                        </td>
                                        <td style={{ textAlign: 'center' }}>
                                            {u.email_verified_at ? (
                                                <span style={{ color: 'var(--cg-pos)' }}>✓</span>
                                            ) : (
                                                <span style={{ color: 'var(--cg-fg-4)' }}>—</span>
                                            )}
                                        </td>
                                        <td>
                                            <span className="cgo-uid">{formatDate(u.created_at)}</span>
                                        </td>
                                        <td>
                                            <span className="cgo-uid">{formatDate(u.last_login_at)}</span>
                                        </td>
                                        <td>
                                            <div style={{ display: 'flex', gap: 4, justifyContent: 'flex-end' }}>
                                                <button
                                                    type="button"
                                                    className="cgo-row-action"
                                                    aria-label="Manage roles"
                                                    title="Manage roles"
                                                    onClick={() => openRoleDialog(u)}
                                                >
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 2 4 6v6c0 5 3.5 9 8 10 4.5-1 8-5 8-10V6l-8-4z" /></svg>
                                                </button>
                                                <a
                                                    href={`/admin/users/${u.uuid}`}
                                                    className="cgo-row-action"
                                                    aria-label="View user"
                                                    title="View user"
                                                >
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z" /><circle cx="12" cy="12" r="3" /></svg>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>

                    {/* Footer / pager */}
                    {meta && (
                        <div className="cgo-table-foot">
                            <span>
                                {meta.total > 0 ? (
                                    <>
                                        Showing <b className="cgo-mono">{users.length}</b> of{' '}
                                        <b className="cgo-mono">{meta.total.toLocaleString()}</b> users
                                    </>
                                ) : (
                                    'No users'
                                )}
                            </span>
                            {meta.last_page > 1 && (
                                <div className="cgo-pager">
                                    <button
                                        type="button"
                                        disabled={meta.current_page === 1}
                                        onClick={() => setPage((p) => Math.max(1, p - 1))}
                                        aria-label="Previous"
                                    >
                                        ‹
                                    </button>
                                    <button type="button" className="curr" disabled>
                                        {meta.current_page}
                                    </button>
                                    <button
                                        type="button"
                                        disabled={meta.current_page === meta.last_page}
                                        onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
                                        aria-label="Next"
                                    >
                                        ›
                                    </button>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>

            {/* Manage roles dialog (PrimeReact — functional, not chrome) */}
            <Dialog
                header={`Manage roles · ${roleDialogUser?.name || ''}`}
                visible={roleDialogOpen}
                style={{ width: '28rem' }}
                onHide={() => setRoleDialogOpen(false)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button label="Cancel" severity="secondary" outlined onClick={() => setRoleDialogOpen(false)} />
                        <Button
                            label={roleSaving ? 'Saving...' : 'Save'}
                            onClick={saveRoles}
                            disabled={roleSaving || roleLoading}
                            loading={roleSaving}
                        />
                    </div>
                }
            >
                {roleLoading ? (
                    <div className="flex justify-center py-6">
                        <i className="pi pi-spin pi-spinner text-2xl" />
                    </div>
                ) : (
                    <div className="space-y-2">
                        {availableRoles.length === 0 ? (
                            <p className="text-sm">No roles available to assign.</p>
                        ) : (
                            availableRoles.map((role) => (
                                <label
                                    key={role.name}
                                    htmlFor={role.name}
                                    className="flex items-start gap-3 p-3 rounded-lg cursor-pointer"
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
                                    <div>
                                        <div className="text-sm font-medium">{role.display_name}</div>
                                        <div className="text-xs opacity-70">{role.description}</div>
                                    </div>
                                </label>
                            ))
                        )}
                    </div>
                )}
            </Dialog>

            {/* Create user dialog */}
            <Dialog
                header="Create user"
                visible={createOpen}
                style={{ width: '28rem' }}
                onHide={() => setCreateOpen(false)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button label="Cancel" severity="secondary" outlined onClick={() => setCreateOpen(false)} />
                        <Button
                            label={creating ? 'Creating...' : 'Create'}
                            onClick={handleCreateUser}
                            disabled={creating}
                            loading={creating}
                        />
                    </div>
                }
            >
                <div className="space-y-4">
                    <div className="flex flex-col gap-1">
                        <label style={{ fontSize: '0.875rem', fontWeight: 500 }}>Name *</label>
                        <InputText value={createForm.name} onChange={(e) => setCreateForm({ ...createForm, name: e.target.value })} className="w-full" />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label style={{ fontSize: '0.875rem', fontWeight: 500 }}>Email *</label>
                        <InputText type="email" value={createForm.email} onChange={(e) => setCreateForm({ ...createForm, email: e.target.value })} className="w-full" />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label style={{ fontSize: '0.875rem', fontWeight: 500 }}>Username</label>
                        <InputText value={createForm.username} onChange={(e) => setCreateForm({ ...createForm, username: e.target.value })} className="w-full" />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label style={{ fontSize: '0.875rem', fontWeight: 500 }}>Password *</label>
                        <InputText type="password" value={createForm.password} onChange={(e) => setCreateForm({ ...createForm, password: e.target.value })} className="w-full" />
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
                            onChange={(e) => setCreateForm({ ...createForm, role: e.value })}
                            className="w-full"
                        />
                    </div>
                </div>
            </Dialog>
        </UserLayout>
    );
}
