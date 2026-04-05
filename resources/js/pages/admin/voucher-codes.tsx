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
    tenant_name: string | null;
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
    const [initialBalance, setInitialBalance] = useState('100');
    const [pin, setPin] = useState('');
    const [generating, setGenerating] = useState(false);
    const [generatedCode, setGeneratedCode] = useState<
        { code: string; balance: number; currency: string } | null
    >(null);

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
                        initial_balance: parseFloat(initialBalance),
                        pin: pin || undefined,
                    }),
                },
            );
            const data = await response.json();
            if (data.success) {
                setGeneratedCode(data.data);
                fetchCodes();

                // Auto-open print receipt
                const venueName = venues.find((v) => v.uuid === selectedVenue)?.name || 'Unknown Venue';
                printVoucherReceipts([{
                    code: data.data.code,
                    balance: data.data.balance,
                    currency: data.data.currency || 'NAD',
                    tenantName: null,
                    venueName,
                    pin: pin || undefined,
                    createdAt: new Date().toISOString(),
                }]);
            }
        } catch (error) {
            console.error('Failed to create voucher:', error);
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

    interface PrintableVoucher {
        code: string;
        balance: number;
        currency: string;
        tenantName: string | null;
        venueName: string;
        pin?: string;
        expiresAt?: string | null;
        createdAt: string;
    }

    const printVoucherReceipts = (vouchers: PrintableVoucher[]) => {
        const printWindow = window.open('', '_blank');
        if (!printWindow) return;

        const doc = printWindow.document;
        doc.title = 'Voucher Receipts';

        const style = doc.createElement('style');
        style.textContent = [
            '@page { margin: 10mm; }',
            'body { font-family: "Courier New", monospace; margin: 0; padding: 0; }',
            '.receipt { width: 80mm; padding: 5mm 0; page-break-after: always; }',
            '.receipt:last-child { page-break-after: auto; }',
            '.divider { font-size: 12px; text-align: center; margin: 4px 0; }',
            '.venue { font-size: 14px; font-weight: bold; text-align: center; margin: 8px 0; }',
            '.section { margin: 8px 0; padding: 0 4px; }',
            '.label { font-size: 11px; color: #666; }',
            '.code { font-size: 16px; font-weight: bold; letter-spacing: 2px; margin-top: 2px; }',
            '.value { font-size: 13px; font-weight: bold; margin-top: 2px; }',
        ].join('\n');
        doc.head.appendChild(style);

        const createTextDiv = (className: string, text: string): HTMLDivElement => {
            const div = doc.createElement('div');
            div.className = className;
            div.textContent = text;
            return div;
        };

        const createSection = (label: string, value: string, valueClass: string = 'value'): HTMLDivElement => {
            const section = doc.createElement('div');
            section.className = 'section';
            section.appendChild(createTextDiv('label', label));
            section.appendChild(createTextDiv(valueClass, value));
            return section;
        };

        for (const v of vouchers) {
            const receipt = doc.createElement('div');
            receipt.className = 'receipt';

            const formatBalance = `${v.currency} ${v.balance.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

            receipt.appendChild(createTextDiv('divider', '================================'));
            if (v.tenantName) {
                receipt.appendChild(createTextDiv('venue', v.tenantName));
            }
            receipt.appendChild(createTextDiv('venue', v.venueName));
            receipt.appendChild(createTextDiv('divider', '================================'));
            receipt.appendChild(createSection('Voucher Code:', v.code, 'code'));
            receipt.appendChild(createSection('Balance:', formatBalance));
            if (v.pin) {
                receipt.appendChild(createSection('PIN:', v.pin));
            }
            if (v.expiresAt) {
                receipt.appendChild(createSection('Expires:', new Date(v.expiresAt).toLocaleDateString()));
            }
            receipt.appendChild(createSection('Created:', new Date(v.createdAt).toLocaleDateString()));
            receipt.appendChild(createTextDiv('divider', '================================'));

            doc.body.appendChild(receipt);
        }

        doc.close();
        setTimeout(() => printWindow.print(), 200);
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
        <code style={{
            background: 'var(--acu-surface-elevated)',
            color: 'var(--acu-primary)',
            border: '1px solid var(--acu-border)',
            borderRadius: '6px',
            padding: '3px 10px',
            fontSize: '0.8125rem',
            fontFamily: 'monospace',
            letterSpacing: '0.04em',
        }}>
            {rowData.code}
        </code>
    );

    const venueTemplate = (rowData: VoucherCode) => (
        <span style={{ color: 'var(--acu-text)', fontFamily: 'var(--font-body)', fontSize: '0.875rem' }}>
            {rowData.venue.name}
        </span>
    );

    const balanceTemplate = (rowData: VoucherCode) => (
        <span style={{ color: 'var(--acu-text)', fontFamily: 'var(--font-body)', fontSize: '0.875rem', fontWeight: 500 }}>
            {formatCurrency(rowData.balance, rowData.currency)}
        </span>
    );

    const statusTemplate = (rowData: VoucherCode) => (
        <StatusBadge status={mapCodeStatus(rowData.status)} label={rowData.status} />
    );

    const loadedCashedTemplate = (rowData: VoucherCode) => (
        <span style={{ color: 'var(--acu-text-light)', fontFamily: 'var(--font-body)', fontSize: '0.875rem' }}>
            {formatCurrency(rowData.total_loaded, rowData.currency)} / {formatCurrency(rowData.total_cashed_out, rowData.currency)}
        </span>
    );

    const createdTemplate = (rowData: VoucherCode) => (
        <span style={{ color: 'var(--acu-text-light)', fontFamily: 'var(--font-body)', fontSize: '0.875rem' }}>
            {new Date(rowData.created_at).toLocaleDateString()}
        </span>
    );

    const actionsTemplate = (rowData: VoucherCode) => (
        <div className="flex gap-1">
            <Button
                icon="pi pi-print"
                text
                severity="info"
                size="small"
                tooltip="Print receipt"
                onClick={() =>
                    printVoucherReceipts([
                        {
                            code: rowData.code,
                            balance: rowData.balance,
                            currency: rowData.currency,
                            tenantName: rowData.tenant_name,
                            venueName: rowData.venue.name,
                            expiresAt: rowData.expires_at,
                            createdAt: rowData.created_at,
                        },
                    ])
                }
            />
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
        </div>
    );

    const closeGenerateDialog = () => {
        setGenerateOpen(false);
        setGeneratedCode(null);
        setSelectedVenue('');
        setPin('');
        setInitialBalance('100');
        setPrefix('');
    };

    const generateDialogFooter = (
        <div className="flex justify-end gap-2">
            <Button
                label={generatedCode ? 'Close' : 'Cancel'}
                icon="pi pi-times"
                severity="secondary"
                outlined
                onClick={closeGenerateDialog}
            />
            {!generatedCode && (
                <Button
                    label={generating ? 'Creating...' : 'Create'}
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

            <div className="space-y-8">
                <PageHeader title="Voucher Codes" subtitle="Generate and manage voucher codes">
                    <Button
                        label="Refresh"
                        icon="pi pi-refresh"
                        severity="secondary"
                        outlined
                        onClick={fetchCodes}
                    />
                    <Button
                        label="Create Voucher"
                        icon="pi pi-plus"
                        onClick={() => {
                            setSelectedVenue(venueFilter);
                            setGenerateOpen(true);
                        }}
                    />
                </PageHeader>

                {/* Filters */}
                <div className="rounded-xl p-4" style={{ background: 'var(--acu-surface-card)', border: '1px solid var(--acu-border)' }}>
                    <div className="flex flex-wrap gap-5">
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

                {/* Codes Table */}
                <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-gold)' } as React.CSSProperties}>
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-credit-card" />
                            <span style={{ fontFamily: 'var(--font-display)' }}>Voucher Codes</span>
                            <span style={{ fontSize: '0.75rem', fontWeight: 400, color: 'var(--acu-text-light)', marginLeft: '0.25rem' }}>
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
                            <Column header="Actions" body={actionsTemplate} style={{ width: '7rem' }} />
                        </DataTable>

                        {/* Pagination */}
                        {meta && meta.last_page > 1 && (
                            <div className="flex items-center justify-between px-4 py-3" style={{ borderTop: '1px solid var(--acu-border)' }}>
                                <p style={{ fontSize: '0.875rem', color: 'var(--acu-text-light)', fontFamily: 'var(--font-body)' }}>
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
                header="Create Voucher"
                visible={generateOpen}
                style={{ width: '32rem' }}
                onHide={closeGenerateDialog}
                footer={generateDialogFooter}
                modal
                draggable={false}
            >
                <p style={{ fontSize: '0.875rem', color: 'var(--acu-text-muted)', marginBottom: '1rem', fontFamily: 'var(--font-body)' }}>
                    Create a voucher for a player
                </p>

                {generatedCode ? (
                    <div className="space-y-4">
                        <div className="rounded-lg p-4" style={{ background: 'var(--acu-surface-elevated)', border: '1px solid var(--acu-border)' }}>
                            <p className="mb-3" style={{ fontWeight: 500, color: 'var(--acu-text)', fontFamily: 'var(--font-body)' }}>
                                Voucher created:
                            </p>
                            <div className="flex justify-between items-center">
                                <code style={{
                                    fontFamily: 'monospace',
                                    color: 'var(--acu-primary)',
                                    letterSpacing: '0.04em',
                                    fontSize: '1.2rem',
                                }}>
                                    {generatedCode.code}
                                </code>
                                <span style={{ color: 'var(--acu-text-light)', fontFamily: 'var(--font-body)', fontSize: '1.1rem' }}>
                                    {formatCurrency(generatedCode.balance, generatedCode.currency || 'NAD')}
                                </span>
                            </div>
                        </div>
                        <Button
                            label="Print Receipt"
                            icon="pi pi-print"
                            onClick={() => {
                                const venueName = venues.find((v) => v.uuid === selectedVenue)?.name || 'Unknown Venue';
                                printVoucherReceipts([{
                                    code: generatedCode.code,
                                    balance: generatedCode.balance,
                                    currency: generatedCode.currency || 'NAD',
                                    tenantName: null,
                                    venueName,
                                    createdAt: new Date().toISOString(),
                                }]);
                            }}
                            className="w-full"
                        />
                    </div>
                ) : (
                    <div className="space-y-4">
                        <div className="flex flex-col gap-1">
                            <label style={{ fontSize: '0.875rem', fontWeight: 500, color: 'var(--acu-text)', fontFamily: 'var(--font-body)' }}>
                                Venue
                            </label>
                            <Dropdown
                                value={selectedVenue}
                                options={generateVenueOptions}
                                onChange={(e) => setSelectedVenue(e.value)}
                                placeholder="Select venue"
                                className="w-full"
                            />
                        </div>
                        <div className="flex flex-col gap-1">
                            <label style={{ fontSize: '0.875rem', fontWeight: 500, color: 'var(--acu-text)', fontFamily: 'var(--font-body)' }}>
                                Amount (NAD)
                            </label>
                            <InputText
                                type="number"
                                min={0.01}
                                step={0.01}
                                value={initialBalance}
                                onChange={(e) => setInitialBalance(e.target.value)}
                                className="w-full"
                            />
                        </div>
                        <div className="flex flex-col gap-1">
                            <label style={{ fontSize: '0.875rem', fontWeight: 500, color: 'var(--acu-text)', fontFamily: 'var(--font-body)' }}>
                                PIN (optional, 4 digits)
                            </label>
                            <InputText
                                type="text"
                                maxLength={4}
                                placeholder="e.g., 1234"
                                value={pin}
                                onChange={(e) => setPin(e.target.value.replace(/[^0-9]/g, ''))}
                                className="w-full"
                            />
                        </div>
                    </div>
                )}
            </Dialog>
        </UserLayout>
    );
}
