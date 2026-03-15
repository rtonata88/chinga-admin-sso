import PageHeader from '@/components/acumatica/Common/PageHeader';
import StatusBadge from '@/components/acumatica/Common/StatusBadge';
import UserLayout from '@/layouts/user-layout';
import type { StatusVariant } from '@/types/acumatica';
import { Head } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Column } from 'primereact/column';
import { DataTable } from 'primereact/datatable';
import { Dialog } from 'primereact/dialog';
import { Dropdown } from 'primereact/dropdown';
import { InputText } from 'primereact/inputtext';
import { useEffect, useMemo, useState } from 'react';

interface Venue {
    uuid: string;
    name: string;
}

interface VoucherCode {
    uuid: string;
    code: string;
    venue: {
        uuid: string;
        name: string;
    };
    balance: number;
    currency: string;
    status: string;
    total_loaded: number;
    total_cashed_out: number;
    last_activity_at: string | null;
    expires_at: string | null;
    created_at: string;
}

interface Meta {
    current_page: number;
    last_page: number;
    total: number;
}

function formatCurrency(amount: number, currency: string = 'NAD'): string {
    return `${currency} ${amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}`;
}

function mapCodeStatus(status: string): StatusVariant {
    switch (status) {
        case 'active':
            return 'active';
        case 'in_use':
            return 'pending';
        case 'expired':
            return 'inactive';
        case 'voided':
            return 'error';
        default:
            return 'inactive';
    }
}

export default function VoucherCodes() {
    // Get initial venue filter from URL query parameter
    const initialVenueFilter = useMemo(() => {
        if (typeof window !== 'undefined') {
            const params = new URLSearchParams(window.location.search);
            return params.get('venue') || '';
        }
        return '';
    }, []);

    const [codes, setCodes] = useState<VoucherCode[]>([]);
    const [venues, setVenues] = useState<Venue[]>([]);
    const [meta, setMeta] = useState<Meta | null>(null);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [venueFilter, setVenueFilter] = useState(initialVenueFilter);
    const [statusFilter, setStatusFilter] = useState('');
    const [page, setPage] = useState(1);

    // Generate dialog
    const [generateOpen, setGenerateOpen] = useState(false);
    const [selectedVenue, setSelectedVenue] = useState('');
    const [generateCount, setGenerateCount] = useState('10');
    const [initialBalance, setInitialBalance] = useState('100');
    const [prefix, setPrefix] = useState('');
    const [generating, setGenerating] = useState(false);
    const [generatedCodes, setGeneratedCodes] = useState<
        { code: string; balance: number }[]
    >([]);

    const fetchVenues = async () => {
        try {
            const response = await fetch('/api/v1/admin/venues', {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (data.success) {
                setVenues(data.data);
            }
        } catch (error) {
            console.error('Failed to fetch venues:', error);
        }
    };

    const fetchCodes = async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams();
            if (search) params.append('code', search);
            if (venueFilter) params.append('venue_uuid', venueFilter);
            if (statusFilter) params.append('status', statusFilter);
            params.append('page', page.toString());

            const response = await fetch(
                `/api/v1/admin/voucher-codes?${params}`,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            );
            const data = await response.json();
            if (data.success) {
                setCodes(data.data);
                setMeta(data.meta);
            }
        } catch (error) {
            console.error('Failed to fetch codes:', error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchVenues();
    }, []);

    useEffect(() => {
        fetchCodes();
    }, [page, venueFilter, statusFilter]);

    const handleSearch = () => {
        setPage(1);
        fetchCodes();
    };

    const getCsrfToken = () => {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    };

    const handleGenerate = async () => {
        if (!selectedVenue) return;

        setGenerating(true);
        try {
            const response = await fetch(
                `/api/v1/admin/venues/${selectedVenue}/codes/generate`,
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        count: parseInt(generateCount),
                        initial_balance: parseFloat(initialBalance),
                        prefix: prefix || undefined,
                    }),
                },
            );
            const data = await response.json();
            if (data.success) {
                setGeneratedCodes(data.data);
                fetchCodes();
            }
        } catch (error) {
            console.error('Failed to generate codes:', error);
        } finally {
            setGenerating(false);
        }
    };

    const handleVoidCode = async (venueUuid: string, codeUuid: string) => {
        if (!confirm('Are you sure you want to void this code? This cannot be undone.')) {
            return;
        }

        try {
            const response = await fetch(
                `/api/v1/admin/venues/${venueUuid}/codes/${codeUuid}/void`,
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                    credentials: 'same-origin',
                },
            );
            const data = await response.json();
            if (data.success) {
                fetchCodes();
            }
        } catch (error) {
            console.error('Failed to void code:', error);
        }
    };

    const exportCodes = () => {
        if (generatedCodes.length === 0) return;

        const csv = [
            'Code,Balance',
            ...generatedCodes.map((c) => `${c.code},${c.balance}`),
        ].join('\n');

        const blob = new Blob([csv], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `voucher-codes-${new Date().toISOString().split('T')[0]}.csv`;
        a.click();
        URL.revokeObjectURL(url);
    };

    // Dropdown options
    const venueOptions = [
        { label: 'All venues', value: '' },
        ...venues.map((v) => ({ label: v.name, value: v.uuid })),
    ];

    const statusOptions = [
        { label: 'All statuses', value: '' },
        { label: 'Active', value: 'active' },
        { label: 'In Use', value: 'in_use' },
        { label: 'Expired', value: 'expired' },
        { label: 'Voided', value: 'voided' },
    ];

    const generateVenueOptions = venues.map((v) => ({ label: v.name, value: v.uuid }));

    // Column body templates
    const codeTemplate = (rowData: VoucherCode) => (
        <code className="rounded bg-[var(--acu-surface-ground)] px-2 py-1 text-sm font-mono text-[var(--acu-text)]">
            {rowData.code}
        </code>
    );

    const venueTemplate = (rowData: VoucherCode) => (
        <span className="text-sm text-[var(--acu-text)]">{rowData.venue.name}</span>
    );

    const balanceTemplate = (rowData: VoucherCode) => (
        <span className="font-medium text-sm text-[var(--acu-text)]">
            {formatCurrency(rowData.balance, rowData.currency)}
        </span>
    );

    const statusTemplate = (rowData: VoucherCode) => (
        <StatusBadge status={mapCodeStatus(rowData.status)} label={rowData.status} />
    );

    const loadedCashedTemplate = (rowData: VoucherCode) => (
        <span className="text-sm text-[var(--acu-text-light)]">
            {formatCurrency(rowData.total_loaded, rowData.currency)} / {formatCurrency(rowData.total_cashed_out, rowData.currency)}
        </span>
    );

    const createdTemplate = (rowData: VoucherCode) => (
        <span className="text-sm text-[var(--acu-text-light)]">
            {new Date(rowData.created_at).toLocaleDateString()}
        </span>
    );

    const actionsTemplate = (rowData: VoucherCode) => (
        <>
            {rowData.status === 'active' && (
                <Button
                    icon="pi pi-times-circle"
                    severity="danger"
                    text
                    size="small"
                    tooltip="Void code"
                    onClick={() => handleVoidCode(rowData.venue.uuid, rowData.uuid)}
                />
            )}
        </>
    );

    const closeGenerateDialog = () => {
        setGenerateOpen(false);
        setGeneratedCodes([]);
        setSelectedVenue('');
        setGenerateCount('10');
        setInitialBalance('100');
        setPrefix('');
    };

    const generateDialogFooter = (
        <div className="flex justify-end gap-2">
            <Button
                label={generatedCodes.length > 0 ? 'Close' : 'Cancel'}
                icon="pi pi-times"
                severity="secondary"
                outlined
                onClick={closeGenerateDialog}
            />
            {generatedCodes.length === 0 && (
                <Button
                    label={generating ? 'Generating...' : 'Generate'}
                    icon="pi pi-cog"
                    onClick={handleGenerate}
                    disabled={!selectedVenue || generating}
                    loading={generating}
                />
            )}
        </div>
    );

    return (
        <UserLayout title="Voucher Codes">
            <Head title="Voucher Codes" />

            <div className="space-y-6">
                <PageHeader title="Voucher Codes" subtitle="Generate and manage voucher codes">
                    <Button
                        label="Refresh"
                        icon="pi pi-refresh"
                        severity="secondary"
                        outlined
                        onClick={fetchCodes}
                    />
                    <Button
                        label="Generate Codes"
                        icon="pi pi-plus"
                        onClick={() => {
                            setSelectedVenue(venueFilter);
                            setGenerateOpen(true);
                        }}
                    />
                </PageHeader>

                {/* Filters */}
                <div className="acu-fieldset">
                    <div className="acu-fieldset-body">
                        <div className="flex flex-wrap gap-4">
                            <div className="flex gap-2">
                                <span className="p-input-icon-left">
                                    <i className="pi pi-search" />
                                    <InputText
                                        placeholder="Search by code..."
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                                        style={{ width: '16rem' }}
                                    />
                                </span>
                                <Button
                                    icon="pi pi-search"
                                    onClick={handleSearch}
                                    tooltip="Search"
                                />
                            </div>
                            <Dropdown
                                value={venueFilter}
                                options={venueOptions}
                                onChange={(e) => setVenueFilter(e.value)}
                                placeholder="All venues"
                                style={{ width: '12rem' }}
                            />
                            <Dropdown
                                value={statusFilter}
                                options={statusOptions}
                                onChange={(e) => setStatusFilter(e.value)}
                                placeholder="All statuses"
                                style={{ width: '10rem' }}
                            />
                        </div>
                    </div>
                </div>

                {/* Codes Table */}
                <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-blue)' } as React.CSSProperties}>
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-credit-card" />
                            <span>Voucher Codes</span>
                            <span className="text-xs font-normal text-[var(--acu-text-light)] ml-1">
                                ({meta?.total ? `${meta.total} total` : 'Loading...'})
                            </span>
                        </div>
                    </div>
                    <div className="acu-fieldset-body p-0">
                        <DataTable
                            value={codes}
                            loading={loading}
                            size="small"
                            emptyMessage="No voucher codes found"
                            showGridlines={false}
                            dataKey="uuid"
                        >
                            <Column header="Code" body={codeTemplate} />
                            <Column header="Venue" body={venueTemplate} />
                            <Column header="Balance" body={balanceTemplate} />
                            <Column header="Status" body={statusTemplate} />
                            <Column header="Loaded / Cashed Out" body={loadedCashedTemplate} />
                            <Column header="Created" body={createdTemplate} />
                            <Column header="Actions" body={actionsTemplate} style={{ width: '5rem' }} />
                        </DataTable>

                        {/* Pagination */}
                        {meta && meta.last_page > 1 && (
                            <div className="flex items-center justify-between px-4 py-3 border-t border-[var(--acu-border)]">
                                <p className="text-sm text-[var(--acu-text-light)]">
                                    Page {meta.current_page} of {meta.last_page}
                                </p>
                                <div className="flex gap-2">
                                    <Button
                                        label="Previous"
                                        icon="pi pi-chevron-left"
                                        severity="secondary"
                                        outlined
                                        size="small"
                                        disabled={meta.current_page === 1}
                                        onClick={() => setPage((p) => Math.max(1, p - 1))}
                                    />
                                    <Button
                                        label="Next"
                                        icon="pi pi-chevron-right"
                                        iconPos="right"
                                        severity="secondary"
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

            {/* Generate Codes Dialog */}
            <Dialog
                header="Generate Voucher Codes"
                visible={generateOpen}
                style={{ width: '32rem' }}
                onHide={closeGenerateDialog}
                footer={generateDialogFooter}
                modal
                draggable={false}
            >
                <p className="text-sm text-[var(--acu-text-muted)] mb-4">
                    Create new voucher codes for a venue
                </p>

                {generatedCodes.length > 0 ? (
                    <div className="space-y-4">
                        <div className="rounded border border-[var(--acu-border)] p-4">
                            <p className="mb-2 font-medium text-[var(--acu-text)]">
                                Generated {generatedCodes.length} codes:
                            </p>
                            <div className="max-h-48 overflow-auto space-y-1">
                                {generatedCodes.map((c, i) => (
                                    <div
                                        key={i}
                                        className="flex justify-between text-sm"
                                    >
                                        <code className="font-mono text-[var(--acu-text)]">
                                            {c.code}
                                        </code>
                                        <span className="text-[var(--acu-text-light)]">
                                            {formatCurrency(c.balance)}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </div>
                        <Button
                            label="Export as CSV"
                            icon="pi pi-download"
                            onClick={exportCodes}
                            className="w-full"
                        />
                    </div>
                ) : (
                    <div className="space-y-4">
                        <div className="flex flex-col gap-1">
                            <label className="text-sm font-medium text-[var(--acu-text)]">Venue</label>
                            <Dropdown
                                value={selectedVenue}
                                options={generateVenueOptions}
                                onChange={(e) => setSelectedVenue(e.value)}
                                placeholder="Select venue"
                                className="w-full"
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="flex flex-col gap-1">
                                <label className="text-sm font-medium text-[var(--acu-text)]">Number of Codes</label>
                                <InputText
                                    type="number"
                                    min={1}
                                    max={100}
                                    value={generateCount}
                                    onChange={(e) => setGenerateCount(e.target.value)}
                                    className="w-full"
                                />
                            </div>
                            <div className="flex flex-col gap-1">
                                <label className="text-sm font-medium text-[var(--acu-text)]">Initial Balance (NAD)</label>
                                <InputText
                                    type="number"
                                    min={0}
                                    step={0.01}
                                    value={initialBalance}
                                    onChange={(e) => setInitialBalance(e.target.value)}
                                    className="w-full"
                                />
                            </div>
                        </div>
                        <div className="flex flex-col gap-1">
                            <label className="text-sm font-medium text-[var(--acu-text)]">Code Prefix (optional)</label>
                            <InputText
                                placeholder="e.g., VIP"
                                maxLength={10}
                                value={prefix}
                                onChange={(e) =>
                                    setPrefix(
                                        e.target.value
                                            .toUpperCase()
                                            .replace(/[^A-Z0-9]/g, ''),
                                    )
                                }
                                className="w-full"
                            />
                            <p className="text-xs text-[var(--acu-text-light)]">
                                Codes will be formatted as: PREFIX-XXXX-XXXX-XXXX
                            </p>
                        </div>
                    </div>
                )}
            </Dialog>
        </UserLayout>
    );
}
