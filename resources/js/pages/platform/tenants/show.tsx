import StatusBadge from '@/components/acumatica/Common/StatusBadge';
import UserLayout from '@/layouts/user-layout';
import type { StatusVariant } from '@/types/acumatica';
import { Head, Link, usePage } from '@inertiajs/react';
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
    legal_name: string;
    registration_number: string;
    license_number: string;
    contact_email: string;
    contact_phone: string;
    logo_url: string;
    domain: string;
    country_code: string;
    currency: string;
    timezone: string;
    status: string;
    revenue_share_pct: number;
    users_count: number;
    venues_count: number;
    voucher_codes_count: number;
    enabled_games: Array<{ uuid: string; name: string; type: string }>;
    created_at: string;
}

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
}

function mapVenueStatus(status: string): StatusVariant {
    switch (status) {
        case 'active':
            return 'active';
        case 'suspended':
            return 'suspended';
        default:
            return 'inactive';
    }
}

export default function TenantShow() {
    const [tenant, setTenant] = useState<Tenant | null>(null);
    const [loading, setLoading] = useState(true);
    const [venues, setVenues] = useState<Venue[]>([]);
    const [venuesLoading, setVenuesLoading] = useState(true);

    // Add venue dialog
    const [addVenueOpen, setAddVenueOpen] = useState(false);
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

    const { uuid } = usePage<{ uuid: string }>().props;

    const fetchVenues = async () => {
        setVenuesLoading(true);
        try {
            const response = await fetch(`/api/v1/platform/tenants/${uuid}/venues`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (data.success) {
                setVenues(data.data);
            }
        } catch (error) {
            console.error('Failed to fetch venues:', error);
        } finally {
            setVenuesLoading(false);
        }
    };

    useEffect(() => {
        fetch(`/api/v1/platform/tenants/${uuid}`)
            .then((res) => res.json())
            .then((res) => {
                setTenant(res.data);
                setLoading(false);
            });
        fetchVenues();
    }, [uuid]);

    const getCsrfToken = () => {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    };

    const generateSlug = (name: string) => {
        return name
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/(^-|-$)/g, '');
    };

    const handleAddVenue = async () => {
        setSaving(true);
        try {
            const response = await fetch(`/api/v1/platform/tenants/${uuid}/venues`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify(formData),
            });
            const data = await response.json();
            if (data.success) {
                setAddVenueOpen(false);
                setFormData({
                    name: '',
                    slug: '',
                    address_line_1: '',
                    city: '',
                    country_code: 'NA',
                    phone: '',
                    email: '',
                });
                fetchVenues();
            } else {
                alert(data.message || 'Failed to create venue');
            }
        } catch (error) {
            console.error('Failed to create venue:', error);
            alert('Failed to create venue');
        } finally {
            setSaving(false);
        }
    };

    if (loading || !tenant) {
        return (
            <UserLayout title="Tenant Details">
                <Head title="Tenant Details" />
                <div className="text-center py-8">Loading...</div>
            </UserLayout>
        );
    }

    const venueNameTemplate = (rowData: Venue) => (
        <div>
            <div className="font-medium text-sm text-[var(--acu-text)]">{rowData.name}</div>
            <div className="text-xs text-[var(--acu-text-light)]">{rowData.slug}</div>
        </div>
    );

    const venueLocationTemplate = (rowData: Venue) => (
        <span className="text-sm text-[var(--acu-text)]">
            {rowData.city}, {rowData.country_code}
        </span>
    );

    const venueStatusTemplate = (rowData: Venue) => (
        <StatusBadge status={mapVenueStatus(rowData.status)} label={rowData.status} />
    );

    const venueActionsTemplate = (rowData: Venue) => (
        <Link href={`/platform/tenants/${uuid}/venues/${rowData.uuid}`}>
            <Button
                icon="pi pi-eye"
                severity="secondary"
                text
                size="small"
                tooltip="View venue"
            />
        </Link>
    );

    const addVenueDialogFooter = (
        <div className="flex justify-end gap-2">
            <Button
                label="Cancel"
                icon="pi pi-times"
                severity="secondary"
                outlined
                onClick={() => setAddVenueOpen(false)}
            />
            <Button
                label={saving ? 'Creating...' : 'Create Venue'}
                icon="pi pi-check"
                onClick={handleAddVenue}
                disabled={
                    saving ||
                    !formData.name ||
                    !formData.slug ||
                    !formData.address_line_1 ||
                    !formData.city ||
                    !formData.country_code
                }
                loading={saving}
            />
        </div>
    );

    return (
        <UserLayout title={tenant.name}>
            <Head title={tenant.name} />

            <div className="space-y-6">
                <div className="flex justify-between items-center">
                    <h1 className="text-2xl font-bold">{tenant.name}</h1>
                    <span
                        className={`text-sm px-3 py-1 rounded ${
                            tenant.status === 'active'
                                ? 'bg-green-100 text-green-800'
                                : tenant.status === 'suspended'
                                  ? 'bg-yellow-100 text-yellow-800'
                                  : 'bg-red-100 text-red-800'
                        }`}
                    >
                        {tenant.status}
                    </span>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div className="acu-fieldset">
                        <div className="acu-fieldset-header">
                            <span className="acu-fieldset-title">Company Info</span>
                        </div>
                        <div className="acu-fieldset-body space-y-2">
                            <div><strong>Slug:</strong> {tenant.slug}</div>
                            <div><strong>Legal Name:</strong> {tenant.legal_name || '—'}</div>
                            <div><strong>Registration:</strong> {tenant.registration_number || '—'}</div>
                            <div><strong>License:</strong> {tenant.license_number || '—'}</div>
                            <div><strong>Contact:</strong> {tenant.contact_email}</div>
                            <div><strong>Phone:</strong> {tenant.contact_phone || '—'}</div>
                        </div>
                    </div>

                    <div className="acu-fieldset">
                        <div className="acu-fieldset-header">
                            <span className="acu-fieldset-title">Configuration</span>
                        </div>
                        <div className="acu-fieldset-body space-y-2">
                            <div><strong>Country:</strong> {tenant.country_code}</div>
                            <div><strong>Currency:</strong> {tenant.currency}</div>
                            <div><strong>Timezone:</strong> {tenant.timezone}</div>
                            <div><strong>Revenue Share:</strong> {tenant.revenue_share_pct}%</div>
                            <div><strong>Custom Domain:</strong> {tenant.domain || '—'}</div>
                        </div>
                    </div>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div className="acu-fieldset">
                        <div className="acu-fieldset-body text-center">
                            <div className="text-2xl font-bold">{tenant.users_count}</div>
                            <div className="text-sm text-muted-foreground">Players</div>
                        </div>
                    </div>
                    <div className="acu-fieldset">
                        <div className="acu-fieldset-body text-center">
                            <div className="text-2xl font-bold">{tenant.venues_count}</div>
                            <div className="text-sm text-muted-foreground">Venues</div>
                        </div>
                    </div>
                    <div className="acu-fieldset">
                        <div className="acu-fieldset-body text-center">
                            <div className="text-2xl font-bold">{tenant.voucher_codes_count}</div>
                            <div className="text-sm text-muted-foreground">Voucher Codes</div>
                        </div>
                    </div>
                </div>

                <div className="acu-fieldset">
                    <div className="acu-fieldset-header">
                        <span className="acu-fieldset-title">Assigned Games</span>
                    </div>
                    <div className="acu-fieldset-body">
                        {tenant.enabled_games && tenant.enabled_games.length > 0 ? (
                            <ul className="space-y-1">
                                {tenant.enabled_games.map((game) => (
                                    <li key={game.uuid} className="flex justify-between">
                                        <span>{game.name}</span>
                                        <span className="text-sm text-muted-foreground">{game.type}</span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <div className="text-sm text-muted-foreground">No games assigned</div>
                        )}
                    </div>
                </div>

                {/* Venues Section */}
                <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-blue)' } as React.CSSProperties}>
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-map-marker" />
                            <span>Venues</span>
                            <span className="text-xs font-normal text-[var(--acu-text-light)] ml-1">
                                ({venues.length})
                            </span>
                        </div>
                        <Button
                            label="Add Venue"
                            icon="pi pi-plus"
                            size="small"
                            onClick={() => setAddVenueOpen(true)}
                        />
                    </div>
                    <div className="acu-fieldset-body p-0">
                        <DataTable
                            value={venues}
                            loading={venuesLoading}
                            size="small"
                            emptyMessage="No venues found"
                            showGridlines={false}
                            dataKey="uuid"
                        >
                            <Column header="Venue" body={venueNameTemplate} />
                            <Column header="Location" body={venueLocationTemplate} />
                            <Column header="Status" body={venueStatusTemplate} />
                            <Column field="staff_count" header="Staff" />
                            <Column field="terminals_count" header="Terminals" />
                            <Column header="Actions" body={venueActionsTemplate} style={{ width: '5rem' }} />
                        </DataTable>
                    </div>
                </div>
            </div>

            {/* Add Venue Dialog */}
            <Dialog
                header="Add New Venue"
                visible={addVenueOpen}
                style={{ width: '28rem' }}
                onHide={() => setAddVenueOpen(false)}
                footer={addVenueDialogFooter}
                modal
                draggable={false}
            >
                <p className="text-sm text-[var(--acu-text-muted)] mb-4">
                    Create a new venue for {tenant.name}
                </p>
                <div className="space-y-4">
                    <div className="flex flex-col gap-1">
                        <label htmlFor="name" className="text-sm font-medium text-[var(--acu-text)]">
                            Venue Name *
                        </label>
                        <InputText
                            id="name"
                            value={formData.name}
                            onChange={(e) => {
                                const name = e.target.value;
                                setFormData({
                                    ...formData,
                                    name,
                                    slug: formData.slug || generateSlug(name),
                                });
                            }}
                            placeholder="e.g., Casino Windhoek"
                            className="w-full"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label htmlFor="slug" className="text-sm font-medium text-[var(--acu-text)]">
                            Slug *
                        </label>
                        <InputText
                            id="slug"
                            value={formData.slug}
                            onChange={(e) =>
                                setFormData({
                                    ...formData,
                                    slug: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''),
                                })
                            }
                            placeholder="e.g., casino-windhoek"
                            className="w-full"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label htmlFor="address" className="text-sm font-medium text-[var(--acu-text)]">
                            Address *
                        </label>
                        <InputText
                            id="address"
                            value={formData.address_line_1}
                            onChange={(e) =>
                                setFormData({
                                    ...formData,
                                    address_line_1: e.target.value,
                                })
                            }
                            placeholder="Street address"
                            className="w-full"
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="flex flex-col gap-1">
                            <label htmlFor="city" className="text-sm font-medium text-[var(--acu-text)]">
                                City *
                            </label>
                            <InputText
                                id="city"
                                value={formData.city}
                                onChange={(e) =>
                                    setFormData({
                                        ...formData,
                                        city: e.target.value,
                                    })
                                }
                                placeholder="e.g., Windhoek"
                                className="w-full"
                            />
                        </div>
                        <div className="flex flex-col gap-1">
                            <label htmlFor="country" className="text-sm font-medium text-[var(--acu-text)]">
                                Country Code *
                            </label>
                            <InputText
                                id="country"
                                value={formData.country_code}
                                onChange={(e) =>
                                    setFormData({
                                        ...formData,
                                        country_code: e.target.value.toUpperCase().slice(0, 2),
                                    })
                                }
                                placeholder="NA"
                                maxLength={2}
                                className="w-full"
                            />
                        </div>
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="flex flex-col gap-1">
                            <label htmlFor="phone" className="text-sm font-medium text-[var(--acu-text)]">
                                Phone
                            </label>
                            <InputText
                                id="phone"
                                value={formData.phone}
                                onChange={(e) =>
                                    setFormData({
                                        ...formData,
                                        phone: e.target.value,
                                    })
                                }
                                placeholder="+264 61 123 4567"
                                className="w-full"
                            />
                        </div>
                        <div className="flex flex-col gap-1">
                            <label htmlFor="email" className="text-sm font-medium text-[var(--acu-text)]">
                                Email
                            </label>
                            <InputText
                                id="email"
                                type="email"
                                value={formData.email}
                                onChange={(e) =>
                                    setFormData({
                                        ...formData,
                                        email: e.target.value,
                                    })
                                }
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
