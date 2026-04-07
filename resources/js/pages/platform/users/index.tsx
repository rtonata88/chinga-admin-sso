import PageHeader from '@/components/acumatica/Common/PageHeader';
import StatusBadge from '@/components/acumatica/Common/StatusBadge';
import UserLayout from '@/layouts/user-layout';
import type { StatusVariant } from '@/types/acumatica';
import { Head } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Checkbox } from 'primereact/checkbox';
import { Chip } from 'primereact/chip';
import { Column } from 'primereact/column';
import { DataTable } from 'primereact/datatable';
import { Dialog } from 'primereact/dialog';
import { Dropdown } from 'primereact/dropdown';
import { InputText } from 'primereact/inputtext';
import { Password } from 'primereact/password';
import { Toast } from 'primereact/toast';
import { useEffect, useRef, useState } from 'react';

interface UserRole {
    name: string;
    display_name: string;
    tenant_id: number | null;
}

interface PlatformUser {
    uuid: string;
    name: string;
    email: string;
    status: string;
    tenant_id: number | null;
    tenant_name: string | null;
    roles: UserRole[];
    created_at: string;
    last_login_at: string | null;
}

interface RoleOption {
    name: string;
    display_name: string;
    description: string;
    is_platform_role: boolean;
}

interface AssignedRole {
    name: string;
    display_name: string;
    description: string;
    is_platform_role: boolean;
    tenant_id: number | null;
}

interface TenantOption {
    label: string;
    value: number;
}

interface Meta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
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

const statusOptions = [
    { label: 'All Status', value: '' },
    { label: 'Active', value: 'active' },
    { label: 'Suspended', value: 'suspended' },
    { label: 'Banned', value: 'banned' },
];

export default function PlatformUsersIndex() {
    const toast = useRef<Toast>(null);
    const [users, setUsers] = useState<PlatformUser[]>([]);
    const [meta, setMeta] = useState<Meta | null>(null);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [tenantFilter, setTenantFilter] = useState<number | ''>('');
    const [page, setPage] = useState(1);
    const [tenants, setTenants] = useState<TenantOption[]>([]);

    // Role dialog state
    const [roleDialogOpen, setRoleDialogOpen] = useState(false);
    const [roleDialogUser, setRoleDialogUser] = useState<PlatformUser | null>(null);
    const [availableRoles, setAvailableRoles] = useState<RoleOption[]>([]);
    const [roleLoading, setRoleLoading] = useState(false);
    const [roleSaving, setRoleSaving] = useState(false);

    // Platform role selections (checkboxes)
    const [selectedPlatformRoles, setSelectedPlatformRoles] = useState<string[]>([]);
    const [originalPlatformRoles, setOriginalPlatformRoles] = useState<string[]>([]);

    // Create user dialog state
    const [createDialogOpen, setCreateDialogOpen] = useState(false);
    const [createForm, setCreateForm] = useState({ name: '', email: '', password: '', tenant_id: null as number | null });
    const [createErrors, setCreateErrors] = useState<Record<string, string[]>>({});
    const [createSaving, setCreateSaving] = useState(false);

    // Tenant role assignment
    const [selectedTenantForRole, setSelectedTenantForRole] = useState<number | null>(null);
    const [selectedTenantRoles, setSelectedTenantRoles] = useState<string[]>([]);
    const [tenantRoleAssignments, setTenantRoleAssignments] = useState<{ role: string; display_name: string; tenant_id: number; tenant_name: string }[]>([]);

    const getCsrfToken = () => {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    };

    const fetchUsers = async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams();
            if (search) params.append('search', search);
            if (statusFilter) params.append('status', statusFilter);
            if (tenantFilter !== '') params.append('tenant_id', tenantFilter.toString());
            params.append('page', page.toString());

            const response = await fetch(`/api/v1/platform/users?${params}`, {
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

    const fetchTenants = async () => {
        try {
            const response = await fetch('/api/v1/platform/tenants?per_page=100', {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            if (data.data) {
                setTenants(data.data.map((t: { id: number; name: string }) => ({
                    label: t.name,
                    value: t.id,
                })));
            }
        } catch (error) {
            console.error('Failed to fetch tenants:', error);
        }
    };

    useEffect(() => {
        fetchUsers();
    }, [page, statusFilter, tenantFilter]);

    useEffect(() => {
        fetchTenants();
    }, []);

    const handleSearch = () => {
        setPage(1);
        fetchUsers();
    };

    const openRoleDialog = async (user: PlatformUser) => {
        setRoleDialogUser(user);
        setRoleDialogOpen(true);
        setRoleLoading(true);
        setSelectedTenantForRole(null);
        setSelectedTenantRoles([]);

        try {
            const [rolesRes, userRolesRes] = await Promise.all([
                fetch('/api/v1/platform/roles', { headers: { Accept: 'application/json' } }),
                fetch(`/api/v1/platform/users/${user.uuid}/roles`, { headers: { Accept: 'application/json' } }),
            ]);

            const rolesData = await rolesRes.json();
            const userRolesData = await userRolesRes.json();

            if (rolesData.success) setAvailableRoles(rolesData.data);
            if (userRolesData.success) {
                const platformRoleNames = userRolesData.data
                    .filter((r: AssignedRole) => r.is_platform_role)
                    .map((r: AssignedRole) => r.name);
                setSelectedPlatformRoles(platformRoleNames);
                setOriginalPlatformRoles(platformRoleNames);

                const tenantAssignments = userRolesData.data
                    .filter((r: AssignedRole) => !r.is_platform_role && r.tenant_id !== null)
                    .map((r: AssignedRole) => {
                        const tenantOpt = tenants.find((t) => t.value === r.tenant_id);
                        return {
                            role: r.name,
                            display_name: r.display_name,
                            tenant_id: r.tenant_id!,
                            tenant_name: tenantOpt?.label || `Tenant #${r.tenant_id}`,
                        };
                    });
                setTenantRoleAssignments(tenantAssignments);
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

        const headers = {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': getCsrfToken(),
        };

        try {
            const platformToAdd = selectedPlatformRoles.filter((r) => !originalPlatformRoles.includes(r));
            const platformToRemove = originalPlatformRoles.filter((r) => !selectedPlatformRoles.includes(r));

            for (const role of platformToAdd) {
                const res = await fetch(`/api/v1/platform/users/${roleDialogUser.uuid}/roles`, {
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

            for (const role of platformToRemove) {
                const res = await fetch(`/api/v1/platform/users/${roleDialogUser.uuid}/roles/${role}`, {
                    method: 'DELETE',
                    headers,
                });
                if (!res.ok) {
                    const data = await res.json();
                    toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || `Failed to remove ${role}` });
                    return;
                }
            }

            if (selectedTenantForRole && selectedTenantRoles.length > 0) {
                for (const role of selectedTenantRoles) {
                    const res = await fetch(`/api/v1/platform/users/${roleDialogUser.uuid}/roles`, {
                        method: 'POST',
                        headers,
                        body: JSON.stringify({ role, tenant_id: selectedTenantForRole }),
                    });
                    if (!res.ok) {
                        const data = await res.json();
                        toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || `Failed to assign ${role}` });
                        return;
                    }
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

    const removeTenantRole = async (role: string, tenantId: number) => {
        if (!roleDialogUser) return;
        setRoleSaving(true);

        try {
            const res = await fetch(
                `/api/v1/platform/users/${roleDialogUser.uuid}/roles/${role}?tenant_id=${tenantId}`,
                {
                    method: 'DELETE',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                }
            );

            if (res.ok) {
                setTenantRoleAssignments((prev) =>
                    prev.filter((a) => !(a.role === role && a.tenant_id === tenantId))
                );
                toast.current?.show({ severity: 'success', summary: 'Success', detail: 'Role removed.' });
                fetchUsers();
            } else {
                const data = await res.json();
                toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || 'Failed to remove role.' });
            }
        } catch (error) {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to remove role.' });
        } finally {
            setRoleSaving(false);
        }
    };

    const openCreateDialog = () => {
        setCreateForm({ name: '', email: '', password: '', tenant_id: null });
        setCreateErrors({});
        setCreateDialogOpen(true);
    };

    const createUser = async () => {
        setCreateSaving(true);
        setCreateErrors({});

        try {
            const res = await fetch('/api/v1/platform/users', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify(createForm),
            });

            const data = await res.json();

            if (res.ok && data.success) {
                toast.current?.show({ severity: 'success', summary: 'Success', detail: 'User created successfully.' });
                setCreateDialogOpen(false);
                fetchUsers();
            } else if (res.status === 422 && data.errors) {
                setCreateErrors(data.errors);
            } else {
                toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || 'Failed to create user.' });
            }
        } catch (error) {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to create user.' });
        } finally {
            setCreateSaving(false);
        }
    };

    const platformRoles = availableRoles.filter((r) => r.is_platform_role);
    const tenantRoles = availableRoles.filter((r) => !r.is_platform_role);

    const userTemplate = (row: PlatformUser) => (
        <div>
            <div className="font-medium text-sm text-[var(--acu-text)]">{row.name}</div>
            <div className="text-xs text-[var(--acu-text-light)]">{row.email}</div>
        </div>
    );

    const tenantTemplate = (row: PlatformUser) => (
        <span className="text-sm text-[var(--acu-text)]">
            {row.tenant_name || 'Platform'}
        </span>
    );

    const rolesTemplate = (row: PlatformUser) => (
        <div className="flex flex-wrap gap-1">
            {row.roles.map((r, i) => (
                <span key={i} className="text-xs px-2 py-0.5 rounded bg-[var(--acu-surface-hover)] text-[var(--acu-text)]">
                    {r.display_name}
                </span>
            ))}
        </div>
    );

    const statusTemplate = (row: PlatformUser) => (
        <StatusBadge status={mapUserStatusToVariant(row.status)} label={row.status} />
    );

    const actionsTemplate = (row: PlatformUser) => (
        <Button
            icon="pi pi-shield"
            text
            severity="secondary"
            size="small"
            tooltip="Manage roles"
            onClick={() => openRoleDialog(row)}
        />
    );

    const tenantFilterOptions = [
        { label: 'All Tenants', value: '' },
        ...tenants,
    ];

    return (
        <UserLayout title="Platform Users">
            <Head title="Platform Users" />
            <Toast ref={toast} />

            <div className="space-y-6">
                <PageHeader title="Users" subtitle="Manage users across all tenants">
                    <Button
                        label="Refresh"
                        icon="pi pi-refresh"
                        outlined
                        size="small"
                        onClick={fetchUsers}
                    />
                    <Button
                        label="Add User"
                        icon="pi pi-plus"
                        size="small"
                        onClick={openCreateDialog}
                    />
                </PageHeader>

                {/* Filters */}
                <div className="acu-fieldset">
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-filter" />
                            <span>Filters</span>
                        </div>
                    </div>
                    <div className="acu-fieldset-body">
                        <div className="flex flex-wrap gap-3 items-end">
                            <div className="flex flex-1 gap-2">
                                <span className="p-input-icon-left flex-1" style={{ maxWidth: '24rem' }}>
                                    <i className="pi pi-search" />
                                    <InputText
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                                        placeholder="Search by name, email..."
                                        className="w-full"
                                    />
                                </span>
                                <Button
                                    label="Search"
                                    icon="pi pi-search"
                                    size="small"
                                    onClick={handleSearch}
                                />
                            </div>
                            <Dropdown
                                value={tenantFilter}
                                onChange={(e) => { setTenantFilter(e.value); setPage(1); }}
                                options={tenantFilterOptions}
                                placeholder="Tenant"
                                className="w-48"
                            />
                            <Dropdown
                                value={statusFilter}
                                onChange={(e) => { setStatusFilter(e.value); setPage(1); }}
                                options={statusOptions}
                                placeholder="Status"
                                className="w-40"
                            />
                        </div>
                    </div>
                </div>

                {/* Users Table */}
                <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-blue)' } as React.CSSProperties}>
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-users" />
                            <span>Users</span>
                            <span className="text-xs font-normal text-[var(--acu-text-light)] ml-1">
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
                            <Column header="Tenant" body={tenantTemplate} />
                            <Column header="Roles" body={rolesTemplate} />
                            <Column header="Status" body={statusTemplate} />
                            <Column header="" body={actionsTemplate} style={{ width: '4rem' }} />
                        </DataTable>

                        {meta && meta.last_page > 1 && (
                            <div className="flex items-center justify-between px-4 py-3 border-t border-[var(--acu-border)]">
                                <span className="text-xs text-[var(--acu-text-light)]">
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

            {/* Create User Dialog */}
            <Dialog
                header="Add User"
                visible={createDialogOpen}
                style={{ width: '28rem' }}
                onHide={() => setCreateDialogOpen(false)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button
                            label="Cancel"
                            icon="pi pi-times"
                            severity="secondary"
                            outlined
                            onClick={() => setCreateDialogOpen(false)}
                        />
                        <Button
                            label={createSaving ? 'Creating...' : 'Create'}
                            icon="pi pi-check"
                            onClick={createUser}
                            disabled={createSaving}
                            loading={createSaving}
                        />
                    </div>
                }
            >
                <div className="space-y-4">
                    <div>
                        <label className="block text-sm font-medium text-[var(--acu-text)] mb-1">Name</label>
                        <InputText
                            value={createForm.name}
                            onChange={(e) => setCreateForm({ ...createForm, name: e.target.value })}
                            className={`w-full ${createErrors.name ? 'p-invalid' : ''}`}
                            placeholder="Full name"
                        />
                        {createErrors.name && <small className="text-red-500">{createErrors.name[0]}</small>}
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-[var(--acu-text)] mb-1">Email</label>
                        <InputText
                            value={createForm.email}
                            onChange={(e) => setCreateForm({ ...createForm, email: e.target.value })}
                            className={`w-full ${createErrors.email ? 'p-invalid' : ''}`}
                            placeholder="user@example.com"
                            type="email"
                        />
                        {createErrors.email && <small className="text-red-500">{createErrors.email[0]}</small>}
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-[var(--acu-text)] mb-1">Password</label>
                        <Password
                            value={createForm.password}
                            onChange={(e) => setCreateForm({ ...createForm, password: e.target.value })}
                            className={`w-full ${createErrors.password ? 'p-invalid' : ''}`}
                            inputClassName="w-full"
                            placeholder="Minimum 8 characters"
                            toggleMask
                            feedback={false}
                        />
                        {createErrors.password && <small className="text-red-500">{createErrors.password[0]}</small>}
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-[var(--acu-text)] mb-1">Tenant</label>
                        <Dropdown
                            value={createForm.tenant_id}
                            onChange={(e) => setCreateForm({ ...createForm, tenant_id: e.value })}
                            options={[{ label: 'Platform (no tenant)', value: null }, ...tenants]}
                            placeholder="Select tenant..."
                            className="w-full"
                        />
                        {createErrors.tenant_id && <small className="text-red-500">{createErrors.tenant_id[0]}</small>}
                    </div>
                </div>
            </Dialog>

            {/* Role Management Dialog */}
            <Dialog
                header={`Manage Roles \u2014 ${roleDialogUser?.name || ''}`}
                visible={roleDialogOpen}
                style={{ width: '36rem' }}
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
                        <i className="pi pi-spin pi-spinner text-2xl" />
                    </div>
                ) : (
                    <div className="space-y-5">
                        {/* Platform Roles */}
                        {platformRoles.length > 0 && (
                            <div>
                                <h4 className="text-sm font-semibold text-[var(--acu-text)] mb-2">Platform Roles</h4>
                                <div className="space-y-2">
                                    {platformRoles.map((role) => (
                                        <div key={role.name} className="flex items-start gap-3 p-2 rounded hover:bg-[var(--acu-surface-hover)]">
                                            <Checkbox
                                                inputId={`platform-${role.name}`}
                                                checked={selectedPlatformRoles.includes(role.name)}
                                                onChange={(e) => {
                                                    if (e.checked) {
                                                        setSelectedPlatformRoles([...selectedPlatformRoles, role.name]);
                                                    } else {
                                                        setSelectedPlatformRoles(selectedPlatformRoles.filter((r) => r !== role.name));
                                                    }
                                                }}
                                            />
                                            <label htmlFor={`platform-${role.name}`} className="cursor-pointer">
                                                <div className="text-sm font-medium text-[var(--acu-text)]">{role.display_name}</div>
                                                <div className="text-xs text-[var(--acu-text-light)]">{role.description}</div>
                                            </label>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Tenant Roles */}
                        <div>
                            <h4 className="text-sm font-semibold text-[var(--acu-text)] mb-2">Tenant Roles</h4>

                            {/* Existing tenant role assignments */}
                            {tenantRoleAssignments.length > 0 && (
                                <div className="flex flex-wrap gap-2 mb-3">
                                    {tenantRoleAssignments.map((a, i) => (
                                        <Chip
                                            key={i}
                                            label={`${a.display_name} @ ${a.tenant_name}`}
                                            removable
                                            onRemove={() => { removeTenantRole(a.role, a.tenant_id); return true; }}
                                        />
                                    ))}
                                </div>
                            )}

                            {/* Add new tenant role */}
                            <div className="space-y-2">
                                <Dropdown
                                    value={selectedTenantForRole}
                                    onChange={(e) => { setSelectedTenantForRole(e.value); setSelectedTenantRoles([]); }}
                                    options={tenants}
                                    placeholder="Select tenant..."
                                    className="w-full"
                                />
                                {selectedTenantForRole && (
                                    <div className="space-y-2 pl-2">
                                        {tenantRoles.map((role) => (
                                            <div key={role.name} className="flex items-start gap-3 p-2 rounded hover:bg-[var(--acu-surface-hover)]">
                                                <Checkbox
                                                    inputId={`tenant-${role.name}`}
                                                    checked={selectedTenantRoles.includes(role.name)}
                                                    onChange={(e) => {
                                                        if (e.checked) {
                                                            setSelectedTenantRoles([...selectedTenantRoles, role.name]);
                                                        } else {
                                                            setSelectedTenantRoles(selectedTenantRoles.filter((r) => r !== role.name));
                                                        }
                                                    }}
                                                />
                                                <label htmlFor={`tenant-${role.name}`} className="cursor-pointer">
                                                    <div className="text-sm font-medium text-[var(--acu-text)]">{role.display_name}</div>
                                                    <div className="text-xs text-[var(--acu-text-light)]">{role.description}</div>
                                                </label>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                )}
            </Dialog>
        </UserLayout>
    );
}
