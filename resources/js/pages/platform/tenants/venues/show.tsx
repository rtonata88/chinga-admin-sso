import PageHeader from '@/components/acumatica/Common/PageHeader';
import StatusBadge from '@/components/acumatica/Common/StatusBadge';
import UserLayout from '@/layouts/user-layout';
import type { StatusVariant } from '@/types/acumatica';
import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Column } from 'primereact/column';
import { DataTable } from 'primereact/datatable';
import { Dialog } from 'primereact/dialog';
import { Dropdown } from 'primereact/dropdown';
import { InputText } from 'primereact/inputtext';
import { useEffect, useState } from 'react';

interface VenueDetails {
    uuid: string;
    name: string;
    slug: string;
    business_name: string | null;
    license_number: string | null;
    address_line_1: string;
    address_line_2: string | null;
    city: string;
    region: string | null;
    postal_code: string | null;
    country_code: string;
    phone: string | null;
    email: string | null;
    timezone: string | null;
    currency: string | null;
    status: string;
    staff_count: number;
    terminals_count: number;
    voucher_codes_count: number;
    stats: {
        active_codes_balance: number;
        total_loaded: number;
        total_cashed_out: number;
    };
    created_at: string;
}

interface Staff {
    uuid: string;
    username: string;
    display_name: string;
    email: string | null;
    phone: string | null;
    role: string;
    status: string;
    last_login_at: string | null;
    created_at: string;
}

interface Terminal {
    uuid: string;
    terminal_code: string;
    name: string;
    type: string;
    status: string;
    last_heartbeat_at: string | null;
    ip_address: string | null;
    created_at: string;
}

function formatCurrency(amount: number, currency: string = 'NAD'): string {
    return `${currency} ${amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}`;
}

export default function VenueShow() {
    const { tenantUuid, uuid } = usePage<{ tenantUuid: string; uuid: string }>().props;

    const [venue, setVenue] = useState<VenueDetails | null>(null);
    const [staff, setStaff] = useState<Staff[]>([]);
    const [terminals, setTerminals] = useState<Terminal[]>([]);
    const [loading, setLoading] = useState(true);
    const [activeTab, setActiveTab] = useState<'staff' | 'terminals'>('staff');

    // Add staff dialog
    const [addStaffOpen, setAddStaffOpen] = useState(false);
    const [savingStaff, setSavingStaff] = useState(false);
    const [staffForm, setStaffForm] = useState({
        username: '',
        password: '',
        display_name: '',
        email: '',
        phone: '',
        role: 'staff',
        pin: '',
    });

    // Add terminal dialog
    const [addTerminalOpen, setAddTerminalOpen] = useState(false);
    const [savingTerminal, setSavingTerminal] = useState(false);
    const [terminalForm, setTerminalForm] = useState({
        terminal_code: '',
        name: '',
        type: 'terminal',
    });
    const [newTerminalApiKey, setNewTerminalApiKey] = useState<string | null>(null);

    const apiBase = `/api/v1/platform/tenants/${tenantUuid}/venues/${uuid}`;

    const getCsrfToken = () => {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    };

    const fetchVenue = async () => {
        try {
            const response = await fetch(apiBase, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (data.success) {
                setVenue(data.data);
            }
        } catch (error) {
            console.error('Failed to fetch venue:', error);
        } finally {
            setLoading(false);
        }
    };

    const fetchStaff = async () => {
        try {
            const response = await fetch(`${apiBase}/staff`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (data.success) {
                setStaff(data.data);
            }
        } catch (error) {
            console.error('Failed to fetch staff:', error);
        }
    };

    const fetchTerminals = async () => {
        try {
            const response = await fetch(`${apiBase}/terminals`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (data.success) {
                setTerminals(data.data);
            }
        } catch (error) {
            console.error('Failed to fetch terminals:', error);
        }
    };

    useEffect(() => {
        fetchVenue();
        fetchStaff();
        fetchTerminals();
    }, [tenantUuid, uuid]);

    const handleAddStaff = async () => {
        setSavingStaff(true);
        try {
            const response = await fetch(`${apiBase}/staff`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify(staffForm),
            });
            const data = await response.json();
            if (data.success) {
                setAddStaffOpen(false);
                setStaffForm({
                    username: '',
                    password: '',
                    display_name: '',
                    email: '',
                    phone: '',
                    role: 'staff',
                    pin: '',
                });
                fetchStaff();
                fetchVenue();
            } else {
                alert(data.message || 'Failed to add staff');
            }
        } catch (error) {
            console.error('Failed to add staff:', error);
            alert('Failed to add staff');
        } finally {
            setSavingStaff(false);
        }
    };

    const handleAddTerminal = async () => {
        setSavingTerminal(true);
        try {
            const response = await fetch(`${apiBase}/terminals`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify(terminalForm),
            });
            const data = await response.json();
            if (data.success) {
                setNewTerminalApiKey(data.data.api_key);
                setTerminalForm({
                    terminal_code: '',
                    name: '',
                    type: 'terminal',
                });
                fetchTerminals();
                fetchVenue();
            } else {
                alert(data.message || 'Failed to add terminal');
            }
        } catch (error) {
            console.error('Failed to add terminal:', error);
            alert('Failed to add terminal');
        } finally {
            setSavingTerminal(false);
        }
    };

    const handleStatusChange = async (newStatus: 'active' | 'suspended') => {
        const action = newStatus === 'active' ? 'activate' : 'suspend';
        try {
            const response = await fetch(`${apiBase}/${action}`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (data.success) {
                fetchVenue();
            } else {
                alert(data.message || `Failed to ${action} venue`);
            }
        } catch (error) {
            console.error(`Failed to ${action} venue:`, error);
        }
    };

    if (loading) {
        return (
            <UserLayout>
                <Head title="Loading..." />
                <div className="flex items-center justify-center py-20">
                    <p className="text-[var(--acu-text-muted)]">Loading venue...</p>
                </div>
            </UserLayout>
        );
    }

    if (!venue) {
        return (
            <UserLayout>
                <Head title="Venue Not Found" />
                <div className="flex flex-col items-center justify-center py-20">
                    <p className="text-[var(--acu-text-muted)] mb-4">Venue not found</p>
                    <Link href={`/platform/tenants/${tenantUuid}`}>
                        <Button label="Back to Tenant" icon="pi pi-arrow-left" />
                    </Link>
                </div>
            </UserLayout>
        );
    }

    const staffNameTemplate = (s: Staff) => (
        <div>
            <p className="font-medium">{s.display_name}</p>
            {s.email && (
                <p className="text-sm text-[var(--acu-text-muted)]">{s.email}</p>
            )}
        </div>
    );

    const staffUsernameTemplate = (s: Staff) => (
        <code className="text-sm">{s.username}</code>
    );

    const staffRoleTemplate = (s: Staff) => (
        <StatusBadge status={'inactive' as StatusVariant} label={s.role} />
    );

    const staffStatusTemplate = (s: Staff) => (
        <StatusBadge status={s.status as StatusVariant} />
    );

    const staffLastLoginTemplate = (s: Staff) => (
        <span className="text-[var(--acu-text-muted)]">
            {s.last_login_at
                ? new Date(s.last_login_at).toLocaleString()
                : 'Never'}
        </span>
    );

    const terminalCodeTemplate = (t: Terminal) => (
        <code className="text-sm">{t.terminal_code}</code>
    );

    const terminalTypeTemplate = (t: Terminal) => (
        <StatusBadge status={'inactive' as StatusVariant} label={t.type} />
    );

    const terminalStatusTemplate = (t: Terminal) => (
        <StatusBadge status={t.status as StatusVariant} />
    );

    const terminalHeartbeatTemplate = (t: Terminal) => (
        <span className="text-[var(--acu-text-muted)]">
            {t.last_heartbeat_at
                ? new Date(t.last_heartbeat_at).toLocaleString()
                : 'Never'}
        </span>
    );

    const terminalIpTemplate = (t: Terminal) => (
        <span className="text-[var(--acu-text-muted)]">
            {t.ip_address || '-'}
        </span>
    );

    const roleOptions = [
        { label: 'Owner', value: 'owner' },
        { label: 'Manager', value: 'manager' },
        { label: 'Staff', value: 'staff' },
        { label: 'Cashier', value: 'cashier' },
    ];

    const terminalTypeOptions = [
        { label: 'Kiosk', value: 'kiosk' },
        { label: 'Tablet', value: 'tablet' },
        { label: 'Terminal', value: 'terminal' },
        { label: 'POS', value: 'pos' },
    ];

    return (
        <UserLayout>
            <Head title={venue.name} />

            <div className="space-y-6">
                <PageHeader title={venue.name} subtitle={venue.slug}>
                    <StatusBadge status={venue.status as StatusVariant} />
                    <Button
                        label="Refresh"
                        icon="pi pi-refresh"
                        severity="secondary"
                        outlined
                        onClick={() => { fetchVenue(); fetchStaff(); fetchTerminals(); }}
                    />
                    {venue.status === 'active' ? (
                        <Button
                            label="Suspend Venue"
                            severity="danger"
                            onClick={() => handleStatusChange('suspended')}
                        />
                    ) : (
                        <Button
                            label="Activate Venue"
                            onClick={() => handleStatusChange('active')}
                        />
                    )}
                </PageHeader>

                <div className="mb-4">
                    <Link href={`/platform/tenants/${tenantUuid}`} className="inline-flex items-center gap-1 text-sm text-[var(--acu-text-muted)] hover:text-[var(--acu-text)]">
                        <i className="pi pi-arrow-left text-xs" />
                        Back to Tenant
                    </Link>
                </div>

                {/* Stats */}
                <div className="grid gap-4 md:grid-cols-4">
                    <div className="acu-fieldset">
                        <div className="p-4">
                            <span className="text-xs font-semibold uppercase tracking-wide text-[var(--acu-text-muted)]">Staff</span>
                            <div className="text-2xl font-bold text-[var(--acu-text)]">{venue.staff_count}</div>
                        </div>
                    </div>
                    <div className="acu-fieldset">
                        <div className="p-4">
                            <span className="text-xs font-semibold uppercase tracking-wide text-[var(--acu-text-muted)]">Terminals</span>
                            <div className="text-2xl font-bold text-[var(--acu-text)]">{venue.terminals_count}</div>
                        </div>
                    </div>
                    <div className="acu-fieldset">
                        <div className="p-4">
                            <span className="text-xs font-semibold uppercase tracking-wide text-[var(--acu-text-muted)]">Voucher Codes</span>
                            <div className="text-2xl font-bold text-[var(--acu-text)]">{venue.voucher_codes_count}</div>
                        </div>
                    </div>
                    <div className="acu-fieldset">
                        <div className="p-4">
                            <span className="text-xs font-semibold uppercase tracking-wide text-[var(--acu-text-muted)]">Active Balance</span>
                            <div className="text-2xl font-bold text-[var(--acu-text)]">
                                {formatCurrency(venue.stats.active_codes_balance, venue.currency || 'NAD')}
                            </div>
                        </div>
                    </div>
                </div>

                {/* Venue Details */}
                <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-blue)' } as React.CSSProperties}>
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-map-marker" />
                            <span>Venue Details</span>
                        </div>
                    </div>
                    <div className="acu-fieldset-body">
                        <div className="grid gap-4 md:grid-cols-2">
                            <div>
                                <p className="text-sm text-[var(--acu-text-muted)]">Address</p>
                                <p className="font-medium">{venue.address_line_1}</p>
                                {venue.address_line_2 && <p>{venue.address_line_2}</p>}
                                <p>{venue.city}, {venue.region} {venue.postal_code}</p>
                                <p>{venue.country_code}</p>
                            </div>
                            <div className="space-y-2">
                                {venue.phone && (
                                    <div>
                                        <p className="text-sm text-[var(--acu-text-muted)]">Phone</p>
                                        <p className="font-medium">{venue.phone}</p>
                                    </div>
                                )}
                                {venue.email && (
                                    <div>
                                        <p className="text-sm text-[var(--acu-text-muted)]">Email</p>
                                        <p className="font-medium">{venue.email}</p>
                                    </div>
                                )}
                                {venue.business_name && (
                                    <div>
                                        <p className="text-sm text-[var(--acu-text-muted)]">Business Name</p>
                                        <p className="font-medium">{venue.business_name}</p>
                                    </div>
                                )}
                                {venue.license_number && (
                                    <div>
                                        <p className="text-sm text-[var(--acu-text-muted)]">License Number</p>
                                        <p className="font-medium">{venue.license_number}</p>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </div>

                {/* Tabs */}
                <div className="flex gap-2 border-b border-[var(--acu-border)]">
                    <Button
                        label={`Staff (${staff.length})`}
                        icon="pi pi-users"
                        outlined={activeTab !== 'staff'}
                        onClick={() => setActiveTab('staff')}
                        size="small"
                    />
                    <Button
                        label={`Terminals (${terminals.length})`}
                        icon="pi pi-desktop"
                        outlined={activeTab !== 'terminals'}
                        onClick={() => setActiveTab('terminals')}
                        size="small"
                    />
                </div>

                {/* Staff Tab */}
                {activeTab === 'staff' && (
                    <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-blue)' } as React.CSSProperties}>
                        <div className="acu-fieldset-header">
                            <div className="acu-fieldset-title">
                                <i className="pi pi-users" />
                                <span>Staff Members</span>
                            </div>
                            <Button
                                label="Add Staff"
                                icon="pi pi-plus"
                                size="small"
                                onClick={() => setAddStaffOpen(true)}
                            />
                        </div>
                        <div className="acu-fieldset-body">
                            <DataTable
                                value={staff}
                                emptyMessage="No staff members"
                                stripedRows
                                size="small"
                            >
                                <Column header="Name" body={staffNameTemplate} />
                                <Column header="Username" body={staffUsernameTemplate} />
                                <Column header="Role" body={staffRoleTemplate} />
                                <Column header="Status" body={staffStatusTemplate} />
                                <Column header="Last Login" body={staffLastLoginTemplate} />
                            </DataTable>
                        </div>
                    </div>
                )}

                {/* Terminals Tab */}
                {activeTab === 'terminals' && (
                    <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-blue)' } as React.CSSProperties}>
                        <div className="acu-fieldset-header">
                            <div className="acu-fieldset-title">
                                <i className="pi pi-desktop" />
                                <span>Terminals</span>
                            </div>
                            <Button
                                label="Add Terminal"
                                icon="pi pi-plus"
                                size="small"
                                onClick={() => setAddTerminalOpen(true)}
                            />
                        </div>
                        <div className="acu-fieldset-body">
                            <DataTable
                                value={terminals}
                                emptyMessage="No terminals"
                                stripedRows
                                size="small"
                            >
                                <Column header="Terminal" field="name" />
                                <Column header="Code" body={terminalCodeTemplate} />
                                <Column header="Type" body={terminalTypeTemplate} />
                                <Column header="Status" body={terminalStatusTemplate} />
                                <Column header="Last Heartbeat" body={terminalHeartbeatTemplate} />
                                <Column header="IP Address" body={terminalIpTemplate} />
                            </DataTable>
                        </div>
                    </div>
                )}
            </div>

            {/* Add Staff Dialog */}
            <Dialog header="Add Staff Member" visible={addStaffOpen} onHide={() => setAddStaffOpen(false)} style={{ width: '500px' }}>
                <p className="text-sm text-[var(--acu-text-muted)] mb-4">Add a new staff member to {venue.name}</p>
                <div className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <label className="text-sm font-medium">Username *</label>
                            <InputText
                                value={staffForm.username}
                                onChange={(e) => setStaffForm({ ...staffForm, username: e.target.value })}
                                placeholder="johndoe"
                                className="w-full"
                            />
                        </div>
                        <div className="space-y-2">
                            <label className="text-sm font-medium">Password *</label>
                            <InputText
                                type="password"
                                value={staffForm.password}
                                onChange={(e) => setStaffForm({ ...staffForm, password: e.target.value })}
                                placeholder="********"
                                className="w-full"
                            />
                        </div>
                    </div>
                    <div className="space-y-2">
                        <label className="text-sm font-medium">Display Name *</label>
                        <InputText
                            value={staffForm.display_name}
                            onChange={(e) => setStaffForm({ ...staffForm, display_name: e.target.value })}
                            placeholder="John Doe"
                            className="w-full"
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <label className="text-sm font-medium">Email</label>
                            <InputText
                                type="email"
                                value={staffForm.email}
                                onChange={(e) => setStaffForm({ ...staffForm, email: e.target.value })}
                                placeholder="john@example.com"
                                className="w-full"
                            />
                        </div>
                        <div className="space-y-2">
                            <label className="text-sm font-medium">Phone</label>
                            <InputText
                                value={staffForm.phone}
                                onChange={(e) => setStaffForm({ ...staffForm, phone: e.target.value })}
                                placeholder="+264 61 123 4567"
                                className="w-full"
                            />
                        </div>
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <label className="text-sm font-medium">Role *</label>
                            <Dropdown
                                value={staffForm.role}
                                onChange={(e) => setStaffForm({ ...staffForm, role: e.value })}
                                options={roleOptions}
                                className="w-full"
                            />
                        </div>
                        <div className="space-y-2">
                            <label className="text-sm font-medium">PIN (4 digits)</label>
                            <InputText
                                value={staffForm.pin}
                                onChange={(e) => setStaffForm({ ...staffForm, pin: e.target.value.replace(/\D/g, '').slice(0, 4) })}
                                placeholder="1234"
                                maxLength={4}
                                className="w-full"
                            />
                        </div>
                    </div>
                </div>
                <div className="flex justify-end gap-2 mt-4">
                    <Button label="Cancel" severity="secondary" outlined onClick={() => setAddStaffOpen(false)} />
                    <Button
                        label={savingStaff ? 'Adding...' : 'Add Staff'}
                        onClick={handleAddStaff}
                        disabled={savingStaff || !staffForm.username || !staffForm.password || !staffForm.display_name}
                        loading={savingStaff}
                    />
                </div>
            </Dialog>

            {/* Add Terminal Dialog */}
            <Dialog
                header={newTerminalApiKey ? 'Terminal Created' : 'Add Terminal'}
                visible={addTerminalOpen}
                onHide={() => {
                    setAddTerminalOpen(false);
                    setNewTerminalApiKey(null);
                }}
                style={{ width: '500px' }}
            >
                <p className="text-sm text-[var(--acu-text-muted)] mb-4">
                    {newTerminalApiKey
                        ? 'Save the API key below - it will only be shown once!'
                        : `Register a new terminal for ${venue.name}`}
                </p>
                {newTerminalApiKey ? (
                    <div className="space-y-4">
                        <div className="rounded-lg border border-[var(--acu-border)] bg-[var(--acu-surface-ground)] p-4">
                            <p className="text-sm text-[var(--acu-text-muted)] mb-2">API Key (save this now!):</p>
                            <code className="text-sm break-all">{newTerminalApiKey}</code>
                        </div>
                        <Button
                            label="Copy API Key"
                            icon="pi pi-copy"
                            className="w-full"
                            onClick={() => {
                                navigator.clipboard.writeText(newTerminalApiKey);
                                alert('API key copied to clipboard');
                            }}
                        />
                    </div>
                ) : (
                    <div className="space-y-4">
                        <div className="space-y-2">
                            <label className="text-sm font-medium">Terminal Code *</label>
                            <InputText
                                value={terminalForm.terminal_code}
                                onChange={(e) => setTerminalForm({ ...terminalForm, terminal_code: e.target.value })}
                                placeholder="TERM-001"
                                className="w-full"
                            />
                        </div>
                        <div className="space-y-2">
                            <label className="text-sm font-medium">Name *</label>
                            <InputText
                                value={terminalForm.name}
                                onChange={(e) => setTerminalForm({ ...terminalForm, name: e.target.value })}
                                placeholder="Front Desk Terminal"
                                className="w-full"
                            />
                        </div>
                        <div className="space-y-2">
                            <label className="text-sm font-medium">Type *</label>
                            <Dropdown
                                value={terminalForm.type}
                                onChange={(e) => setTerminalForm({ ...terminalForm, type: e.value })}
                                options={terminalTypeOptions}
                                className="w-full"
                            />
                        </div>
                    </div>
                )}
                <div className="flex justify-end gap-2 mt-4">
                    <Button
                        label={newTerminalApiKey ? 'Close' : 'Cancel'}
                        severity="secondary"
                        outlined
                        onClick={() => {
                            setAddTerminalOpen(false);
                            setNewTerminalApiKey(null);
                        }}
                    />
                    {!newTerminalApiKey && (
                        <Button
                            label={savingTerminal ? 'Adding...' : 'Add Terminal'}
                            onClick={handleAddTerminal}
                            disabled={savingTerminal || !terminalForm.terminal_code || !terminalForm.name}
                            loading={savingTerminal}
                        />
                    )}
                </div>
            </Dialog>
        </UserLayout>
    );
}
