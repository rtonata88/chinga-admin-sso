// resources/js/pages/admin/venues/show.tsx
//
// Tenant-admin venue detail. Tenant context is implicit — the
// /api/v1/admin/venues/{uuid} endpoints auto-scope via Venue's
// BelongsToTenant trait, so a tenant admin can never load another
// tenant's venue from here. Add-staff and add-terminal dialogs stay
// on PrimeReact for their multi-field form state.

import { KpiCard, formatCount, formatCurrencyCompact } from '@/components/operator/kpi-card';
import UserLayout from '@/layouts/user-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { Dropdown } from 'primereact/dropdown';
import { InputText } from 'primereact/inputtext';
import { Toast } from 'primereact/toast';
import { useEffect, useRef, useState } from 'react';

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

function statusPill(status: string | null | undefined): string {
    switch (status) {
        case 'active': return 'live';
        case 'suspended': return 'flagged';
        case 'inactive':
        case 'closed':
        case 'offline': return 'void';
        case 'pending': return 'pending';
        default: return 'void';
    }
}

const ROLE_OPTIONS = [
    { label: 'Owner', value: 'owner' },
    { label: 'Manager', value: 'manager' },
    { label: 'Staff', value: 'staff' },
    { label: 'Cashier', value: 'cashier' },
];

const TERMINAL_TYPE_OPTIONS = [
    { label: 'Kiosk', value: 'kiosk' },
    { label: 'Tablet', value: 'tablet' },
    { label: 'Terminal', value: 'terminal' },
    { label: 'POS', value: 'pos' },
];

const DATETIME_FMT = new Intl.DateTimeFormat('en-GB', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
});

function formatDateTime(iso: string | null): string {
    return iso ? DATETIME_FMT.format(new Date(iso)) : '—';
}

interface InfoItem {
    label: string;
    value: React.ReactNode;
    span?: number;
    mono?: boolean;
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
                    <div key={i} style={{ gridColumn: `span ${it.span ?? 6}` }}>
                        <div
                            style={{
                                fontSize: 10, textTransform: 'uppercase', letterSpacing: '0.16em',
                                color: 'var(--cg-fg-3)', fontWeight: 600, marginBottom: 4,
                            }}
                        >
                            {it.label}
                        </div>
                        <div
                            style={{
                                fontSize: 13, color: 'var(--cg-fg-1)',
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

export default function VenueShow() {
    const { uuid } = usePage<{ uuid: string }>().props;
    const apiBase = `/api/v1/admin/venues/${uuid}`;

    const [venue, setVenue] = useState<VenueDetails | null>(null);
    const [staff, setStaff] = useState<Staff[]>([]);
    const [terminals, setTerminals] = useState<Terminal[]>([]);
    const [loading, setLoading] = useState(true);
    const [activeTab, setActiveTab] = useState<'staff' | 'terminals'>('staff');
    const toast = useRef<Toast>(null);

    const [addStaffOpen, setAddStaffOpen] = useState(false);
    const [savingStaff, setSavingStaff] = useState(false);
    const [staffForm, setStaffForm] = useState({
        username: '', password: '', display_name: '',
        email: '', phone: '', role: 'staff', pin: '',
    });

    const [addTerminalOpen, setAddTerminalOpen] = useState(false);
    const [savingTerminal, setSavingTerminal] = useState(false);
    const [terminalForm, setTerminalForm] = useState({
        terminal_code: '', name: '', type: 'terminal',
    });
    const [newTerminalApiKey, setNewTerminalApiKey] = useState<string | null>(null);

    const getCsrfToken = () =>
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const fetchVenue = async () => {
        try {
            const response = await fetch(apiBase, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (data.success) setVenue(data.data);
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
            if (data.success) setStaff(data.data);
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
            if (data.success) setTerminals(data.data);
        } catch (error) {
            console.error('Failed to fetch terminals:', error);
        }
    };

    useEffect(() => {
        fetchVenue();
        fetchStaff();
        fetchTerminals();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [uuid]);

    const refreshAll = () => { fetchVenue(); fetchStaff(); fetchTerminals(); };

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
                setStaffForm({ username: '', password: '', display_name: '', email: '', phone: '', role: 'staff', pin: '' });
                fetchStaff();
                fetchVenue();
                toast.current?.show({ severity: 'success', summary: 'Created', detail: 'Staff added.' });
            } else {
                toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || 'Failed to add staff.' });
            }
        } catch {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to add staff.' });
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
                setTerminalForm({ terminal_code: '', name: '', type: 'terminal' });
                fetchTerminals();
                fetchVenue();
            } else {
                toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || 'Failed to add terminal.' });
            }
        } catch {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to add terminal.' });
        } finally {
            setSavingTerminal(false);
        }
    };

    const handleStatusChange = async (newStatus: 'active' | 'suspended') => {
        const action = newStatus === 'active' ? 'activate' : 'suspend';
        if (newStatus === 'suspended' && !confirm('Suspend this venue?')) return;
        try {
            const response = await fetch(`${apiBase}/${action}`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (data.success) {
                fetchVenue();
                toast.current?.show({
                    severity: newStatus === 'active' ? 'success' : 'warn',
                    summary: newStatus === 'active' ? 'Activated' : 'Suspended',
                    detail: `Venue ${action}d.`,
                });
            } else {
                toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || `Failed to ${action} venue.` });
            }
        } catch {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: `Failed to ${action} venue.` });
        }
    };

    if (loading) {
        return (
            <UserLayout title="Venue">
                <Head title="Loading…" />
                <div className="cgo-page">
                    <div style={{ color: 'var(--cg-fg-3)', padding: '40px 0' }}>Loading venue…</div>
                </div>
            </UserLayout>
        );
    }

    if (!venue) {
        return (
            <UserLayout title="Venue">
                <Head title="Venue not found" />
                <div className="cgo-page">
                    <div className="cgo-page-head">
                        <div>
                            <div className="cgo-eyebrow">Admin · Venue</div>
                            <h1 className="cgo-title">Venue not found</h1>
                            <div className="cgo-subtitle">This venue does not exist or doesn't belong to your tenant.</div>
                        </div>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={() => router.visit('/admin/venues')}
                        >
                            ← Back to venues
                        </button>
                    </div>
                </div>
            </UserLayout>
        );
    }

    const addressLines = [
        venue.address_line_1,
        venue.address_line_2,
        [venue.city, venue.region, venue.postal_code].filter(Boolean).join(', '),
        venue.country_code,
    ].filter(Boolean);

    const addressItems: InfoItem[] = [
        {
            label: 'Address',
            span: 12,
            value: addressLines.length ? (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                    {addressLines.map((line, i) => (<span key={i}>{line}</span>))}
                </div>
            ) : '—',
        },
        { label: 'City', value: venue.city || '—', span: 6 },
        { label: 'Country', value: venue.country_code || '—', mono: true, span: 6 },
        { label: 'Region', value: venue.region || '—', span: 6 },
        { label: 'Postal code', value: venue.postal_code || '—', mono: true, span: 6 },
    ];

    const businessItems: InfoItem[] = [
        { label: 'Slug', value: venue.slug, mono: true, span: 6 },
        { label: 'Business name', value: venue.business_name || '—', span: 6 },
        { label: 'License', value: venue.license_number || '—', mono: true, span: 6 },
        { label: 'Currency', value: venue.currency || '—', mono: true, span: 3 },
        { label: 'Timezone', value: venue.timezone || '—', mono: true, span: 3 },
        { label: 'Phone', value: venue.phone || '—', mono: true, span: 6 },
        { label: 'Email', value: venue.email || '—', span: 6 },
    ];

    return (
        <UserLayout title={venue.name}>
            <Head title={`${venue.name} · Venue`} />
            <Toast ref={toast} />

            <div className="cgo-page">
                {/* Page header */}
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Admin · Venue</div>
                        <h1 className="cgo-title">{venue.name}</h1>
                        <div className="cgo-subtitle" style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                            <span className="cgo-uid" style={{ fontFamily: 'var(--cg-mono)' }}>{venue.slug}</span>
                            <span className={`cgo-pill ${statusPill(venue.status)}`}>{venue.status}</span>
                        </div>
                    </div>
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={() => router.visit('/admin/venues')}
                        >
                            ← Back
                        </button>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={refreshAll}
                        >
                            Refresh
                        </button>
                        {venue.status === 'active' ? (
                            <button
                                type="button"
                                className="cg-btn cg-btn--ghost cg-btn--sm"
                                style={{ borderColor: 'var(--cg-neg)', color: 'var(--cg-neg)' }}
                                onClick={() => handleStatusChange('suspended')}
                            >
                                Suspend
                            </button>
                        ) : (
                            <button
                                type="button"
                                className="cg-btn cg-btn--primary cg-btn--sm"
                                onClick={() => handleStatusChange('active')}
                            >
                                Activate
                            </button>
                        )}
                    </div>
                </div>

                {/* KPI strip */}
                <div className="cgo-kpis">
                    <KpiCard label="Staff" value={formatCount(venue.staff_count)} meta="members" />
                    <KpiCard label="Terminals" value={formatCount(venue.terminals_count)} meta="registered" />
                    <KpiCard label="Voucher codes" value={formatCount(venue.voucher_codes_count)} meta="lifetime issued" />
                    <KpiCard
                        label="Active balance"
                        value={formatCurrencyCompact(venue.stats?.active_codes_balance ?? 0)}
                        brass
                        meta={venue.currency || 'NAD'}
                    />
                </div>

                {/* Address + Business */}
                <div
                    style={{
                        display: 'grid', gridTemplateColumns: '1fr 1fr',
                        gap: 16, marginBottom: 24,
                    }}
                >
                    <InfoPanel title="Location" items={addressItems} />
                    <InfoPanel title="Business & contact" items={businessItems} />
                </div>

                {/* Tab chips */}
                <div className="cgo-filterbar">
                    <button
                        type="button"
                        className={`cgo-chip${activeTab === 'staff' ? ' active' : ''}`}
                        onClick={() => setActiveTab('staff')}
                    >
                        Staff <span className="cgo-chip-count">{staff.length}</span>
                    </button>
                    <button
                        type="button"
                        className={`cgo-chip${activeTab === 'terminals' ? ' active' : ''}`}
                        onClick={() => setActiveTab('terminals')}
                    >
                        Terminals <span className="cgo-chip-count">{terminals.length}</span>
                    </button>
                    <div className="cgo-right">
                        {activeTab === 'staff' ? (
                            <button
                                type="button"
                                className="cg-btn cg-btn--primary cg-btn--sm"
                                onClick={() => setAddStaffOpen(true)}
                            >
                                + Add staff
                            </button>
                        ) : (
                            <button
                                type="button"
                                className="cg-btn cg-btn--primary cg-btn--sm"
                                onClick={() => setAddTerminalOpen(true)}
                            >
                                + Add terminal
                            </button>
                        )}
                    </div>
                </div>

                {/* Active tab table */}
                <div
                    className="cgo-table-wrap cgo-table-wrap--scroll"
                    style={{ borderRadius: '0 0 8px 8px', borderTop: 0 }}
                >
                    {activeTab === 'staff' ? (
                        <table className="cgo-wagers">
                            <thead>
                                <tr>
                                    <th style={{ minWidth: 220 }}>Name</th>
                                    <th style={{ minWidth: 160 }}>Username</th>
                                    <th style={{ width: 110 }}>Role</th>
                                    <th style={{ width: 100 }}>Status</th>
                                    <th style={{ width: 180 }}>Last login</th>
                                </tr>
                            </thead>
                            <tbody>
                                {staff.length === 0 ? (
                                    <tr>
                                        <td colSpan={5} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                            No staff yet. Click <strong style={{ color: 'var(--cg-fg-2)' }}>+ Add staff</strong>.
                                        </td>
                                    </tr>
                                ) : (
                                    staff.map((s) => (
                                        <tr key={s.uuid}>
                                            <td>
                                                <div className="cgo-name">{s.display_name}</div>
                                                {s.email && <div className="cgo-uid">{s.email}</div>}
                                            </td>
                                            <td>
                                                <span className="cgo-uid" style={{ fontFamily: 'var(--cg-mono)' }}>{s.username}</span>
                                            </td>
                                            <td>
                                                <span style={{ fontSize: 12, color: 'var(--cg-fg-2)', textTransform: 'capitalize' }}>
                                                    {s.role}
                                                </span>
                                            </td>
                                            <td>
                                                <span className={`cgo-pill ${statusPill(s.status)}`}>{s.status}</span>
                                            </td>
                                            <td>
                                                <span className="cgo-uid">
                                                    {s.last_login_at ? formatDateTime(s.last_login_at) : 'Never'}
                                                </span>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    ) : (
                        <table className="cgo-wagers">
                            <thead>
                                <tr>
                                    <th style={{ minWidth: 200 }}>Terminal</th>
                                    <th style={{ minWidth: 140 }}>Code</th>
                                    <th style={{ width: 110 }}>Type</th>
                                    <th style={{ width: 100 }}>Status</th>
                                    <th style={{ width: 180 }}>Last heartbeat</th>
                                    <th style={{ width: 130 }}>IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                {terminals.length === 0 ? (
                                    <tr>
                                        <td colSpan={6} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                            No terminals registered. Click <strong style={{ color: 'var(--cg-fg-2)' }}>+ Add terminal</strong>.
                                        </td>
                                    </tr>
                                ) : (
                                    terminals.map((t) => (
                                        <tr key={t.uuid}>
                                            <td>
                                                <div className="cgo-name">{t.name}</div>
                                            </td>
                                            <td>
                                                <span
                                                    style={{
                                                        display: 'inline-block', padding: '2px 8px',
                                                        border: '1px solid var(--cg-rule-strong)', borderRadius: 4,
                                                        background: 'var(--cg-ink-elevated)', color: 'var(--cg-brass-hi)',
                                                        fontFamily: 'var(--cg-mono)', fontSize: 11,
                                                        letterSpacing: '0.04em', fontWeight: 600,
                                                    }}
                                                >
                                                    {t.terminal_code}
                                                </span>
                                            </td>
                                            <td>
                                                <span style={{ fontSize: 12, color: 'var(--cg-fg-2)', textTransform: 'capitalize' }}>
                                                    {t.type}
                                                </span>
                                            </td>
                                            <td>
                                                <span className={`cgo-pill ${statusPill(t.status)}`}>{t.status}</span>
                                            </td>
                                            <td>
                                                <span className="cgo-uid">
                                                    {t.last_heartbeat_at ? formatDateTime(t.last_heartbeat_at) : 'Never'}
                                                </span>
                                            </td>
                                            <td>
                                                <span className="cgo-uid" style={{ fontFamily: 'var(--cg-mono)' }}>
                                                    {t.ip_address || '—'}
                                                </span>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>

            {/* Add staff dialog */}
            <Dialog
                header={`Add staff · ${venue.name}`}
                visible={addStaffOpen}
                onHide={() => setAddStaffOpen(false)}
                style={{ width: '500px' }}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button label="Cancel" severity="secondary" outlined onClick={() => setAddStaffOpen(false)} />
                        <Button
                            label={savingStaff ? 'Adding…' : 'Add staff'}
                            onClick={handleAddStaff}
                            disabled={savingStaff || !staffForm.username || !staffForm.password || !staffForm.display_name}
                            loading={savingStaff}
                        />
                    </div>
                }
            >
                <div className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <label style={{ fontSize: 12, fontWeight: 500 }}>Username *</label>
                            <InputText
                                value={staffForm.username}
                                onChange={(e) => setStaffForm({ ...staffForm, username: e.target.value })}
                                placeholder="johndoe"
                                className="w-full"
                            />
                        </div>
                        <div className="space-y-2">
                            <label style={{ fontSize: 12, fontWeight: 500 }}>Password *</label>
                            <InputText
                                type="password"
                                value={staffForm.password}
                                onChange={(e) => setStaffForm({ ...staffForm, password: e.target.value })}
                                placeholder="••••••••"
                                className="w-full"
                            />
                        </div>
                    </div>
                    <div className="space-y-2">
                        <label style={{ fontSize: 12, fontWeight: 500 }}>Display name *</label>
                        <InputText
                            value={staffForm.display_name}
                            onChange={(e) => setStaffForm({ ...staffForm, display_name: e.target.value })}
                            placeholder="John Doe"
                            className="w-full"
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <label style={{ fontSize: 12, fontWeight: 500 }}>Email</label>
                            <InputText
                                type="email"
                                value={staffForm.email}
                                onChange={(e) => setStaffForm({ ...staffForm, email: e.target.value })}
                                placeholder="john@example.com"
                                className="w-full"
                            />
                        </div>
                        <div className="space-y-2">
                            <label style={{ fontSize: 12, fontWeight: 500 }}>Phone</label>
                            <InputText
                                value={staffForm.phone}
                                onChange={(e) => setStaffForm({ ...staffForm, phone: e.target.value })}
                                placeholder="+264 61 …"
                                className="w-full"
                            />
                        </div>
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <label style={{ fontSize: 12, fontWeight: 500 }}>Role *</label>
                            <Dropdown
                                value={staffForm.role}
                                onChange={(e) => setStaffForm({ ...staffForm, role: e.value })}
                                options={ROLE_OPTIONS}
                                className="w-full"
                            />
                        </div>
                        <div className="space-y-2">
                            <label style={{ fontSize: 12, fontWeight: 500 }}>PIN (4 digits)</label>
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
            </Dialog>

            {/* Add terminal dialog */}
            <Dialog
                header={newTerminalApiKey ? 'Terminal created' : `Add terminal · ${venue.name}`}
                visible={addTerminalOpen}
                onHide={() => { setAddTerminalOpen(false); setNewTerminalApiKey(null); }}
                style={{ width: '500px' }}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button
                            label={newTerminalApiKey ? 'Close' : 'Cancel'}
                            severity="secondary"
                            outlined
                            onClick={() => { setAddTerminalOpen(false); setNewTerminalApiKey(null); }}
                        />
                        {!newTerminalApiKey && (
                            <Button
                                label={savingTerminal ? 'Adding…' : 'Add terminal'}
                                onClick={handleAddTerminal}
                                disabled={savingTerminal || !terminalForm.terminal_code || !terminalForm.name}
                                loading={savingTerminal}
                            />
                        )}
                    </div>
                }
            >
                {newTerminalApiKey ? (
                    <div className="space-y-4">
                        <p style={{ fontSize: 13 }}>Save the API key below — it will only be shown once.</p>
                        <div
                            style={{
                                background: 'var(--cg-ink-elevated)',
                                border: '1px solid var(--cg-rule)',
                                borderRadius: 6, padding: 14,
                            }}
                        >
                            <div className="cgo-uid" style={{ marginBottom: 4 }}>API key</div>
                            <code
                                style={{
                                    display: 'block', fontFamily: 'var(--cg-mono)', fontSize: 12,
                                    color: 'var(--cg-brass-hi)', wordBreak: 'break-all',
                                }}
                            >
                                {newTerminalApiKey}
                            </code>
                        </div>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={() => {
                                navigator.clipboard.writeText(newTerminalApiKey);
                                toast.current?.show({ severity: 'info', summary: 'Copied', detail: 'API key copied to clipboard.' });
                            }}
                            style={{ width: '100%', justifyContent: 'center' }}
                        >
                            Copy API key
                        </button>
                    </div>
                ) : (
                    <div className="space-y-4">
                        <p style={{ fontSize: 12, color: 'var(--cg-fg-3)' }}>
                            Register a new terminal for {venue.name}.
                        </p>
                        <div className="space-y-2">
                            <label style={{ fontSize: 12, fontWeight: 500 }}>Terminal code *</label>
                            <InputText
                                value={terminalForm.terminal_code}
                                onChange={(e) => setTerminalForm({ ...terminalForm, terminal_code: e.target.value })}
                                placeholder="TERM-001"
                                className="w-full"
                            />
                        </div>
                        <div className="space-y-2">
                            <label style={{ fontSize: 12, fontWeight: 500 }}>Name *</label>
                            <InputText
                                value={terminalForm.name}
                                onChange={(e) => setTerminalForm({ ...terminalForm, name: e.target.value })}
                                placeholder="Front desk terminal"
                                className="w-full"
                            />
                        </div>
                        <div className="space-y-2">
                            <label style={{ fontSize: 12, fontWeight: 500 }}>Type *</label>
                            <Dropdown
                                value={terminalForm.type}
                                onChange={(e) => setTerminalForm({ ...terminalForm, type: e.value })}
                                options={TERMINAL_TYPE_OPTIONS}
                                className="w-full"
                            />
                        </div>
                    </div>
                )}
            </Dialog>
        </UserLayout>
    );
}
