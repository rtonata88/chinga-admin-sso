import PageHeader from '@/components/acumatica/Common/PageHeader';
import StatusBadge from '@/components/acumatica/Common/StatusBadge';
import UserLayout from '@/layouts/user-layout';
import type { StatusVariant } from '@/types/acumatica';
import { Head, router } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Column } from 'primereact/column';
import { DataTable } from 'primereact/datatable';
import { Dialog } from 'primereact/dialog';
import { Dropdown } from 'primereact/dropdown';
import { InputText } from 'primereact/inputtext';
import { Toast } from 'primereact/toast';
import { useEffect, useRef, useState } from 'react';

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

function mapTenantStatusToVariant(status: string): StatusVariant {
    const map: Record<string, StatusVariant> = {
        active: 'active',
        suspended: 'suspended',
        inactive: 'inactive',
    };
    return map[status] || 'inactive';
}

const statusOptions = [
    { label: 'All Status', value: '' },
    { label: 'Active', value: 'active' },
    { label: 'Suspended', value: 'suspended' },
    { label: 'Inactive', value: 'inactive' },
];

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

    const getCsrfToken = () => {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    };

    const generateSlug = (name: string) => {
        return name
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/(^-|-$)/g, '');
    };

    const fetchTenants = (searchQuery = '', status = '') => {
        setLoading(true);
        const params = new URLSearchParams();
        if (searchQuery) params.set('search', searchQuery);
        if (status) params.set('status', status);

        fetch(`/api/v1/platform/tenants?${params}`)
            .then((res) => res.json())
            .then((res) => {
                setTenants(res.data);
                setLoading(false);
            });
    };

    useEffect(() => {
        fetchTenants();
    }, []);

    const handleSearch = () => {
        fetchTenants(search, statusFilter);
    };

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
                toast.current?.show({ severity: 'success', summary: 'Success', detail: 'Tenant created successfully.' });
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

    const tenantTemplate = (row: Tenant) => (
        <div>
            <div className="font-medium text-sm" style={{ color: 'var(--acu-text)' }}>{row.name}</div>
            <div className="text-xs" style={{ color: 'var(--acu-text-light)' }}>{row.slug}.sso.chingagames.com</div>
        </div>
    );

    const contactTemplate = (row: Tenant) => (
        <span className="text-sm" style={{ color: 'var(--acu-text)' }}>{row.contact_email}</span>
    );

    const statusTemplate = (row: Tenant) => (
        <StatusBadge status={mapTenantStatusToVariant(row.status)} label={row.status} />
    );

    const statsTemplate = (row: Tenant) => (
        <div className="flex gap-4">
            <span className="text-sm" style={{ color: 'var(--acu-text-muted)' }}>
                <i className="pi pi-users text-xs mr-1" />{row.users_count}
            </span>
            <span className="text-sm" style={{ color: 'var(--acu-text-muted)' }}>
                <i className="pi pi-map-marker text-xs mr-1" />{row.venues_count}
            </span>
        </div>
    );

    const countryTemplate = (row: Tenant) => (
        <span className="text-sm" style={{ color: 'var(--acu-text-muted)' }}>
            {row.country_code} / {row.currency}
        </span>
    );

    const actionsTemplate = (row: Tenant) => (
        <Button
            icon="pi pi-eye"
            text
            severity="secondary"
            size="small"
            tooltip="View tenant"
            onClick={() => router.visit(`/platform/tenants/${row.uuid}`)}
        />
    );

    const fieldError = (field: string) =>
        errors[field] ? (
            <small className="text-red-500">{errors[field][0]}</small>
        ) : null;

    return (
        <UserLayout title="Tenants">
            <Head title="Tenants" />
            <Toast ref={toast} />

            <div className="space-y-6">
                <PageHeader title="Tenants" subtitle="Manage operator tenants on the platform">
                    <Button
                        label="Refresh"
                        icon="pi pi-refresh"
                        outlined
                        size="small"
                        onClick={() => fetchTenants(search, statusFilter)}
                    />
                    <Button
                        label="New Tenant"
                        icon="pi pi-plus"
                        size="small"
                        onClick={() => setAddOpen(true)}
                    />
                </PageHeader>

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
                                    placeholder="Search by name, slug, email..."
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
                            onChange={(e) => { setStatusFilter(e.value); fetchTenants(search, e.value); }}
                            options={statusOptions}
                            placeholder="Status"
                            className="w-40"
                        />
                    </div>
                </div>

                {/* Tenants Table */}
                <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-gold)' } as React.CSSProperties}>
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-building" />
                            <span>Tenants</span>
                            <span className="text-xs font-normal ml-1" style={{ color: 'var(--acu-text-light)' }}>
                                ({tenants.length})
                            </span>
                        </div>
                    </div>
                    <div className="acu-fieldset-body p-0">
                        <DataTable
                            value={tenants}
                            loading={loading}
                            size="small"
                            showGridlines={false}
                            emptyMessage="No tenants found"
                        >
                            <Column header="Tenant" body={tenantTemplate} />
                            <Column header="Contact" body={contactTemplate} />
                            <Column header="Status" body={statusTemplate} />
                            <Column header="Players / Venues" body={statsTemplate} />
                            <Column header="Region" body={countryTemplate} />
                            <Column header="" body={actionsTemplate} style={{ width: '4rem' }} />
                        </DataTable>
                    </div>
                </div>
            </div>

            {/* Create Tenant Dialog */}
            <Dialog
                header="Create New Tenant"
                visible={addOpen}
                style={{ width: '32rem' }}
                onHide={() => setAddOpen(false)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button
                            label="Cancel"
                            icon="pi pi-times"
                            severity="secondary"
                            outlined
                            onClick={() => setAddOpen(false)}
                        />
                        <Button
                            label={saving ? 'Creating...' : 'Create Tenant'}
                            icon="pi pi-check"
                            onClick={handleCreateTenant}
                            disabled={saving || !formData.name || !formData.contact_email}
                            loading={saving}
                        />
                    </div>
                }
            >
                <div className="space-y-4">
                    <div>
                        <label htmlFor="name" className="block text-sm font-medium mb-1" style={{ color: 'var(--acu-text)' }}>Tenant Name *</label>
                        <InputText
                            id="name"
                            value={formData.name}
                            onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                            placeholder="e.g., Lucky Star Gaming"
                            className={`w-full ${errors.name ? 'p-invalid' : ''}`}
                        />
                        {fieldError('name')}
                        {formData.name && (
                            <small style={{ color: 'var(--acu-text-light)' }}>
                                Subdomain: {generateSlug(formData.name)}.sso.chingagames.com
                            </small>
                        )}
                    </div>

                    <div>
                        <label htmlFor="contact_email" className="block text-sm font-medium mb-1" style={{ color: 'var(--acu-text)' }}>Contact Email *</label>
                        <InputText
                            id="contact_email"
                            type="email"
                            value={formData.contact_email}
                            onChange={(e) => setFormData({ ...formData, contact_email: e.target.value })}
                            placeholder="e.g., admin@luckystar.com"
                            className={`w-full ${errors.contact_email ? 'p-invalid' : ''}`}
                        />
                        {fieldError('contact_email')}
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label htmlFor="country_code" className="block text-sm font-medium mb-1" style={{ color: 'var(--acu-text)' }}>Country Code</label>
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
                            <label htmlFor="currency" className="block text-sm font-medium mb-1" style={{ color: 'var(--acu-text)' }}>Currency</label>
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
                        <label htmlFor="timezone" className="block text-sm font-medium mb-1" style={{ color: 'var(--acu-text)' }}>Timezone</label>
                        <InputText
                            id="timezone"
                            value={formData.timezone}
                            onChange={(e) => setFormData({ ...formData, timezone: e.target.value })}
                            className={`w-full ${errors.timezone ? 'p-invalid' : ''}`}
                        />
                        {fieldError('timezone')}
                    </div>

                    <div
                        className="rounded-lg p-3 mt-2"
                        style={{
                            background: 'rgba(59, 130, 246, 0.04)',
                            border: '1px solid rgba(59, 130, 246, 0.15)',
                        }}
                    >
                        <div className="flex items-center gap-2 mb-3">
                            <i className="pi pi-user-plus text-xs" style={{ color: '#3B82F6' }} />
                            <span className="text-sm font-medium" style={{ color: 'var(--acu-text)' }}>Initial Admin (optional)</span>
                        </div>

                        <div className="space-y-3">
                            <div>
                                <label htmlFor="admin_name" className="block text-sm font-medium mb-1" style={{ color: 'var(--acu-text)' }}>Admin Name</label>
                                <InputText
                                    id="admin_name"
                                    value={formData.admin_name}
                                    onChange={(e) => setFormData({ ...formData, admin_name: e.target.value })}
                                    placeholder="e.g., John Doe"
                                    className={`w-full ${errors.admin_name ? 'p-invalid' : ''}`}
                                />
                                {fieldError('admin_name')}
                            </div>

                            <div>
                                <label htmlFor="admin_email" className="block text-sm font-medium mb-1" style={{ color: 'var(--acu-text)' }}>Admin Email</label>
                                <InputText
                                    id="admin_email"
                                    type="email"
                                    value={formData.admin_email}
                                    onChange={(e) => setFormData({ ...formData, admin_email: e.target.value })}
                                    placeholder="e.g., john@luckystar.com"
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
