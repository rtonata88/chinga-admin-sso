// resources/js/pages/admin/withdrawals.tsx
//
// Player withdrawal queue, brass-on-ink. KPI strip → status chips
// (with live counts) → table → action dialog (PrimeReact, kept for
// the multi-mode form state).

import { KpiCard, formatCount } from '@/components/operator/kpi-card';
import UserLayout from '@/layouts/user-layout';
import { Head } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { InputText } from 'primereact/inputtext';
import { InputTextarea } from 'primereact/inputtextarea';
import { Toast } from 'primereact/toast';
import { useEffect, useRef, useState } from 'react';

type Status = 'requested' | 'approved' | 'paid' | 'rejected' | 'cancelled';

interface Withdrawal {
    uuid: string;
    tenant: { uuid: string; name: string; slug: string } | null;
    user: { uuid: string; name: string; email: string; username: string | null } | null;
    amount: string;
    fee_amount: string;
    net_amount: string;
    currency: string;
    payment_method: 'bank_transfer' | 'venue_cash' | 'mobile_money' | 'voucher';
    payment_details: Record<string, unknown> | null;
    status: Status;
    external_reference: string | null;
    rejection_reason: string | null;
    reviewed_by: string | null;
    reviewed_at: string | null;
    paid_by: string | null;
    paid_at: string | null;
    created_at: string;
}

const STATUS_PILL: Record<Status, string> = {
    requested: 'pending',
    approved: 'live',
    paid: 'settled',
    rejected: 'flagged',
    cancelled: 'void',
};

const FILTERS: { label: string; value: Status | 'all'; countKey?: Status; danger?: boolean }[] = [
    { label: 'Pending', value: 'requested', countKey: 'requested', danger: true },
    { label: 'Approved', value: 'approved', countKey: 'approved' },
    { label: 'Paid', value: 'paid', countKey: 'paid' },
    { label: 'Rejected', value: 'rejected', countKey: 'rejected' },
    { label: 'Cancelled', value: 'cancelled', countKey: 'cancelled' },
    { label: 'All', value: 'all' },
];

function methodLabel(method: Withdrawal['payment_method']): string {
    return {
        bank_transfer: 'Bank transfer',
        venue_cash: 'Venue cash',
        mobile_money: 'Mobile money',
        voucher: 'Voucher',
    }[method];
}

function fmt(value: string | number): string {
    const n = typeof value === 'string' ? parseFloat(value) : value;
    return Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

const DATETIME_FMT = new Intl.DateTimeFormat('en-GB', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
});

function formatDateTime(iso: string): string {
    return DATETIME_FMT.format(new Date(iso));
}

function getCsrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

export default function Withdrawals() {
    const [withdrawals, setWithdrawals] = useState<Withdrawal[]>([]);
    const [counts, setCounts] = useState<Record<string, number>>({});
    const [loading, setLoading] = useState(true);
    const [filterStatus, setFilterStatus] = useState<Status | 'all'>('requested');
    const [selected, setSelected] = useState<Withdrawal | null>(null);
    const [dialogMode, setDialogMode] = useState<'view' | 'approve' | 'reject' | 'pay' | null>(null);
    const [reasonInput, setReasonInput] = useState('');
    const [referenceInput, setReferenceInput] = useState('');
    const [notesInput, setNotesInput] = useState('');
    const [acting, setActing] = useState(false);
    const toast = useRef<Toast>(null);

    const fetchData = async (status: Status | 'all' = filterStatus) => {
        setLoading(true);
        try {
            const url = status === 'all'
                ? '/api/v1/admin/withdrawals'
                : `/api/v1/admin/withdrawals?status=${status}`;
            const r = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await r.json();
            setWithdrawals(data.data ?? []);
            setCounts(data.counts ?? {});
        } catch {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to load withdrawals.' });
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        void fetchData(filterStatus);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [filterStatus]);

    const openDialog = (w: Withdrawal, mode: 'view' | 'approve' | 'reject' | 'pay') => {
        setSelected(w);
        setDialogMode(mode);
        setReasonInput('');
        setReferenceInput('');
        setNotesInput('');
    };

    const closeDialog = () => {
        setSelected(null);
        setDialogMode(null);
    };

    const act = async (action: 'approve' | 'reject' | 'mark-paid', body: Record<string, unknown> = {}) => {
        if (!selected) return;
        setActing(true);
        try {
            const r = await fetch(`/api/v1/admin/withdrawals/${selected.uuid}/${action}`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });
            const data = await r.json();
            if (r.ok) {
                toast.current?.show({ severity: 'success', summary: 'Done', detail: `Withdrawal ${action}.` });
                closeDialog();
                await fetchData(filterStatus);
            } else {
                toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || 'Action failed.' });
            }
        } catch {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Action failed.' });
        } finally {
            setActing(false);
        }
    };

    return (
        <UserLayout title="Withdrawals">
            <Head title="Withdrawals · Admin" />
            <Toast ref={toast} />

            <div className="cgo-page">
                {/* Page header */}
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Admin</div>
                        <h1 className="cgo-title">Withdrawals</h1>
                        <div className="cgo-subtitle">
                            Player payout requests — review, approve, and mark as paid.
                        </div>
                    </div>
                    <div style={{ display: 'flex', gap: 8 }}>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={() => void fetchData(filterStatus)}
                        >
                            Refresh
                        </button>
                    </div>
                </div>

                {/* KPI strip — workflow stages. */}
                <div className="cgo-kpis">
                    <KpiCard
                        label="Pending review"
                        value={formatCount(counts.requested ?? 0)}
                        meta={counts.requested ? 'awaiting decision' : 'all clear'}
                    />
                    <KpiCard
                        label="Approved"
                        value={formatCount(counts.approved ?? 0)}
                        meta="ready to pay"
                    />
                    <KpiCard
                        label="Paid"
                        value={formatCount(counts.paid ?? 0)}
                        brass
                        meta="terminal · completed"
                    />
                    <KpiCard
                        label="Rejected"
                        value={formatCount(counts.rejected ?? 0)}
                        meta="refunded"
                    />
                </div>

                {/* Filter bar — chips with counts. */}
                <div className="cgo-filterbar">
                    {FILTERS.map((f) => {
                        const count = f.countKey ? counts[f.countKey] : undefined;
                        return (
                            <button
                                key={f.value}
                                type="button"
                                className={`cgo-chip${filterStatus === f.value ? ' active' : ''}`}
                                onClick={() => setFilterStatus(f.value)}
                            >
                                {f.label}
                                {count !== undefined && count > 0 && (
                                    <span className={`cgo-chip-count${f.danger ? ' danger' : ''}`}>
                                        {count}
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>

                {/* Withdrawals table */}
                <div
                    className="cgo-table-wrap cgo-table-wrap--scroll"
                    style={{ borderRadius: '0 0 8px 8px', borderTop: 0 }}
                >
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ minWidth: 220 }}>Player</th>
                                <th style={{ width: 160 }}>Tenant</th>
                                <th className="cgo-r" style={{ width: 160 }}>Amount</th>
                                <th style={{ width: 130 }}>Method</th>
                                <th style={{ width: 100 }}>Status</th>
                                <th style={{ width: 150 }}>Requested</th>
                                <th style={{ width: 240 }} />
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan={7} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        Loading…
                                    </td>
                                </tr>
                            ) : withdrawals.length === 0 ? (
                                <tr>
                                    <td colSpan={7} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        No withdrawals match the current filter.
                                    </td>
                                </tr>
                            ) : (
                                withdrawals.map((w) => {
                                    const hasFee = parseFloat(w.fee_amount) > 0;
                                    return (
                                        <tr key={w.uuid}>
                                            <td style={{ maxWidth: 280 }}>
                                                <div className="cgo-name cgo-cell-clip" title={w.user?.name || ''}>
                                                    {w.user?.name || '—'}
                                                </div>
                                                <div className="cgo-uid">{w.user?.email || '—'}</div>
                                            </td>
                                            <td>
                                                <span style={{ fontSize: 12, color: 'var(--cg-fg-2)' }}>
                                                    {w.tenant?.name || '—'}
                                                </span>
                                            </td>
                                            <td className="cgo-r">
                                                <span className="cgo-stake">
                                                    <span className="cgo-ccy">{w.currency}</span>
                                                    {fmt(w.amount)}
                                                </span>
                                                {hasFee && (
                                                    <div className="cgo-uid" style={{ marginTop: 2 }}>
                                                        fee {fmt(w.fee_amount)} → net {fmt(w.net_amount)}
                                                    </div>
                                                )}
                                            </td>
                                            <td>
                                                <span style={{ fontSize: 12, color: 'var(--cg-fg-2)' }}>
                                                    {methodLabel(w.payment_method)}
                                                </span>
                                            </td>
                                            <td>
                                                <span className={`cgo-pill ${STATUS_PILL[w.status]}`}>
                                                    {w.status}
                                                </span>
                                            </td>
                                            <td>
                                                <span className="cgo-uid">{formatDateTime(w.created_at)}</span>
                                            </td>
                                            <td>
                                                <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end', flexWrap: 'wrap' }}>
                                                    <button
                                                        type="button"
                                                        className="cg-btn cg-btn--text cg-btn--sm"
                                                        onClick={() => openDialog(w, 'view')}
                                                    >
                                                        View
                                                    </button>
                                                    {w.status === 'requested' && (
                                                        <>
                                                            <button
                                                                type="button"
                                                                className="cg-btn cg-btn--ghost cg-btn--sm"
                                                                onClick={() => openDialog(w, 'approve')}
                                                            >
                                                                Approve
                                                            </button>
                                                            <button
                                                                type="button"
                                                                className="cg-btn cg-btn--ghost cg-btn--sm"
                                                                style={{ borderColor: 'var(--cg-neg)', color: 'var(--cg-neg)' }}
                                                                onClick={() => openDialog(w, 'reject')}
                                                            >
                                                                Reject
                                                            </button>
                                                        </>
                                                    )}
                                                    {w.status === 'approved' && (
                                                        <>
                                                            <button
                                                                type="button"
                                                                className="cg-btn cg-btn--primary cg-btn--sm"
                                                                onClick={() => openDialog(w, 'pay')}
                                                            >
                                                                Mark paid
                                                            </button>
                                                            <button
                                                                type="button"
                                                                className="cg-btn cg-btn--ghost cg-btn--sm"
                                                                style={{ borderColor: 'var(--cg-neg)', color: 'var(--cg-neg)' }}
                                                                onClick={() => openDialog(w, 'reject')}
                                                            >
                                                                Reject
                                                            </button>
                                                        </>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Detail / action dialog (PrimeReact — multi-mode form) */}
            <Dialog
                header={
                    dialogMode === 'approve' ? 'Approve withdrawal' :
                    dialogMode === 'reject' ? 'Reject withdrawal' :
                    dialogMode === 'pay' ? 'Mark as paid' :
                    'Withdrawal detail'
                }
                visible={!!selected && !!dialogMode}
                style={{ width: '32rem' }}
                onHide={closeDialog}
                modal
                draggable={false}
            >
                {selected && (
                    <div className="space-y-3" style={{ fontSize: 13 }}>
                        <div
                            style={{
                                background: 'var(--cg-ink-elevated)',
                                border: '1px solid var(--cg-rule)',
                                borderRadius: 6,
                                padding: 12,
                                display: 'grid',
                                gap: 6,
                            }}
                        >
                            <div>
                                <strong>Player:</strong> {selected.user?.name} <span style={{ color: 'var(--cg-fg-3)' }}>({selected.user?.email})</span>
                            </div>
                            <div>
                                <strong>Tenant:</strong> {selected.tenant?.name ?? '—'}
                            </div>
                            <div>
                                <strong>Amount:</strong>{' '}
                                <span style={{ fontFamily: 'var(--cg-mono)', color: 'var(--cg-brass-hi)' }}>
                                    {selected.currency} {fmt(selected.amount)}
                                </span>
                                {parseFloat(selected.fee_amount) > 0 && (
                                    <span style={{ color: 'var(--cg-fg-3)' }}>
                                        {' '}— fee {fmt(selected.fee_amount)} → net {fmt(selected.net_amount)}
                                    </span>
                                )}
                            </div>
                            <div><strong>Method:</strong> {methodLabel(selected.payment_method)}</div>
                            {selected.payment_details && Object.keys(selected.payment_details).length > 0 && (
                                <div>
                                    <strong>Details:</strong>
                                    <pre
                                        style={{
                                            fontSize: 11,
                                            marginTop: 4,
                                            padding: 8,
                                            background: 'var(--cg-ink-card)',
                                            border: '1px solid var(--cg-rule)',
                                            borderRadius: 4,
                                            color: 'var(--cg-fg-2)',
                                            fontFamily: 'var(--cg-mono)',
                                            overflow: 'auto',
                                        }}
                                    >
                                        {JSON.stringify(selected.payment_details, null, 2)}
                                    </pre>
                                </div>
                            )}
                            <div>
                                <strong>Status:</strong>{' '}
                                <span className={`cgo-pill ${STATUS_PILL[selected.status]}`}>
                                    {selected.status}
                                </span>
                            </div>
                            {selected.rejection_reason && (
                                <div><strong>Rejection reason:</strong> {selected.rejection_reason}</div>
                            )}
                            {selected.external_reference && (
                                <div>
                                    <strong>Payment reference:</strong>{' '}
                                    <code style={{ fontFamily: 'var(--cg-mono)', color: 'var(--cg-fg-2)' }}>
                                        {selected.external_reference}
                                    </code>
                                </div>
                            )}
                            {selected.reviewed_at && (
                                <div style={{ fontSize: 11, color: 'var(--cg-fg-3)' }}>
                                    Reviewed {formatDateTime(selected.reviewed_at)} by {selected.reviewed_by ?? '—'}
                                </div>
                            )}
                            {selected.paid_at && (
                                <div style={{ fontSize: 11, color: 'var(--cg-fg-3)' }}>
                                    Paid {formatDateTime(selected.paid_at)} by {selected.paid_by ?? '—'}
                                </div>
                            )}
                        </div>

                        {dialogMode === 'approve' && (
                            <div className="space-y-2">
                                <label style={{ fontSize: 12, fontWeight: 500 }}>Notes (optional)</label>
                                <InputTextarea
                                    value={notesInput}
                                    onChange={(e) => setNotesInput(e.target.value)}
                                    rows={2}
                                    className="w-full"
                                    placeholder="Internal notes for the audit trail"
                                />
                                <div className="flex justify-end gap-2 pt-2">
                                    <Button label="Cancel" severity="secondary" outlined onClick={closeDialog} disabled={acting} />
                                    <Button
                                        label={acting ? 'Approving…' : 'Approve'}
                                        severity="success"
                                        onClick={() => void act('approve', { notes: notesInput || null })}
                                        loading={acting}
                                        disabled={acting}
                                    />
                                </div>
                            </div>
                        )}

                        {dialogMode === 'reject' && (
                            <div className="space-y-2">
                                <label style={{ fontSize: 12, fontWeight: 500 }}>Rejection reason *</label>
                                <InputTextarea
                                    value={reasonInput}
                                    onChange={(e) => setReasonInput(e.target.value)}
                                    rows={3}
                                    className="w-full"
                                    placeholder="Visible to the player"
                                />
                                <p style={{ fontSize: 11, color: 'var(--cg-fg-3)' }}>
                                    The player's wallet will be refunded automatically.
                                </p>
                                <div className="flex justify-end gap-2 pt-2">
                                    <Button label="Cancel" severity="secondary" outlined onClick={closeDialog} disabled={acting} />
                                    <Button
                                        label={acting ? 'Rejecting…' : 'Reject + Refund'}
                                        severity="danger"
                                        onClick={() => void act('reject', { reason: reasonInput })}
                                        loading={acting}
                                        disabled={acting || !reasonInput.trim()}
                                    />
                                </div>
                            </div>
                        )}

                        {dialogMode === 'pay' && (
                            <div className="space-y-2">
                                <label style={{ fontSize: 12, fontWeight: 500 }}>Payment reference *</label>
                                <InputText
                                    value={referenceInput}
                                    onChange={(e) => setReferenceInput(e.target.value)}
                                    className="w-full"
                                    placeholder="Bank reference, voucher code, or receipt #"
                                />
                                <p style={{ fontSize: 11, color: 'var(--cg-fg-3)' }}>
                                    The player's wallet was already debited at request time. This step records the actual payout.
                                </p>
                                <div className="flex justify-end gap-2 pt-2">
                                    <Button label="Cancel" severity="secondary" outlined onClick={closeDialog} disabled={acting} />
                                    <Button
                                        label={acting ? 'Saving…' : 'Mark as Paid'}
                                        severity="success"
                                        onClick={() => void act('mark-paid', { external_reference: referenceInput || null })}
                                        loading={acting}
                                        disabled={acting}
                                    />
                                </div>
                            </div>
                        )}

                        {dialogMode === 'view' && (
                            <div className="flex justify-end pt-2">
                                <Button label="Close" severity="secondary" onClick={closeDialog} />
                            </div>
                        )}
                    </div>
                )}
            </Dialog>
        </UserLayout>
    );
}
