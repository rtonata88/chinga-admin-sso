import UserLayout from '@/layouts/user-layout';
import { Head, router } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Column } from 'primereact/column';
import { DataTable } from 'primereact/datatable';
import { Dialog } from 'primereact/dialog';
import { InputText } from 'primereact/inputtext';
import { useEffect, useState } from 'react';

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

export default function TenantsIndex() {
    const [tenants, setTenants] = useState<Tenant[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');

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

    const fetchTenants = (searchQuery = '') => {
        setLoading(true);
        const params = new URLSearchParams();
        if (searchQuery) params.set('search', searchQuery);

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
                fetchTenants();
            } else if (response.status === 422 && data.errors) {
                setErrors(data.errors);
            } else {
                alert(data.message || 'Failed to create tenant');
            }
        } catch (error) {
            console.error('Failed to create tenant:', error);
            alert('Failed to create tenant');
        } finally {
            setSaving(false);
        }
    };

    const statusTemplate = (row: Tenant) => (
        <span
            className={`text-xs px-2 py-1 rounded ${
                row.status === 'active'
                    ? 'bg-green-100 text-green-800'
                    : row.status === 'suspended'
                      ? 'bg-yellow-100 text-yellow-800'
                      : 'bg-red-100 text-red-800'
            }`}
        >
            {row.status}
        </span>
    );

    const actionsTemplate = (row: Tenant) => (
        <Button
            icon="pi pi-eye"
            className="p-button-text p-button-sm"
            onClick={() => router.visit(`/platform/tenants/${row.uuid}`)}
        />
    );

    const dialogFooter = (
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
    );

    const fieldError = (field: string) =>
        errors[field] ? (
            <small className="text-red-600">{errors[field][0]}</small>
        ) : null;

    return (
        <UserLayout title="Tenants">
            <Head title="Tenants" />

            <div className="space-y-4">
                <div className="flex justify-between items-center">
                    <h1 className="text-2xl font-bold">Tenants</h1>
                    <Button label="New Tenant" icon="pi pi-plus" onClick={() => setAddOpen(true)} />
                </div>

                <div className="flex gap-2">
                    <span className="p-input-icon-left">
                        <i className="pi pi-search" />
                        <InputText
                            placeholder="Search tenants..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && fetchTenants(search)}
                        />
                    </span>
                </div>

                <DataTable value={tenants} loading={loading} paginator rows={25} stripedRows>
                    <Column field="name" header="Name" sortable />
                    <Column field="slug" header="Slug" sortable />
                    <Column field="contact_email" header="Email" />
                    <Column field="status" header="Status" body={statusTemplate} sortable />
                    <Column field="users_count" header="Players" sortable />
                    <Column field="venues_count" header="Venues" sortable />
                    <Column field="country_code" header="Country" />
                    <Column body={actionsTemplate} style={{ width: '4rem' }} />
                </DataTable>
            </div>

            <Dialog
                header="Create New Tenant"
                visible={addOpen}
                style={{ width: '32rem' }}
                onHide={() => setAddOpen(false)}
                footer={dialogFooter}
                modal
                draggable={false}
            >
                <div className="space-y-4">
                    <div className="flex flex-col gap-1">
                        <label htmlFor="name" className="text-sm font-medium">Tenant Name *</label>
                        <InputText
                            id="name"
                            value={formData.name}
                            onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                            placeholder="e.g., Lucky Star Gaming"
                            className={`w-full ${errors.name ? 'p-invalid' : ''}`}
                        />
                        {fieldError('name')}
                        {formData.name && (
                            <small className="text-gray-500">
                                Subdomain: {generateSlug(formData.name)}.sso.chingagames.com
                            </small>
                        )}
                    </div>

                    <div className="flex flex-col gap-1">
                        <label htmlFor="contact_email" className="text-sm font-medium">Contact Email *</label>
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
                        <div className="flex flex-col gap-1">
                            <label htmlFor="country_code" className="text-sm font-medium">Country Code</label>
                            <InputText
                                id="country_code"
                                value={formData.country_code}
                                onChange={(e) => setFormData({ ...formData, country_code: e.target.value })}
                                maxLength={2}
                                className={`w-full ${errors.country_code ? 'p-invalid' : ''}`}
                            />
                            {fieldError('country_code')}
                        </div>
                        <div className="flex flex-col gap-1">
                            <label htmlFor="currency" className="text-sm font-medium">Currency</label>
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

                    <div className="flex flex-col gap-1">
                        <label htmlFor="timezone" className="text-sm font-medium">Timezone</label>
                        <InputText
                            id="timezone"
                            value={formData.timezone}
                            onChange={(e) => setFormData({ ...formData, timezone: e.target.value })}
                            className={`w-full ${errors.timezone ? 'p-invalid' : ''}`}
                        />
                        {fieldError('timezone')}
                    </div>

                    <hr />
                    <p className="text-sm text-gray-500">Optionally create an initial admin user for this tenant:</p>

                    <div className="flex flex-col gap-1">
                        <label htmlFor="admin_name" className="text-sm font-medium">Admin Name</label>
                        <InputText
                            id="admin_name"
                            value={formData.admin_name}
                            onChange={(e) => setFormData({ ...formData, admin_name: e.target.value })}
                            placeholder="e.g., John Doe"
                            className={`w-full ${errors.admin_name ? 'p-invalid' : ''}`}
                        />
                        {fieldError('admin_name')}
                    </div>

                    <div className="flex flex-col gap-1">
                        <label htmlFor="admin_email" className="text-sm font-medium">Admin Email</label>
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
            </Dialog>
        </UserLayout>
    );
}
