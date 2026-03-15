import PageHeader from '@/components/acumatica/Common/PageHeader';
import StatusBadge from '@/components/acumatica/Common/StatusBadge';
import UserLayout from '@/layouts/user-layout';
import type { StatusVariant } from '@/types/acumatica';
import { Head, router } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Checkbox } from 'primereact/checkbox';
import { Column } from 'primereact/column';
import { DataTable } from 'primereact/datatable';
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
    kyc_level: number;
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
    kyc_levels: {
        unverified: number;
        basic: number;
        enhanced: number;
        full: number;
    };
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

function getKycLevelName(level: number): string {
    return ['Unverified', 'Basic', 'Enhanced', 'Full'][level] || 'Unknown';
}

interface StatCardProps {
    icon: string;
    iconColor: string;
    title: string;
    value: string | number;
}

function StatCard({ icon, iconColor, title, value }: StatCardProps) {
    return (
        <div className="acu-fieldset">
            <div className="p-4">
                <div className="flex items-center justify-between mb-3">
                    <span className="text-xs font-semibold uppercase tracking-wide text-[var(--acu-text-muted)]">
                        {title}
                    </span>
                    <div
                        className="w-8 h-8 rounded-lg flex items-center justify-center"
                        style={{ backgroundColor: `${iconColor}15`, color: iconColor }}
                    >
                        <i className={`${icon} text-sm`} />
                    </div>
                </div>
                <div className="text-2xl font-bold text-[var(--acu-text)]">{value}</div>
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

const kycOptions = [
    { label: 'All Levels', value: '' },
    { label: 'Unverified', value: '0' },
    { label: 'Basic', value: '1' },
    { label: 'Enhanced', value: '2' },
    { label: 'Full', value: '3' },
];

export default function Users() {
    const [users, setUsers] = useState<User[]>([]);
    const [meta, setMeta] = useState<Meta | null>(null);
    const [stats, setStats] = useState<Stats | null>(null);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [kycFilter, setKycFilter] = useState('');
    const [page, setPage] = useState(1);

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
            if (kycFilter) params.append('kyc_level', kycFilter);
            params.append('page', page.toString());

            const response = await fetch(`/api/v1/admin/users?${params}`, {
                headers: {
                    Accept: 'application/json',
                },
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
    }, [page, statusFilter, kycFilter]);

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

    const handleSearch = () => {
        setPage(1);
        fetchUsers();
    };

    const userTemplate = (row: User) => (
        <div>
            <div className="font-medium text-sm text-[var(--acu-text)]">{row.name}</div>
            <div className="text-xs text-[var(--acu-text-light)]">{row.email}</div>
        </div>
    );

    const statusTemplate = (row: User) => (
        <StatusBadge status={mapUserStatusToVariant(row.status)} label={row.status} />
    );

    const kycTemplate = (row: User) => (
        <span className="text-xs font-medium text-[var(--acu-text)]">
            {getKycLevelName(row.kyc_level)}
        </span>
    );

    const verifiedTemplate = (row: User) => (
        <span className="text-sm text-[var(--acu-text)]">
            {row.email_verified_at ? (
                <i className="pi pi-check-circle text-green-600" />
            ) : (
                <i className="pi pi-times-circle text-[var(--acu-text-light)]" />
            )}
        </span>
    );

    const registeredTemplate = (row: User) => (
        <span className="text-sm text-[var(--acu-text)]">
            {new Date(row.created_at).toLocaleDateString()}
        </span>
    );

    const lastLoginTemplate = (row: User) => (
        <span className="text-sm text-[var(--acu-text)]">
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
            />
        </div>
    );

    return (
        <UserLayout title="User Management">
            <Head title="User Management" />
            <Toast ref={toast} />

            <div className="space-y-6">
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
                </PageHeader>

                {/* Stats */}
                {stats && (
                    <div className="grid gap-4 md:grid-cols-4">
                        <StatCard
                            icon="pi pi-users"
                            iconColor="#3B82F6"
                            title="Total Users"
                            value={stats.total_users.toLocaleString()}
                        />
                        <StatCard
                            icon="pi pi-check-circle"
                            iconColor="#10B981"
                            title="Active"
                            value={stats.active_users.toLocaleString()}
                        />
                        <StatCard
                            icon="pi pi-pause-circle"
                            iconColor="#F59E0B"
                            title="Suspended"
                            value={stats.suspended_users}
                        />
                        <StatCard
                            icon="pi pi-ban"
                            iconColor="#EF4444"
                            title="Banned"
                            value={stats.banned_users}
                        />
                    </div>
                )}

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
                                        placeholder="Search by name, email, username..."
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
                                value={statusFilter}
                                onChange={(e) => {
                                    setStatusFilter(e.value);
                                    setPage(1);
                                }}
                                options={statusOptions}
                                placeholder="Status"
                                className="w-40"
                            />
                            <Dropdown
                                value={kycFilter}
                                onChange={(e) => {
                                    setKycFilter(e.value);
                                    setPage(1);
                                }}
                                options={kycOptions}
                                placeholder="KYC Level"
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
                                {meta?.total
                                    ? `(${users.length} of ${meta.total})`
                                    : ''}
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
                            <Column header="KYC Level" body={kycTemplate} />
                            <Column header="Verified" body={verifiedTemplate} style={{ textAlign: 'center' }} />
                            <Column header="Registered" body={registeredTemplate} />
                            <Column header="Last Login" body={lastLoginTemplate} />
                            <Column header="" body={actionsTemplate} style={{ width: '4rem' }} />
                        </DataTable>

                        {/* Pagination */}
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
                        <i className="pi pi-spin pi-spinner text-2xl" />
                    </div>
                ) : (
                    <div className="space-y-3">
                        {availableRoles.length === 0 ? (
                            <p className="text-sm text-[var(--acu-text-muted)]">No roles available to assign.</p>
                        ) : (
                            availableRoles.map((role) => (
                                <div key={role.name} className="flex items-start gap-3 p-2 rounded hover:bg-[var(--acu-surface-hover)]">
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
                                        <div className="text-sm font-medium text-[var(--acu-text)]">{role.display_name}</div>
                                        <div className="text-xs text-[var(--acu-text-light)]">{role.description}</div>
                                    </label>
                                </div>
                            ))
                        )}
                    </div>
                )}
            </Dialog>
        </UserLayout>
    );
}
