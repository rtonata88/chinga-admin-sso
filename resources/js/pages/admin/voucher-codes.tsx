// resources/js/pages/admin/voucher-codes.tsx
//
// Voucher codes, brass-on-ink to match /tenant-overview. Page head
// → filter bar (status chips + venue select + search) → table with
// Print + Void row actions. The Create-voucher dialog stays on
// PrimeReact (multi-step form with success state). The thermal-
// receipt print window logic is unchanged — it's its own document.

import UserLayout from '@/layouts/user-layout';
import { Head } from '@inertiajs/react';
import { Button } from 'primereact/button';
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
    venue: { uuid: string; name: string };
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

const STATUS_FILTERS = [
    { label: 'All', value: '' },
    { label: 'Active', value: 'active' },
    { label: 'In use', value: 'in_use' },
    { label: 'Expired', value: 'expired' },
    { label: 'Voided', value: 'voided' },
];

function statusPill(status: string): string {
    switch (status) {
        case 'active': return 'live';
        case 'in_use': return 'pending';
        case 'expired': return 'void';
        case 'voided': return 'flagged';
        default: return 'void';
    }
}

function formatNAD(amount: number): string {
    return amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

const DATE_FMT = new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });

function formatDate(iso: string): string {
    return DATE_FMT.format(new Date(iso));
}

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

function printVoucherReceipts(vouchers: PrintableVoucher[]) {
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
        if (v.pin) receipt.appendChild(createSection('PIN:', v.pin));
        if (v.expiresAt) {
            receipt.appendChild(createSection('Expires:', new Date(v.expiresAt).toLocaleDateString()));
        }
        receipt.appendChild(createSection('Created:', new Date(v.createdAt).toLocaleDateString()));
        receipt.appendChild(createTextDiv('divider', '================================'));

        doc.body.appendChild(receipt);
    }

    doc.close();
    setTimeout(() => printWindow.print(), 200);
}

export default function VoucherCodes() {
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

    const [generateOpen, setGenerateOpen] = useState(false);
    const [selectedVenue, setSelectedVenue] = useState('');
    const [initialBalance, setInitialBalance] = useState('100');
    const [pin, setPin] = useState('');
    const [generating, setGenerating] = useState(false);
    const [generatedCode, setGeneratedCode] = useState<
        { code: string; balance: number; currency: string } | null
    >(null);

    const getCsrfToken = () =>
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const fetchVenues = async () => {
        try {
            const response = await fetch('/api/v1/admin/venues', {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (data.success) setVenues(data.data);
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

            const response = await fetch(`/api/v1/admin/voucher-codes?${params}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
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

    useEffect(() => { fetchVenues(); }, []);
    useEffect(() => {
        fetchCodes();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [page, venueFilter, statusFilter]);

    const submitSearch = () => { setPage(1); fetchCodes(); };

    const handleGenerate = async () => {
        if (!selectedVenue) return;
        setGenerating(true);
        try {
            const response = await fetch(`/api/v1/admin/venues/${selectedVenue}/codes/generate`, {
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
            });
            const data = await response.json();
            if (data.success) {
                setGeneratedCode(data.data);
                fetchCodes();

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
        if (!confirm('Void this code? This cannot be undone.')) return;
        try {
            const response = await fetch(`/api/v1/admin/venues/${venueUuid}/codes/${codeUuid}/void`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (data.success) fetchCodes();
        } catch (error) {
            console.error('Failed to void code:', error);
        }
    };

    const closeGenerateDialog = () => {
        setGenerateOpen(false);
        setGeneratedCode(null);
        setSelectedVenue('');
        setPin('');
        setInitialBalance('100');
    };

    const generateVenueOptions = venues.map((v) => ({ label: v.name, value: v.uuid }));

    return (
        <UserLayout title="Voucher codes">
            <Head title="Voucher codes · Admin" />

            <div className="cgo-page">
                {/* Page header */}
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Admin</div>
                        <h1 className="cgo-title">Voucher codes</h1>
                        <div className="cgo-subtitle">
                            Issue, monitor and void voucher codes per venue.
                        </div>
                    </div>
                    <div style={{ display: 'flex', gap: 8 }}>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={fetchCodes}
                        >
                            Refresh
                        </button>
                        <button
                            type="button"
                            className="cg-btn cg-btn--primary cg-btn--sm"
                            onClick={() => {
                                setSelectedVenue(venueFilter);
                                setGenerateOpen(true);
                            }}
                        >
                            + New voucher
                        </button>
                    </div>
                </div>

                {/* Filter bar — status chips, venue select, search */}
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
                    <div className="cgo-right" style={{ flexWrap: 'wrap' }}>
                        <label className="cgo-input" style={{ minWidth: 180 }}>
                            <select
                                value={venueFilter}
                                onChange={(e) => { setVenueFilter(e.target.value); setPage(1); }}
                                style={{
                                    all: 'unset', flex: 1,
                                    color: 'inherit', font: 'inherit', cursor: 'pointer',
                                }}
                            >
                                <option value="">All venues</option>
                                {venues.map((v) => (
                                    <option key={v.uuid} value={v.uuid}>{v.name}</option>
                                ))}
                            </select>
                        </label>
                        <label className="cgo-input">
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && submitSearch()}
                                placeholder="Search by code…"
                                style={{ minWidth: 220 }}
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

                {/* Voucher codes table */}
                <div
                    className="cgo-table-wrap cgo-table-wrap--scroll"
                    style={{ borderRadius: '0 0 8px 8px', borderTop: 0 }}
                >
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ minWidth: 180 }}>Code</th>
                                <th style={{ minWidth: 160 }}>Venue</th>
                                <th className="cgo-r" style={{ width: 130 }}>Balance</th>
                                <th style={{ width: 100 }}>Status</th>
                                <th className="cgo-r" style={{ width: 200 }}>Loaded / Cashed</th>
                                <th style={{ width: 110 }}>Created</th>
                                <th style={{ width: 100 }} />
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan={7} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        Loading…
                                    </td>
                                </tr>
                            ) : codes.length === 0 ? (
                                <tr>
                                    <td colSpan={7} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        No voucher codes match the current filters.
                                    </td>
                                </tr>
                            ) : (
                                codes.map((c) => (
                                    <tr key={c.uuid}>
                                        <td>
                                            <span
                                                style={{
                                                    display: 'inline-block',
                                                    padding: '3px 10px',
                                                    border: '1px solid var(--cg-rule-strong)',
                                                    borderRadius: 4,
                                                    background: 'var(--cg-ink-elevated)',
                                                    color: 'var(--cg-brass-hi)',
                                                    fontFamily: 'var(--cg-mono)',
                                                    fontSize: 12,
                                                    letterSpacing: '0.04em',
                                                    fontWeight: 600,
                                                }}
                                            >
                                                {c.code}
                                            </span>
                                        </td>
                                        <td>
                                            <span style={{ fontSize: 12, color: 'var(--cg-fg-2)' }}>
                                                {c.venue.name}
                                            </span>
                                            {c.tenant_name && (
                                                <div className="cgo-uid">{c.tenant_name}</div>
                                            )}
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-stake">
                                                <span className="cgo-ccy">{c.currency}</span>
                                                {formatNAD(c.balance)}
                                            </span>
                                        </td>
                                        <td>
                                            <span className={`cgo-pill ${statusPill(c.status)}`}>
                                                {c.status.replace('_', ' ')}
                                            </span>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-uid" style={{ fontFamily: 'var(--cg-mono)' }}>
                                                {formatNAD(c.total_loaded)} / {formatNAD(c.total_cashed_out)}
                                            </span>
                                        </td>
                                        <td>
                                            <span className="cgo-uid">{formatDate(c.created_at)}</span>
                                        </td>
                                        <td>
                                            <div style={{ display: 'flex', gap: 4, justifyContent: 'flex-end' }}>
                                                <button
                                                    type="button"
                                                    className="cgo-row-action"
                                                    aria-label="Print receipt"
                                                    title="Print receipt"
                                                    onClick={() =>
                                                        printVoucherReceipts([{
                                                            code: c.code,
                                                            balance: c.balance,
                                                            currency: c.currency,
                                                            tenantName: c.tenant_name,
                                                            venueName: c.venue.name,
                                                            expiresAt: c.expires_at,
                                                            createdAt: c.created_at,
                                                        }])
                                                    }
                                                >
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><polyline points="6 9 6 2 18 2 18 9" /><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" /><rect x="6" y="14" width="12" height="8" /></svg>
                                                </button>
                                                {c.status === 'active' && (
                                                    <button
                                                        type="button"
                                                        className="cgo-row-action"
                                                        aria-label="Void code"
                                                        title="Void code"
                                                        style={{ color: 'var(--cg-neg)', borderColor: 'var(--cg-neg)' }}
                                                        onClick={() => handleVoidCode(c.venue.uuid, c.uuid)}
                                                    >
                                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="10" /><line x1="15" y1="9" x2="9" y2="15" /><line x1="9" y1="9" x2="15" y2="15" /></svg>
                                                    </button>
                                                )}
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
                                        Showing <b className="cgo-mono">{codes.length}</b> of{' '}
                                        <b className="cgo-mono">{meta.total.toLocaleString()}</b> codes
                                    </>
                                ) : (
                                    'No codes'
                                )}
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

            {/* Create voucher dialog */}
            <Dialog
                header="Create voucher"
                visible={generateOpen}
                style={{ width: '32rem' }}
                onHide={closeGenerateDialog}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button
                            label={generatedCode ? 'Close' : 'Cancel'}
                            severity="secondary"
                            outlined
                            onClick={closeGenerateDialog}
                        />
                        {!generatedCode && (
                            <Button
                                label={generating ? 'Creating…' : 'Create'}
                                onClick={handleGenerate}
                                disabled={!selectedVenue || generating}
                                loading={generating}
                            />
                        )}
                    </div>
                }
            >
                {generatedCode ? (
                    <div className="space-y-4">
                        <div
                            style={{
                                background: 'var(--cg-ink-elevated)',
                                border: '1px solid var(--cg-rule)',
                                borderRadius: 6,
                                padding: 14,
                            }}
                        >
                            <div style={{ fontSize: 12, fontWeight: 500, marginBottom: 8 }}>
                                Voucher created
                            </div>
                            <div className="flex justify-between items-center">
                                <code
                                    style={{
                                        fontFamily: 'var(--cg-mono)',
                                        color: 'var(--cg-brass-hi)',
                                        letterSpacing: '0.06em',
                                        fontSize: '1.2rem',
                                        fontWeight: 600,
                                    }}
                                >
                                    {generatedCode.code}
                                </code>
                                <span style={{ fontFamily: 'var(--cg-mono)', fontSize: '1.1rem' }}>
                                    {generatedCode.currency || 'NAD'} {formatNAD(generatedCode.balance)}
                                </span>
                            </div>
                        </div>
                        <Button
                            label="Print receipt"
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
                            <label style={{ fontSize: 12, fontWeight: 500 }}>Venue</label>
                            <Dropdown
                                value={selectedVenue}
                                options={generateVenueOptions}
                                onChange={(e) => setSelectedVenue(e.value)}
                                placeholder="Select venue"
                                className="w-full"
                            />
                        </div>
                        <div className="flex flex-col gap-1">
                            <label style={{ fontSize: 12, fontWeight: 500 }}>Amount (NAD)</label>
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
                            <label style={{ fontSize: 12, fontWeight: 500 }}>PIN (optional · 4 digits)</label>
                            <InputText
                                type="text"
                                maxLength={4}
                                placeholder="e.g. 1234"
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
