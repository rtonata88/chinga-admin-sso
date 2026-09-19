// resources/js/pages/admin/venues/index.tsx
//
// Venues list for tenant admins. Reuses /api/v1/admin/venues which
// auto-scopes via Venue's BelongsToTenant global scope, so a tenant
// admin only sees their own tenant's venues. Platform admins (no
// tenant context) see every venue.

import { KpiCard, formatCount } from '@/components/operator/kpi-card';
import UserLayout from '@/layouts/user-layout';
import { Head, router } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { InputText } from 'primereact/inputtext';
import { Toast } from 'primereact/toast';
import { useEffect, useMemo, useRef, useState } from 'react';

interface Venue {
    uuid: string;
    name: string;
    slug: string;
    city: string;
    country_code: string;
    status: string;
    staff_count: number;
    terminals_count: number;
    voucher_codes_count: number;
    created_at: string;
}

interface Meta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

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
        case 'inactive':
        case 'closed': return 'void';
        default: return 'void';
    }
}

const generateSlug = (name: string) =>
    name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');

export default function VenuesIndex() {
    const toast = useRef<Toast>(null);
    const [venues, setVenues] = useState<Venue[]>([]);
    const [meta, setMeta] = useState<Meta | null>(null);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [page, setPage] = useState(1);

    const [addOpen, setAddOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    const [formData, setFormData] = useState({
        name: '',
        slug: '',
        address_line_1: '',
        city: '',
        country_code: 'NA',
        phone: '',
        email: '',
    });

    const getCsrfToken = () =>
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const fetchVenues = async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams();
            if (search) params.append('search', search);
            if (statusFilter) params.append('status', statusFilter);
            params.append('page', page.toString());

            const response = await fetch(`/api/v1/admin/venues?${params}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (data.success) {
                setVenues(data.data);
                setMeta(data.meta);
            }
        } catch (error) {
            console.error('Failed to fetch venues:', error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchVenues();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [page, statusFilter]);

    const submitSearch = () => { setPage(1); fetchVenues(); };

    const stats = useMemo(() => {
        let active = 0;
        let staff = 0;
        let terminals = 0;
        for (const v of venues) {
            if (v.status === 'active') active++;
            staff += v.staff_count || 0;
            terminals += v.terminals_count || 0;
        }
        return { total: meta?.total ?? venues.length, active, staff, terminals };
    }, [venues, meta]);

    const handleAddVenue = async () => {
        setSaving(true);
        try {
            const response = await fetch('/api/v1/admin/venues', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify(formData),
            });
            const data = await response.json();
            if (data.success) {
                setAddOpen(false);
                setFormData({ name: '', slug: '', address_line_1: '', city: '', country_code: 'NA', phone: '', email: '' });
                fetchVenues();
                toast.current?.show({ severity: 'success', summary: 'Created', detail: 'Venue created.' });
            } else {
                toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || 'Failed to create venue.' });
            }
        } catch (error) {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to create venue.' });
        } finally {
            setSaving(false);
        }
    };

    return (
        <UserLayout title="Venues">
            <Head title="Venues · Admin" />
            <Toast ref={toast} />

            <div className="cgo-page">
                {/* Page header */}
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Admin</div>
                        <h1 className="cgo-title">Venues</h1>
                        <div className="cgo-subtitle">
                            Branded points-of-sale for your tenant — staff, terminals, voucher issuance.
                        </div>
                    </div>
                    <div style={{ display: 'flex', gap: 8 }}>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={fetchVenues}
                        >
                            Refresh
                        </button>
                        <button
                            type="button"
                            className="cg-btn cg-btn--primary cg-btn--sm"
                            onClick={() => setAddOpen(true)}
                        >
                            + New venue
                        </button>
                    </div>
                </div>

                {/* KPI strip */}
                <div className="cgo-kpis">
                    <KpiCard
                        label="Total venues"
                        value={loading ? '—' : formatCount(stats.total)}
                        brass
                        meta="under your tenant"
                    />
                    <KpiCard
                        label="Active"
                        value={loading ? '—' : formatCount(stats.active)}
                        meta="trading"
                    />
                    <KpiCard
                        label="Staff"
                        value={loading ? '—' : formatCount(stats.staff)}
                        meta="across visible venues"
                    />
                    <KpiCard
                        label="Terminals"
                        value={loading ? '—' : formatCount(stats.terminals)}
                        meta="registered devices"
                    />
                </div>

                {/* Filter bar */}
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
                    <div className="cgo-right">
                        <label className="cgo-input">
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && submitSearch()}
                                placeholder="Search name, slug, city…"
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

                {/* Venues table */}
                <div
                    className="cgo-table-wrap cgo-table-wrap--scroll"
                    style={{ borderRadius: '0 0 8px 8px', borderTop: 0 }}
                >
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ minWidth: 220 }}>Venue</th>
                                <th style={{ minWidth: 160 }}>Location</th>
                                <th style={{ width: 110 }}>Status</th>
                                <th className="cgo-r" style={{ width: 90 }}>Staff</th>
                                <th className="cgo-r" style={{ width: 110 }}>Terminals</th>
                                <th className="cgo-r" style={{ width: 110 }}>Vouchers</th>
                                <th style={{ width: 70 }} />
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan={7} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        Loading…
                                    </td>
                                </tr>
                            ) : venues.length === 0 ? (
                                <tr>
                                    <td colSpan={7} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        No venues match the current filters. Click <strong style={{ color: 'var(--cg-fg-2)' }}>+ New venue</strong> to create one.
                                    </td>
                                </tr>
                            ) : (
                                venues.map((v) => (
                                    <tr
                                        key={v.uuid}
                                        onClick={() => router.visit(`/admin/venues/${v.uuid}`)}
                                        style={{ cursor: 'pointer' }}
                                    >
                                        <td>
                                            <div className="cgo-name">{v.name}</div>
                                            <div className="cgo-uid">{v.slug}</div>
                                        </td>
                                        <td>
                                            <span style={{ fontSize: 12, color: 'var(--cg-fg-2)' }}>
                                                {v.city}
                                                {v.country_code && (
                                                    <span style={{ color: 'var(--cg-fg-3)' }}> · {v.country_code}</span>
                                                )}
                                            </span>
                                        </td>
                                        <td>
                                            <span className={`cgo-pill ${statusPill(v.status)}`}>
                                                {v.status}
                                            </span>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-odds">{formatCount(v.staff_count)}</span>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-odds">{formatCount(v.terminals_count)}</span>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-odds">{formatCount(v.voucher_codes_count)}</span>
                                        </td>
                                        <td>
                                            <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
                                                <span style={{ color: 'var(--cg-fg-3)' }}>›</span>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>

                    {/* Footer + pager */}
                    {meta && (
                        <div className="cgo-table-foot">
                            <span>
                                {meta.total > 0 ? (
                                    <>
                                        Showing <b className="cgo-mono">{venues.length}</b> of{' '}
                                        <b className="cgo-mono">{meta.total.toLocaleString()}</b> venues
                                    </>
                                ) : 'No venues'}
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

            {/* Add venue dialog */}
            <Dialog
                header="New venue"
                visible={addOpen}
                style={{ width: '28rem' }}
                onHide={() => setAddOpen(false)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button label="Cancel" severity="secondary" outlined onClick={() => setAddOpen(false)} />
                        <Button
                            label={saving ? 'Creating…' : 'Create venue'}
                            onClick={handleAddVenue}
                            disabled={
                                saving || !formData.name || !formData.slug ||
                                !formData.address_line_1 || !formData.city || !formData.country_code
                            }
                            loading={saving}
                        />
                    </div>
                }
            >
                <div className="space-y-4">
                    <div className="flex flex-col gap-1">
                        <label style={{ fontSize: 12, fontWeight: 500 }}>Venue name *</label>
                        <InputText
                            value={formData.name}
                            onChange={(e) => {
                                const name = e.target.value;
                                setFormData({ ...formData, name, slug: formData.slug || generateSlug(name) });
                            }}
                            placeholder="e.g. Casino Windhoek"
                            className="w-full"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label style={{ fontSize: 12, fontWeight: 500 }}>Slug *</label>
                        <InputText
                            value={formData.slug}
                            onChange={(e) => setFormData({ ...formData, slug: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '') })}
                            placeholder="e.g. casino-windhoek"
                            className="w-full"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label style={{ fontSize: 12, fontWeight: 500 }}>Address *</label>
                        <InputText
                            value={formData.address_line_1}
                            onChange={(e) => setFormData({ ...formData, address_line_1: e.target.value })}
                            placeholder="Street address"
                            className="w-full"
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="flex flex-col gap-1">
                            <label style={{ fontSize: 12, fontWeight: 500 }}>City *</label>
                            <InputText
                                value={formData.city}
                                onChange={(e) => setFormData({ ...formData, city: e.target.value })}
                                placeholder="Windhoek"
                                className="w-full"
                            />
                        </div>
                        <div className="flex flex-col gap-1">
                            <label style={{ fontSize: 12, fontWeight: 500 }}>Country *</label>
                            <InputText
                                value={formData.country_code}
                                onChange={(e) => setFormData({ ...formData, country_code: e.target.value.toUpperCase().slice(0, 2) })}
                                placeholder="NA"
                                maxLength={2}
                                className="w-full"
                            />
                        </div>
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="flex flex-col gap-1">
                            <label style={{ fontSize: 12, fontWeight: 500 }}>Phone</label>
                            <InputText
                                value={formData.phone}
                                onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                                placeholder="+264 61 …"
                                className="w-full"
                            />
                        </div>
                        <div className="flex flex-col gap-1">
                            <label style={{ fontSize: 12, fontWeight: 500 }}>Email</label>
                            <InputText
                                type="email"
                                value={formData.email}
                                onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                                placeholder="venue@example.com"
                                className="w-full"
                            />
                        </div>
                    </div>
                </div>
            </Dialog>
        </UserLayout>
    );
}
