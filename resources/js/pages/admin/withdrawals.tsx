import PageHeader from '@/components/acumatica/Common/PageHeader';
import StatusBadge from '@/components/acumatica/Common/StatusBadge';
import UserLayout from '@/layouts/user-layout';
import type { StatusVariant } from '@/types/acumatica';
import { Head } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Column } from 'primereact/column';
import { DataTable } from 'primereact/datatable';
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

const STATUS_VARIANTS: Record<Status, StatusVariant> = {
    requested: 'pending',
    approved: 'active',
    paid: 'active',
    rejected: 'error',
    cancelled: 'inactive',
};

function fmt(value: string | number): string {
    const n = typeof value === 'string' ? parseFloat(value) : value;
    return Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function getCsrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function methodLabel(method: Withdrawal['payment_method']): string {
    return {
        bank_transfer: 'Bank Transfer',
        venue_cash: 'Venue Cash',
        mobile_money: 'Mobile Money',
        voucher: 'Voucher',
    }[method];
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

    const tabButton = (label: string, value: Status | 'all', countKey?: string) => {
        const active = filterStatus === value;
        const count = countKey ? counts[countKey] : undefined;
        return (
            <button
                key={value}
                onClick={() => setFilterStatus(value)}
                className="px-3 py-1.5 rounded text-sm font-medium transition-colors"
                style={{
                    background: active ? 'var(--acu-primary)' : 'var(--acu-surface-card)',
                    color: active ? 'var(--acu-on-primary)' : 'var(--acu-text)',
                    border: `1px solid ${active ? 'var(--acu-primary)' : 'var(--acu-border)'}`,
                }}
            >
                {label}
                {count !== undefined && count > 0 && (
                    <span className="ml-1.5 text-xs" style={{ opacity: 0.8 }}>({count})</span>
                )}
            </button>
        );
    };

    return (
        <UserLayout title="Withdrawals">
            <Head title="Withdrawals" />
            <Toast ref={toast} />

            <div className="space-y-5">
                <PageHeader title="Withdrawals" subtitle="Player payout requests — review, approve, and mark as paid." />

                <div className="flex flex-wrap gap-2">
                    {tabButton('Pending', 'requested', 'requested')}
                    {tabButton('Approved', 'approved', 'approved')}
                    {tabButton('Paid', 'paid', 'paid')}
                    {tabButton('Rejected', 'rejected', 'rejected')}
                    {tabButton('Cancelled', 'cancelled', 'cancelled')}
                    {tabButton('All', 'all')}
                </div>

                <div className="acu-fieldset">
                    <div className="acu-fieldset-body p-0">
                        <DataTable
                            value={withdrawals}
                            loading={loading}
                            size="small"
                            showGridlines={false}
                            emptyMessage="No withdrawals match the current filter."
                            dataKey="uuid"
                        >
                            <Column
                                header="Player"
                                body={(row: Withdrawal) => row.user ? (
                                    <div>
                                        <div className="font-medium text-sm">{row.user.name}</div>
                                        <div className="text-xs" style={{ color: 'var(--acu-text-light)' }}>{row.user.email}</div>
                                    </div>
                                ) : '—'}
                            />
                            <Column header="Tenant" body={(row: Withdrawal) => row.tenant?.name ?? '—'} />
                            <Column
                                header="Amount"
                                body={(row: Withdrawal) => (
                                    <div className="text-right">
                                        <div className="font-medium">{row.currency} {fmt(row.amount)}</div>
                                        {parseFloat(row.fee_amount) > 0 && (
                                            <div className="text-xs" style={{ color: 'var(--acu-text-light)' }}>
                                                fee {fmt(row.fee_amount)} → net {fmt(row.net_amount)}
                                            </div>
                                        )}
                                    </div>
                                )}
                            />
                            <Column header="Method" body={(row: Withdrawal) => methodLabel(row.payment_method)} />
                            <Column
                                header="Status"
                                body={(row: Withdrawal) => <StatusBadge status={STATUS_VARIANTS[row.status]} label={row.status} />}
                                style={{ width: '7rem' }}
                            />
                            <Column
                                header="Requested"
                                body={(row: Withdrawal) => (
                                    <span className="text-xs" style={{ color: 'var(--acu-text-muted)' }}>
                                        {new Date(row.created_at).toLocaleString()}
                                    </span>
                                )}
                            />
                            <Column
                                header="Actions"
                                body={(row: Withdrawal) => (
                                    <div className="flex gap-1 flex-wrap">
                                        <Button
                                            icon="pi pi-eye"
                                            size="small"
                                            text
                                            severity="secondary"
                                            onClick={() => openDialog(row, 'view')}
                                            tooltip="View"
                                        />
                                        {row.status === 'requested' && (
                                            <>
                                                <Button
                                                    icon="pi pi-check"
                                                    label="Approve"
                                                    size="small"
                                                    severity="success"
                                                    text
                                                    onClick={() => openDialog(row, 'approve')}
                                                />
                                                <Button
                                                    icon="pi pi-times"
                                                    label="Reject"
                                                    size="small"
                                                    severity="danger"
                                                    text
                                                    onClick={() => openDialog(row, 'reject')}
                                                />
                                            </>
                                        )}
                                        {row.status === 'approved' && (
                                            <>
                                                <Button
                                                    icon="pi pi-money-bill"
                                                    label="Mark Paid"
                                                    size="small"
                                                    severity="success"
                                                    onClick={() => openDialog(row, 'pay')}
                                                />
                                                <Button
                                                    icon="pi pi-times"
                                                    label="Reject"
                                                    size="small"
                                                    severity="danger"
                                                    text
                                                    onClick={() => openDialog(row, 'reject')}
                                                />
                                            </>
                                        )}
                                    </div>
                                )}
                                style={{ minWidth: '14rem' }}
                            />
                        </DataTable>
                    </div>
                </div>
            </div>

            {/* Detail / action dialog */}
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
                    <div className="space-y-3 text-sm">
                        <div className="rounded-lg p-3 space-y-1" style={{ background: 'var(--acu-surface-elevated)', border: '1px solid var(--acu-border)' }}>
                            <div><strong>Player:</strong> {selected.user?.name} ({selected.user?.email})</div>
                            <div><strong>Tenant:</strong> {selected.tenant?.name ?? '—'}</div>
                            <div><strong>Amount:</strong> {selected.currency} {fmt(selected.amount)}{parseFloat(selected.fee_amount) > 0 && <> — fee {fmt(selected.fee_amount)} → net {fmt(selected.net_amount)}</>}</div>
                            <div><strong>Method:</strong> {methodLabel(selected.payment_method)}</div>
                            {selected.payment_details && Object.keys(selected.payment_details).length > 0 && (
                                <div>
                                    <strong>Details:</strong>
                                    <pre className="text-xs mt-1 p-2 rounded" style={{ background: 'var(--acu-surface-card)' }}>
                                        {JSON.stringify(selected.payment_details, null, 2)}
                                    </pre>
                                </div>
                            )}
                            <div><strong>Status:</strong> <StatusBadge status={STATUS_VARIANTS[selected.status]} label={selected.status} /></div>
                            {selected.rejection_reason && (
                                <div><strong>Rejection reason:</strong> {selected.rejection_reason}</div>
                            )}
                            {selected.external_reference && (
                                <div><strong>Payment reference:</strong> <code>{selected.external_reference}</code></div>
                            )}
                            {selected.reviewed_at && (
                                <div className="text-xs" style={{ color: 'var(--acu-text-light)' }}>
                                    Reviewed {new Date(selected.reviewed_at).toLocaleString()} by {selected.reviewed_by ?? '—'}
                                </div>
                            )}
                            {selected.paid_at && (
                                <div className="text-xs" style={{ color: 'var(--acu-text-light)' }}>
                                    Paid {new Date(selected.paid_at).toLocaleString()} by {selected.paid_by ?? '—'}
                                </div>
                            )}
                        </div>

                        {dialogMode === 'approve' && (
                            <div className="space-y-2">
                                <label className="block text-sm font-medium">Notes (optional)</label>
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
                                        icon="pi pi-check"
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
                                <label className="block text-sm font-medium">Rejection reason *</label>
                                <InputTextarea
                                    value={reasonInput}
                                    onChange={(e) => setReasonInput(e.target.value)}
                                    rows={3}
                                    className="w-full"
                                    placeholder="Visible to the player"
                                />
                                <p className="text-xs" style={{ color: 'var(--acu-text-light)' }}>
                                    The player's wallet will be refunded automatically.
                                </p>
                                <div className="flex justify-end gap-2 pt-2">
                                    <Button label="Cancel" severity="secondary" outlined onClick={closeDialog} disabled={acting} />
                                    <Button
                                        label={acting ? 'Rejecting…' : 'Reject + Refund'}
                                        icon="pi pi-times"
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
                                <label className="block text-sm font-medium">Payment reference *</label>
                                <InputText
                                    value={referenceInput}
                                    onChange={(e) => setReferenceInput(e.target.value)}
                                    className="w-full"
                                    placeholder="Bank reference, voucher code, or receipt #"
                                />
                                <p className="text-xs" style={{ color: 'var(--acu-text-light)' }}>
                                    The player's wallet was already debited at request time. This step records the actual payout.
                                </p>
                                <div className="flex justify-end gap-2 pt-2">
                                    <Button label="Cancel" severity="secondary" outlined onClick={closeDialog} disabled={acting} />
                                    <Button
                                        label={acting ? 'Saving…' : 'Mark as Paid'}
                                        icon="pi pi-money-bill"
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
