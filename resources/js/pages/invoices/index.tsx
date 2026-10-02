// resources/js/pages/invoices/index.tsx
//
// Issued reseller invoices. Platform admins see every tenant and can
// record payments or void; tenant admins see their own, read-only. The
// printable invoice itself is the Blade view at /invoices/{number}.

import { KpiCard, formatCurrencyCompact } from '@/components/operator/kpi-card';
import { formatNAD } from '@/pages/vrrr-pha/format';
import UserLayout from '@/layouts/user-layout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Dialog } from 'primereact/dialog';
import { Toast } from 'primereact/toast';
import { type FormEvent, useEffect, useRef, useState } from 'react';

interface Row {
    id: number;
    number: string;
    tenant_uuid: string | null;
    tenant_name: string | null;
    period_from: string;
    period_to: string;
    issued_at: string;
    due_at: string;
    status: 'issued' | 'part_paid' | 'paid' | 'void';
    overdue: boolean;
    currency: string;
    ggr: number;
    amount_due: number;
    amount_paid: number;
    outstanding: number;
    paid_at: string | null;
}

interface Props {
    rows: Row[];
    totals: { count: number; outstanding: number; overdue: number; paid: number };
    tenants: { uuid: string; name: string }[];
    filters: { tenant_uuid: string | null; status: string | null };
    can_manage: boolean;
    methods: string[];
}

const STATUS_FILTERS = [
    { label: 'All', value: '' },
    { label: 'Open', value: 'issued' },
    { label: 'Part paid', value: 'part_paid' },
    { label: 'Overdue', value: 'overdue' },
    { label: 'Paid', value: 'paid' },
    { label: 'Void', value: 'void' },
];

const METHOD_LABELS: Record<string, string> = { bank_transfer: 'Bank transfer', cash: 'Cash', other: 'Other' };

function fmtDate(iso: string): string {
    return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

function pillClass(r: Row): string {
    if (r.status === 'paid') return 'live';
    if (r.status === 'void') return 'void';
    if (r.overdue) return 'flagged';
    if (r.status === 'part_paid') return 'pending';
    return 'settled';
}

function pillLabel(r: Row): string {
    if (r.status === 'paid') return 'paid';
    if (r.status === 'void') return 'void';
    if (r.overdue) return 'overdue';
    if (r.status === 'part_paid') return 'part paid';
    return 'issued';
}

export default function Invoices({ rows, totals, tenants, filters, can_manage, methods }: Props) {
    const toast = useRef<Toast>(null);
    const { flash } = usePage<{ flash: { success?: string; error?: string } }>().props;
    useEffect(() => {
        if (flash?.success) toast.current?.show({ severity: 'success', summary: 'Done', detail: flash.success });
        if (flash?.error) toast.current?.show({ severity: 'error', summary: 'Error', detail: flash.error });
    }, [flash]);

    const [paying, setPaying] = useState<Row | null>(null);
    const [voiding, setVoiding] = useState<Row | null>(null);
    const today = new Date().toISOString().slice(0, 10);
    const payment = useForm({ amount: '', paid_at: today, method: 'bank_transfer', reference: '', note: '' });
    const voidForm = useForm({ reason: '' });

    const openPayment = (r: Row) => {
        payment.setData({ amount: r.outstanding.toFixed(2), paid_at: today, method: 'bank_transfer', reference: '', note: '' });
        payment.clearErrors();
        setPaying(r);
    };
    const submitPayment = (e: FormEvent) => {
        e.preventDefault();
        if (!paying) return;
        payment.post(`/platform/invoices/${paying.id}/payments`, {
            preserveScroll: true,
            onSuccess: () => setPaying(null),
        });
    };
    const submitVoid = (e: FormEvent) => {
        e.preventDefault();
        if (!voiding) return;
        voidForm.post(`/platform/invoices/${voiding.id}/void`, { preserveScroll: true, onSuccess: () => setVoiding(null) });
    };

    const applyFilters = (next: Partial<Props['filters']>) => {
        const params: Record<string, string> = {};
        const tenant = next.tenant_uuid !== undefined ? next.tenant_uuid : filters.tenant_uuid;
        const status = next.status !== undefined ? next.status : filters.status;
        if (tenant) params.tenant_uuid = tenant;
        if (status) params.status = status;
        router.get('/invoices', params, { preserveState: true, preserveScroll: true });
    };

    const label = (k: string) => (
        <span style={{ fontSize: 10, textTransform: 'uppercase', letterSpacing: '0.16em', color: 'var(--cg-fg-3)', fontWeight: 600 }}>{k}</span>
    );

    return (
        <UserLayout title="Invoices">
            <Head title="Invoices · Admin" />
            <Toast ref={toast} />
            <div className="cgo-page">
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">{can_manage ? 'Platform' : 'Admin'}</div>
                        <h1 className="cgo-title">Invoices</h1>
                        <div className="cgo-subtitle">
                            {can_manage
                                ? 'Issued reseller invoices and the payments recorded against them. Issue a new one from a tenant’s row on Tenant Overview.'
                                : 'Invoices issued to your operation and what has been paid against them.'}
                        </div>
                    </div>
                    {can_manage && (
                        <div className="flex flex-wrap gap-2">
                            <a href="/platform/company" className="cg-btn cg-btn--ghost cg-btn--sm max-sm:min-h-10">Company details</a>
                            <a href="/tenant-overview" className="cg-btn cg-btn--primary cg-btn--sm max-sm:min-h-10">Issue an invoice</a>
                        </div>
                    )}
                </div>

                <div className="cgo-kpis cgo-kpis--4">
                    <KpiCard label="Invoices" value={String(totals.count)} meta="issued, including paid and void" />
                    <KpiCard label="Outstanding" value={formatCurrencyCompact(totals.outstanding)} brass={totals.outstanding > 0} meta="open invoices, unpaid balance" />
                    <KpiCard label="Overdue" value={formatCurrencyCompact(totals.overdue)} meta={totals.overdue > 0 ? 'past the due date' : 'nothing past due'} />
                    <KpiCard label="Collected" value={formatCurrencyCompact(totals.paid)} meta="payments recorded" />
                </div>

                <div className="cgo-filterbar">
                    {STATUS_FILTERS.map((f) => (
                        <button
                            key={f.value || 'all'}
                            type="button"
                            className={`cgo-chip max-sm:min-h-10${(filters.status ?? '') === f.value ? ' active' : ''}`}
                            onClick={() => applyFilters({ status: f.value })}
                        >
                            {f.label}
                        </button>
                    ))}
                    {can_manage && tenants.length > 0 && (
                        <div className="cgo-right max-sm:w-full">
                            <label className="cgo-input max-sm:min-h-10 max-sm:flex-1">
                                <select className="max-sm:flex-1" value={filters.tenant_uuid ?? ''} onChange={(e) => applyFilters({ tenant_uuid: e.target.value })} style={{ minWidth: 'min(220px, 55vw)' }}>
                                    <option value="">All tenants</option>
                                    {tenants.map((t) => (
                                        <option key={t.uuid} value={t.uuid}>{t.name}</option>
                                    ))}
                                </select>
                            </label>
                        </div>
                    )}
                </div>

                <div className="cgo-table-wrap" style={{ borderRadius: '0 0 8px 8px', borderTop: 0 }}>
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ minWidth: 170 }}>Invoice</th>
                                {can_manage && <th style={{ minWidth: 160 }}>Tenant</th>}
                                <th style={{ minWidth: 170 }}>Period</th>
                                <th style={{ width: 120 }}>Due</th>
                                {/* GGR and Paid hide on phones; Amount due and Outstanding carry the row. */}
                                <th className="cgo-r hidden md:table-cell" style={{ width: 120 }}>GGR</th>
                                <th className="cgo-r" style={{ width: 120 }}>Amount due</th>
                                <th className="cgo-r hidden md:table-cell" style={{ width: 120 }}>Paid</th>
                                <th className="cgo-r" style={{ width: 120 }}>Outstanding</th>
                                <th style={{ width: 110 }}>Status</th>
                                <th style={{ width: can_manage ? 230 : 90 }} />
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={can_manage ? 10 : 9} style={{ textAlign: 'center', color: 'var(--cg-fg-3)', padding: '32px 0' }}>
                                        {can_manage ? 'No invoices issued yet. Open a tenant’s invoice preview on Tenant Overview and choose Issue invoice.' : 'No invoices yet.'}
                                    </td>
                                </tr>
                            ) : (
                                rows.map((r) => (
                                    <tr key={r.id} style={r.status === 'void' ? { opacity: 0.55 } : undefined}>
                                        <td>
                                            <div className="cgo-name" style={{ fontFamily: 'var(--cg-mono)' }}>{r.number}</div>
                                            <div className="cgo-uid">issued {fmtDate(r.issued_at)}</div>
                                        </td>
                                        {can_manage && <td><div className="cgo-name">{r.tenant_name ?? '—'}</div></td>}
                                        <td className="cgo-mono" style={{ whiteSpace: 'nowrap' }}>{fmtDate(r.period_from)} – {fmtDate(r.period_to)}</td>
                                        <td className="cgo-mono" style={{ whiteSpace: 'nowrap', ...(r.overdue ? { color: 'var(--cg-neg)' } : {}) }}>{fmtDate(r.due_at)}</td>
                                        <td className="cgo-r cgo-mono hidden md:table-cell">{formatNAD(r.ggr)}</td>
                                        <td className="cgo-r cgo-mono">{formatNAD(r.amount_due)}</td>
                                        <td className="cgo-r cgo-mono hidden md:table-cell">{formatNAD(r.amount_paid)}</td>
                                        <td className="cgo-r cgo-mono" style={{ fontWeight: r.outstanding > 0 && r.status !== 'void' ? 600 : undefined }}>
                                            {r.status === 'void' ? '—' : formatNAD(r.outstanding)}
                                        </td>
                                        <td><span className={`cgo-pill ${pillClass(r)}`}>{pillLabel(r)}</span></td>
                                        <td>
                                            <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                                                <a href={`/invoices/${r.number}`} target="_blank" rel="noopener" className="cg-btn cg-btn--ghost cg-btn--sm max-sm:min-h-10">View</a>
                                                {can_manage && r.status !== 'paid' && r.status !== 'void' && (
                                                    <button type="button" className="cg-btn cg-btn--primary cg-btn--sm max-sm:min-h-10" onClick={() => openPayment(r)}>Record payment</button>
                                                )}
                                                {can_manage && r.status === 'issued' && r.amount_paid === 0 && (
                                                    <button type="button" className="cg-btn cg-btn--ghost cg-btn--sm max-sm:min-h-10" onClick={() => { voidForm.setData('reason', ''); setVoiding(r); }}>Void</button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            <Dialog
                header={paying ? `Record payment · ${paying.number}` : 'Record payment'}
                visible={paying !== null}
                className="cgo-dialog"
                style={{ width: '30rem', maxWidth: '96vw' }}
                onHide={() => setPaying(null)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <button type="button" className="cg-btn cg-btn--ghost cg-btn--sm max-sm:min-h-10" onClick={() => setPaying(null)}>Cancel</button>
                        <button type="submit" form="payment-form" className="cg-btn cg-btn--primary cg-btn--sm max-sm:min-h-10" disabled={payment.processing}>
                            {payment.processing ? 'Saving…' : 'Record payment'}
                        </button>
                    </div>
                }
            >
                {paying && (
                    <form id="payment-form" onSubmit={submitPayment}>
                        <div style={{ fontSize: 12, color: 'var(--cg-fg-3)', marginBottom: 14 }}>
                            {paying.tenant_name} · outstanding <b style={{ color: 'var(--cg-fg-1)', fontFamily: 'var(--cg-mono)' }}>{formatNAD(paying.outstanding)}</b>
                        </div>
                        <div className="cgo-field-grid">
                            <div className="cgo-field">
                                <label className="cgo-field-label" htmlFor="pay-amount">{label('Amount')}</label>
                                <span className="cgo-input max-sm:min-h-10"><input id="pay-amount" type="number" step="0.01" min="0.01" max={paying.outstanding} value={payment.data.amount} onChange={(e) => payment.setData('amount', e.target.value)} required /></span>
                                {payment.errors.amount && <div style={{ fontSize: 11, color: 'var(--cg-neg)', marginTop: 4 }}>{payment.errors.amount}</div>}
                            </div>
                            <div className="cgo-field">
                                <label className="cgo-field-label" htmlFor="pay-date">{label('Paid on')}</label>
                                <span className="cgo-input max-sm:min-h-10"><input id="pay-date" type="date" max={today} value={payment.data.paid_at} onChange={(e) => payment.setData('paid_at', e.target.value)} required /></span>
                                {payment.errors.paid_at && <div style={{ fontSize: 11, color: 'var(--cg-neg)', marginTop: 4 }}>{payment.errors.paid_at}</div>}
                            </div>
                            <div className="cgo-field">
                                <label className="cgo-field-label" htmlFor="pay-method">{label('Method')}</label>
                                <span className="cgo-input max-sm:min-h-10">
                                    <select id="pay-method" value={payment.data.method} onChange={(e) => payment.setData('method', e.target.value)}>
                                        {methods.map((m) => (
                                            <option key={m} value={m}>{METHOD_LABELS[m] ?? m}</option>
                                        ))}
                                    </select>
                                </span>
                            </div>
                            <div className="cgo-field">
                                <label className="cgo-field-label" htmlFor="pay-ref">{label('Reference')}</label>
                                <span className="cgo-input max-sm:min-h-10"><input id="pay-ref" type="text" value={payment.data.reference} onChange={(e) => payment.setData('reference', e.target.value)} placeholder="Bank reference" /></span>
                            </div>
                            <div className="cgo-field" style={{ gridColumn: '1 / -1' }}>
                                <label className="cgo-field-label" htmlFor="pay-note">{label('Note')}</label>
                                <span className="cgo-input max-sm:min-h-10"><input id="pay-note" type="text" value={payment.data.note} onChange={(e) => payment.setData('note', e.target.value)} /></span>
                            </div>
                        </div>
                    </form>
                )}
            </Dialog>

            <Dialog
                header={voiding ? `Void · ${voiding.number}` : 'Void invoice'}
                visible={voiding !== null}
                className="cgo-dialog"
                style={{ width: '26rem', maxWidth: '96vw' }}
                onHide={() => setVoiding(null)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <button type="button" className="cg-btn cg-btn--ghost cg-btn--sm max-sm:min-h-10" onClick={() => setVoiding(null)}>Cancel</button>
                        <button type="submit" form="void-form" className="cg-btn cg-btn--primary cg-btn--sm max-sm:min-h-10" disabled={voidForm.processing}>Void invoice</button>
                    </div>
                }
            >
                {voiding && (
                    <form id="void-form" onSubmit={submitVoid}>
                        <div style={{ fontSize: 13, lineHeight: 1.6, color: 'var(--cg-fg-2)', marginBottom: 14 }}>
                            The invoice stays on file marked void and nothing can be paid against it. A fresh invoice for the same period can then be issued.
                        </div>
                        <div className="cgo-field">
                            <label className="cgo-field-label" htmlFor="void-reason">{label('Reason')}</label>
                            <span className="cgo-input max-sm:min-h-10"><input id="void-reason" type="text" value={voidForm.data.reason} onChange={(e) => voidForm.setData('reason', e.target.value)} placeholder="Optional" /></span>
                            {voidForm.errors.reason && <div style={{ fontSize: 11, color: 'var(--cg-neg)', marginTop: 4 }}>{voidForm.errors.reason}</div>}
                        </div>
                    </form>
                )}
            </Dialog>
        </UserLayout>
    );
}
