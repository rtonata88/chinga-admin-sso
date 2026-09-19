// resources/js/pages/platform/tenants/index.tsx
//
// Platform tenants list, brass-on-ink to match /tenant-overview.
// KPI strip → status chips + search → table. Create-tenant dialog
// stays on PrimeReact (multi-field form with server validation).

import { KpiCard, formatCount } from '@/components/operator/kpi-card';
import UserLayout from '@/layouts/user-layout';
import { Head, router } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { InputText } from 'primereact/inputtext';
import { Toast } from 'primereact/toast';
import { useEffect, useMemo, useRef, useState } from 'react';

interface Tenant {
    uuid: string;
    name: string;
    slug: string;
    contact_email: string;
    status: string;
    country_code: string;
    currency: string;
    users_count: number;
    venues_count: number;
    created_at: string;
}

const initialFormData = {
    name: '',
    contact_email: '',
    country_code: 'NA',
    currency: 'NAD',
    timezone: 'Africa/Windhoek',
    admin_email: '',
    admin_name: '',
};

const STATUS_FILTERS = [
    { label: 'All', value: '' },
    { label: 'Active', value: 'active' },
    { label: 'Suspended', value: 'suspended' },
    { label: 'Inactive', value: 'inactive' },
];

function statusPill(status: string): string {
    switch (status) {
        case 'active': return 'live';
        case 'suspended': return 'flagged';
        case 'inactive': return 'void';
        default: return 'void';
    }
}

const generateSlug = (name: string) =>
    name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');

export default function TenantsIndex() {
    const toast = useRef<Toast>(null);
    const [tenants, setTenants] = useState<Tenant[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');

    const [addOpen, setAddOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    const [formData, setFormData] = useState(initialFormData);
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    const getCsrfToken = () =>
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const fetchTenants = (searchQuery = '', status = '') => {
        setLoading(true);
        const params = new URLSearchParams();
        if (searchQuery) params.set('search', searchQuery);
        if (status) params.set('status', status);

        fetch(`/api/v1/platform/tenants?${params}`)
            .then((res) => res.json())
            .then((res) => {
                setTenants(res.data || []);
                setLoading(false);
            })
            .catch(() => setLoading(false));
    };

    useEffect(() => {
        fetchTenants();
    }, []);

    const submitSearch = () => fetchTenants(search, statusFilter);

    // Aggregate counts for the KPI strip — derived from the loaded
    // tenants array. If filters are applied these reflect the filter
    // (which matches the user's intent: see what they're looking at).
    const kpis = useMemo(() => {
        const total = tenants.length;
        const active = tenants.filter((t) => t.status === 'active').length;
        const suspended = tenants.filter((t) => t.status === 'suspended').length;
        const players = tenants.reduce((s, t) => s + (t.users_count || 0), 0);
        const venues = tenants.reduce((s, t) => s + (t.venues_count || 0), 0);
        return { total, active, suspended, players, venues };
    }, [tenants]);

    const handleCreateTenant = async () => {
        setSaving(true);
        setErrors({});
        try {
            const response = await fetch('/api/v1/platform/tenants', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify(formData),
            });
            const data = await response.json();
            if (response.ok) {
                setAddOpen(false);
                setFormData(initialFormData);
                fetchTenants(search, statusFilter);
                toast.current?.show({ severity: 'success', summary: 'Created', detail: 'Tenant created successfully.' });
            } else if (response.status === 422 && data.errors) {
                setErrors(data.errors);
            } else {
                toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || 'Failed to create tenant.' });
            }
        } catch (error) {
            console.error('Failed to create tenant:', error);
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to create tenant.' });
        } finally {
            setSaving(false);
        }
    };

    const fieldError = (field: string) =>
        errors[field] ? (
            <small style={{ color: 'var(--cg-neg)' }}>{errors[field][0]}</small>
        ) : null;

    return (
        <UserLayout title="Tenants">
            <Head title="Tenants · Platform" />
            <Toast ref={toast} />

            <div className="cgo-page">
                {/* Page header */}
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Platform</div>
                        <h1 className="cgo-title">Tenants</h1>
                        <div className="cgo-subtitle">
                            Operator tenants on the platform — brands, venues, and player counts.
                        </div>
                    </div>
                    <div style={{ display: 'flex', gap: 8 }}>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={() => fetchTenants(search, statusFilter)}
                        >
                            Refresh
                        </button>
                        <button
                            type="button"
                            className="cg-btn cg-btn--primary cg-btn--sm"
                            onClick={() => setAddOpen(true)}
                        >
                            + New tenant
                        </button>
                    </div>
                </div>

                {/* KPI strip — 5-up. Counts derive from the visible list. */}
                <div className="cgo-kpis cgo-kpis--5">
                    <KpiCard
                        label="Tenants"
                        value={loading ? '—' : formatCount(kpis.total)}
                        brass
                        meta={statusFilter || search ? 'matching filters' : 'on platform'}
                    />
                    <KpiCard
                        label="Active"
                        value={loading ? '—' : formatCount(kpis.active)}
                        meta="signed-in operators"
                    />
                    <KpiCard
                        label="Suspended"
                        value={loading ? '—' : formatCount(kpis.suspended)}
                        meta={kpis.suspended > 0 ? 'review' : 'all clear'}
                    />
                    <KpiCard
                        label="Total players"
                        value={loading ? '—' : formatCount(kpis.players)}
                        meta="across tenants"
                    />
                    <KpiCard
                        label="Total venues"
                        value={loading ? '—' : formatCount(kpis.venues)}
                        meta="branded points-of-sale"
                    />
                </div>

                {/* Filter bar */}
                <div className="cgo-filterbar">
                    {STATUS_FILTERS.map((f) => (
                        <button
                            key={f.value || 'all'}
                            type="button"
                            className={`cgo-chip${statusFilter === f.value ? ' active' : ''}`}
                            onClick={() => { setStatusFilter(f.value); fetchTenants(search, f.value); }}
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
                                placeholder="Search name, slug, email…"
                                style={{ minWidth: 240 }}
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

                {/* Tenants table */}
                <div
                    className="cgo-table-wrap cgo-table-wrap--scroll"
                    style={{ borderRadius: '0 0 8px 8px', borderTop: 0 }}
                >
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ minWidth: 240 }}>Tenant</th>
                                <th style={{ minWidth: 200 }}>Contact</th>
                                <th style={{ width: 110 }}>Status</th>
                                <th className="cgo-r" style={{ width: 100 }}>Players</th>
                                <th className="cgo-r" style={{ width: 100 }}>Venues</th>
                                <th style={{ width: 120 }}>Region</th>
                                <th style={{ width: 80 }} />
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan={7} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        Loading…
                                    </td>
                                </tr>
                            ) : tenants.length === 0 ? (
                                <tr>
                                    <td colSpan={7} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        No tenants match the current filters.
                                    </td>
                                </tr>
                            ) : (
                                tenants.map((t) => (
                                    <tr key={t.uuid}>
                                        <td style={{ maxWidth: 320 }}>
                                            <div className="cgo-name cgo-cell-clip" title={t.name}>
                                                {t.name}
                                            </div>
                                            <div className="cgo-uid">{t.slug}.sso.chingagames.com</div>
                                        </td>
                                        <td>
                                            <span style={{ fontSize: 12, color: 'var(--cg-fg-2)' }}>
                                                {t.contact_email}
                                            </span>
                                        </td>
                                        <td>
                                            <span className={`cgo-pill ${statusPill(t.status)}`}>
                                                {t.status}
                                            </span>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-odds">{formatCount(t.users_count)}</span>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-odds">{formatCount(t.venues_count)}</span>
                                        </td>
                                        <td>
                                            <span className="cgo-uid">
                                                {t.country_code} · {t.currency}
                                            </span>
                                        </td>
                                        <td>
                                            <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
                                                <button
                                                    type="button"
                                                    className="cgo-row-action"
                                                    aria-label="View tenant"
                                                    title="View tenant"
                                                    onClick={() => router.visit(`/platform/tenants/${t.uuid}`)}
                                                >
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z" /><circle cx="12" cy="12" r="3" /></svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Create tenant dialog */}
            <Dialog
                header="Create new tenant"
                visible={addOpen}
                style={{ width: '32rem' }}
                onHide={() => setAddOpen(false)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button label="Cancel" severity="secondary" outlined onClick={() => setAddOpen(false)} />
                        <Button
                            label={saving ? 'Creating…' : 'Create tenant'}
                            onClick={handleCreateTenant}
                            disabled={saving || !formData.name || !formData.contact_email}
                            loading={saving}
                        />
                    </div>
                }
            >
                <div className="space-y-4">
                    <div>
                        <label htmlFor="name" style={{ fontSize: 12, fontWeight: 500, display: 'block', marginBottom: 4 }}>
                            Tenant name *
                        </label>
                        <InputText
                            id="name"
                            value={formData.name}
                            onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                            placeholder="e.g., Lucky Star Gaming"
                            className={`w-full ${errors.name ? 'p-invalid' : ''}`}
                        />
                        {fieldError('name')}
                        {formData.name && (
                            <small style={{ color: 'var(--cg-fg-3)' }}>
                                Subdomain:{' '}
                                <code style={{ fontFamily: 'var(--cg-mono)', color: 'var(--cg-brass-hi)' }}>
                                    {generateSlug(formData.name)}.sso.chingagames.com
                                </code>
                            </small>
                        )}
                    </div>

                    <div>
                        <label htmlFor="contact_email" style={{ fontSize: 12, fontWeight: 500, display: 'block', marginBottom: 4 }}>
                            Contact email *
                        </label>
                        <InputText
                            id="contact_email"
                            type="email"
                            value={formData.contact_email}
                            onChange={(e) => setFormData({ ...formData, contact_email: e.target.value })}
                            placeholder="admin@luckystar.com"
                            className={`w-full ${errors.contact_email ? 'p-invalid' : ''}`}
                        />
                        {fieldError('contact_email')}
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label htmlFor="country_code" style={{ fontSize: 12, fontWeight: 500, display: 'block', marginBottom: 4 }}>
                                Country
                            </label>
                            <InputText
                                id="country_code"
                                value={formData.country_code}
                                onChange={(e) => setFormData({ ...formData, country_code: e.target.value })}
                                maxLength={2}
                                className={`w-full ${errors.country_code ? 'p-invalid' : ''}`}
                            />
                            {fieldError('country_code')}
                        </div>
                        <div>
                            <label htmlFor="currency" style={{ fontSize: 12, fontWeight: 500, display: 'block', marginBottom: 4 }}>
                                Currency
                            </label>
                            <InputText
                                id="currency"
                                value={formData.currency}
                                onChange={(e) => setFormData({ ...formData, currency: e.target.value })}
                                maxLength={3}
                                className={`w-full ${errors.currency ? 'p-invalid' : ''}`}
                            />
                            {fieldError('currency')}
                        </div>
                    </div>

                    <div>
                        <label htmlFor="timezone" style={{ fontSize: 12, fontWeight: 500, display: 'block', marginBottom: 4 }}>
                            Timezone
                        </label>
                        <InputText
                            id="timezone"
                            value={formData.timezone}
                            onChange={(e) => setFormData({ ...formData, timezone: e.target.value })}
                            className={`w-full ${errors.timezone ? 'p-invalid' : ''}`}
                        />
                        {fieldError('timezone')}
                    </div>

                    <div
                        style={{
                            background: 'var(--cg-ink-elevated)',
                            border: '1px solid var(--cg-rule)',
                            borderRadius: 6,
                            padding: 12,
                        }}
                    >
                        <div style={{ fontSize: 12, fontWeight: 500, marginBottom: 8, color: 'var(--cg-fg-2)' }}>
                            Initial admin (optional)
                        </div>
                        <div className="space-y-3">
                            <div>
                                <label htmlFor="admin_name" style={{ fontSize: 12, fontWeight: 500, display: 'block', marginBottom: 4 }}>
                                    Name
                                </label>
                                <InputText
                                    id="admin_name"
                                    value={formData.admin_name}
                                    onChange={(e) => setFormData({ ...formData, admin_name: e.target.value })}
                                    placeholder="John Doe"
                                    className={`w-full ${errors.admin_name ? 'p-invalid' : ''}`}
                                />
                                {fieldError('admin_name')}
                            </div>
                            <div>
                                <label htmlFor="admin_email" style={{ fontSize: 12, fontWeight: 500, display: 'block', marginBottom: 4 }}>
                                    Email
                                </label>
                                <InputText
                                    id="admin_email"
                                    type="email"
                                    value={formData.admin_email}
                                    onChange={(e) => setFormData({ ...formData, admin_email: e.target.value })}
                                    placeholder="john@luckystar.com"
                                    className={`w-full ${errors.admin_email ? 'p-invalid' : ''}`}
                                />
                                {fieldError('admin_email')}
                            </div>
                        </div>
                    </div>
                </div>
            </Dialog>
        </UserLayout>
    );
}
