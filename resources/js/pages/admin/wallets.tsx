// resources/js/pages/admin/wallets.tsx
//
// Wallet management, brass-on-ink to match /tenant-overview. KPI
// strip → filter bar → wallets table. Deposit / withdraw stay on
// PrimeReact Dialog (functional modals with form state); the Toast
// is also kept.

import { KpiCard, formatCount, formatCurrencyCompact, formatNAD } from '@/components/operator/kpi-card';
import UserLayout from '@/layouts/user-layout';
import { Head } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { InputText } from 'primereact/inputtext';
import { InputTextarea } from 'primereact/inputtextarea';
import { Toast } from 'primereact/toast';
import { useEffect, useRef, useState } from 'react';

interface WalletUser {
    uuid: string;
    name: string;
    email: string;
}

interface WalletTenant {
    uuid: string;
    slug: string;
    name: string;
}

interface Wallet {
    id: number;
    uuid: string;
    user: WalletUser;
    /** The operator the wallet (and its account) belongs to; the same email can hold one under several. */
    tenant?: WalletTenant | null;
    balance: string;
    currency: string;
    status: string;
    total_deposited: string;
    total_withdrawn: string;
    created_at: string;
    updated_at: string;
}

interface Meta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Stats {
    total_wallets: number;
    active_wallets: number;
    frozen_wallets: number;
    closed_wallets: number;
    total_balance: number;
}

const STATUS_FILTERS = [
    { label: 'All', value: '' },
    { label: 'Active', value: 'active' },
    { label: 'Frozen', value: 'frozen' },
    { label: 'Closed', value: 'closed' },
];

function statusPill(status: string): string {
    switch (status) {
        case 'active': return 'live';
        case 'frozen': return 'pending';
        case 'closed': return 'void';
        default: return 'void';
    }
}

export default function Wallets() {
    const toast = useRef<Toast>(null);
    const [wallets, setWallets] = useState<Wallet[]>([]);
    const [meta, setMeta] = useState<Meta | null>(null);
    const [stats, setStats] = useState<Stats | null>(null);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [page, setPage] = useState(1);

    const [depositOpen, setDepositOpen] = useState(false);
    const [depositWallet, setDepositWallet] = useState<Wallet | null>(null);
    const [depositAmount, setDepositAmount] = useState('');
    const [depositReference, setDepositReference] = useState('');
    const [depositLoading, setDepositLoading] = useState(false);

    const [withdrawOpen, setWithdrawOpen] = useState(false);
    const [withdrawWallet, setWithdrawWallet] = useState<Wallet | null>(null);
    const [withdrawAmount, setWithdrawAmount] = useState('');
    const [withdrawReference, setWithdrawReference] = useState('');
    const [withdrawLoading, setWithdrawLoading] = useState(false);

    const getCsrfToken = () =>
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const fetchWallets = async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams();
            if (search) params.append('search', search);
            if (statusFilter) params.append('status', statusFilter);
            params.append('page', page.toString());

            const response = await fetch(`/api/v1/admin/wallets?${params}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            if (data.success) {
                setWallets(data.data);
                setMeta(data.meta);
                if (data.stats) setStats(data.stats);
            }
        } catch (error) {
            console.error('Failed to fetch wallets:', error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchWallets();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [page, statusFilter]);

    const submitSearch = () => { setPage(1); fetchWallets(); };

    const openDeposit = (wallet: Wallet) => {
        setDepositWallet(wallet); setDepositAmount(''); setDepositReference(''); setDepositOpen(true);
    };
    const openWithdraw = (wallet: Wallet) => {
        setWithdrawWallet(wallet); setWithdrawAmount(''); setWithdrawReference(''); setWithdrawOpen(true);
    };

    const handleDeposit = async () => {
        if (!depositWallet || !depositAmount) return;
        setDepositLoading(true);
        try {
            const response = await fetch(`/api/v1/admin/wallets/${depositWallet.uuid}/deposit`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
                body: JSON.stringify({ amount: parseFloat(depositAmount), reference: depositReference || undefined }),
            });
            const data = await response.json();
            if (data.success) {
                toast.current?.show({ severity: 'success', summary: 'Success', detail: 'Deposit successful.' });
                setDepositOpen(false); fetchWallets();
            } else {
                toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || 'Deposit failed.' });
            }
        } catch {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Deposit failed.' });
        } finally {
            setDepositLoading(false);
        }
    };

    const handleWithdraw = async () => {
        if (!withdrawWallet || !withdrawAmount) return;
        setWithdrawLoading(true);
        try {
            const response = await fetch(`/api/v1/admin/wallets/${withdrawWallet.uuid}/withdraw`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
                body: JSON.stringify({ amount: parseFloat(withdrawAmount), reference: withdrawReference || undefined }),
            });
            const data = await response.json();
            if (data.success) {
                toast.current?.show({ severity: 'success', summary: 'Success', detail: 'Withdrawal successful.' });
                setWithdrawOpen(false); fetchWallets();
            } else {
                toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || 'Withdrawal failed.' });
            }
        } catch {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Withdrawal failed.' });
        } finally {
            setWithdrawLoading(false);
        }
    };

    const handleFreeze = async (wallet: Wallet) => {
        if (!confirm(`Freeze wallet for ${wallet.user.name}?`)) return;
        try {
            const response = await fetch(`/api/v1/admin/wallets/${wallet.uuid}/freeze`, {
                method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
            });
            const data = await response.json();
            if (data.success) {
                toast.current?.show({ severity: 'success', summary: 'Frozen', detail: 'Wallet frozen.' });
                fetchWallets();
            } else {
                toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || 'Failed to freeze wallet.' });
            }
        } catch {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to freeze wallet.' });
        }
    };

    const handleActivate = async (wallet: Wallet) => {
        try {
            const response = await fetch(`/api/v1/admin/wallets/${wallet.uuid}/activate`, {
                method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
            });
            const data = await response.json();
            if (data.success) {
                toast.current?.show({ severity: 'success', summary: 'Activated', detail: 'Wallet activated.' });
                fetchWallets();
            } else {
                toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || 'Failed to activate wallet.' });
            }
        } catch {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to activate wallet.' });
        }
    };

    return (
        <UserLayout title="Wallets">
            <Head title="Wallets · Admin" />
            <Toast ref={toast} />

            <div className="cgo-page">
                {/* Page header */}
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Admin</div>
                        <h1 className="cgo-title">Wallets</h1>
                        <div className="cgo-subtitle">
                            Player wallets, balances, deposits and freezes.
                        </div>
                    </div>
                    <div style={{ display: 'flex', gap: 8 }}>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={fetchWallets}
                        >
                            Refresh
                        </button>
                    </div>
                </div>

                {/* KPI strip */}
                <div className="cgo-kpis">
                    <KpiCard
                        label="Total wallets"
                        value={stats ? formatCount(stats.total_wallets) : '—'}
                        meta="all statuses"
                    />
                    <KpiCard
                        label="Total balance"
                        value={stats ? formatCurrencyCompact(stats.total_balance) : '—'}
                        brass
                        meta="across all wallets"
                    />
                    <KpiCard
                        label="Active"
                        value={stats ? formatCount(stats.active_wallets) : '—'}
                        meta="able to transact"
                    />
                    <KpiCard
                        label="Frozen"
                        value={stats ? formatCount(stats.frozen_wallets) : '—'}
                        meta={stats?.frozen_wallets ? 'review' : 'none'}
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
                                placeholder="Search by user name or email…"
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

                {/* Wallets table */}
                <div
                    className="cgo-table-wrap cgo-table-wrap--scroll"
                    style={{ borderRadius: '0 0 8px 8px', borderTop: 0 }}
                >
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ minWidth: 220 }}>User</th>
                                <th style={{ minWidth: 150 }}>Operator</th>
                                <th className="cgo-r" style={{ width: 140 }}>Balance</th>
                                <th style={{ width: 80 }}>Currency</th>
                                <th style={{ width: 110 }}>Status</th>
                                <th className="cgo-r" style={{ width: 140 }}>Total deposited</th>
                                <th className="cgo-r" style={{ width: 140 }}>Total withdrawn</th>
                                <th className="cgo-r" style={{ width: 120 }} />
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan={8} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        Loading…
                                    </td>
                                </tr>
                            ) : wallets.length === 0 ? (
                                <tr>
                                    <td colSpan={7} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        No wallets match the current filters.
                                    </td>
                                </tr>
                            ) : (
                                wallets.map((w) => (
                                    <tr key={w.uuid}>
                                        <td style={{ maxWidth: 280 }}>
                                            <div className="cgo-name cgo-cell-clip" title={w.user?.name}>
                                                {w.user?.name || '—'}
                                            </div>
                                            <div className="cgo-uid">{w.user?.email || '—'}</div>
                                        </td>
                                        <td>
                                            <div className="cgo-name cgo-cell-clip" title={w.tenant?.name}>
                                                {w.tenant?.name || '—'}
                                            </div>
                                            <div className="cgo-uid">{w.tenant?.slug || ''}</div>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-stake" style={{ color: 'var(--cg-brass-hi)' }}>
                                                <span className="cgo-ccy">{w.currency}</span>
                                                {formatNAD(parseFloat(w.balance))}
                                            </span>
                                        </td>
                                        <td>
                                            <span className="cgo-uid">{w.currency}</span>
                                        </td>
                                        <td>
                                            <span className={`cgo-pill ${statusPill(w.status)}`}>
                                                {w.status}
                                            </span>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-payout">
                                                {formatNAD(parseFloat(w.total_deposited))}
                                            </span>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-payout">
                                                {formatNAD(parseFloat(w.total_withdrawn))}
                                            </span>
                                        </td>
                                        <td>
                                            <div style={{ display: 'flex', gap: 4, justifyContent: 'flex-end' }}>
                                                {w.status === 'active' && (
                                                    <>
                                                        <button
                                                            type="button"
                                                            className="cgo-row-action"
                                                            aria-label="Deposit"
                                                            title="Deposit"
                                                            onClick={() => openDeposit(w)}
                                                        >
                                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 5v14M5 12h14" /></svg>
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className="cgo-row-action"
                                                            aria-label="Withdraw"
                                                            title="Withdraw"
                                                            onClick={() => openWithdraw(w)}
                                                        >
                                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M5 12h14" /></svg>
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className="cgo-row-action"
                                                            aria-label="Freeze"
                                                            title="Freeze"
                                                            onClick={() => handleFreeze(w)}
                                                        >
                                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V7a4 4 0 0 1 8 0v4" /></svg>
                                                        </button>
                                                    </>
                                                )}
                                                {w.status === 'frozen' && (
                                                    <button
                                                        type="button"
                                                        className="cgo-row-action"
                                                        aria-label="Activate"
                                                        title="Activate"
                                                        onClick={() => handleActivate(w)}
                                                    >
                                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V7a4 4 0 0 1 8 0" /></svg>
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
                                        Showing <b className="cgo-mono">{wallets.length}</b> of{' '}
                                        <b className="cgo-mono">{meta.total.toLocaleString()}</b> wallets
                                    </>
                                ) : (
                                    'No wallets'
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

            {/* Deposit dialog */}
            <Dialog
                header={`Deposit · ${depositWallet?.user?.name || ''}${depositWallet?.tenant ? ` · ${depositWallet.tenant.name}` : ''}`}
                visible={depositOpen}
                style={{ width: '28rem' }}
                onHide={() => setDepositOpen(false)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button label="Cancel" severity="secondary" outlined onClick={() => setDepositOpen(false)} />
                        <Button
                            label={depositLoading ? 'Processing…' : 'Deposit'}
                            onClick={handleDeposit}
                            disabled={depositLoading || !depositAmount}
                            loading={depositLoading}
                        />
                    </div>
                }
            >
                <div className="space-y-4">
                    {depositWallet && (
                        <p style={{ fontSize: 13 }}>
                            Current balance:{' '}
                            <strong style={{ color: 'var(--cg-brass-hi)', fontFamily: 'var(--cg-mono)' }}>
                                {depositWallet.currency} {formatNAD(parseFloat(depositWallet.balance))}
                            </strong>
                        </p>
                    )}
                    <div className="flex flex-col gap-1">
                        <label style={{ fontSize: 12, fontWeight: 500 }}>
                            Amount ({depositWallet?.currency || 'NAD'})
                        </label>
                        <InputText
                            type="number"
                            min={0.01}
                            step={0.01}
                            value={depositAmount}
                            onChange={(e) => setDepositAmount(e.target.value)}
                            placeholder="0.00"
                            className="w-full"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label style={{ fontSize: 12, fontWeight: 500 }}>Reference / note (optional)</label>
                        <InputTextarea
                            value={depositReference}
                            onChange={(e) => setDepositReference(e.target.value)}
                            placeholder="e.g., Manual deposit by admin"
                            rows={2}
                            className="w-full"
                        />
                    </div>
                </div>
            </Dialog>

            {/* Withdraw dialog */}
            <Dialog
                header={`Withdraw · ${withdrawWallet?.user?.name || ''}${withdrawWallet?.tenant ? ` · ${withdrawWallet.tenant.name}` : ''}`}
                visible={withdrawOpen}
                style={{ width: '28rem' }}
                onHide={() => setWithdrawOpen(false)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button label="Cancel" severity="secondary" outlined onClick={() => setWithdrawOpen(false)} />
                        <Button
                            label={withdrawLoading ? 'Processing…' : 'Withdraw'}
                            onClick={handleWithdraw}
                            disabled={withdrawLoading || !withdrawAmount}
                            loading={withdrawLoading}
                        />
                    </div>
                }
            >
                <div className="space-y-4">
                    {withdrawWallet && (
                        <p style={{ fontSize: 13 }}>
                            Current balance:{' '}
                            <strong style={{ color: 'var(--cg-brass-hi)', fontFamily: 'var(--cg-mono)' }}>
                                {withdrawWallet.currency} {formatNAD(parseFloat(withdrawWallet.balance))}
                            </strong>
                        </p>
                    )}
                    <div className="flex flex-col gap-1">
                        <label style={{ fontSize: 12, fontWeight: 500 }}>
                            Amount ({withdrawWallet?.currency || 'NAD'})
                        </label>
                        <InputText
                            type="number"
                            min={0.01}
                            step={0.01}
                            value={withdrawAmount}
                            onChange={(e) => setWithdrawAmount(e.target.value)}
                            placeholder="0.00"
                            className="w-full"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label style={{ fontSize: 12, fontWeight: 500 }}>Reference / note (optional)</label>
                        <InputTextarea
                            value={withdrawReference}
                            onChange={(e) => setWithdrawReference(e.target.value)}
                            placeholder="e.g., Manual withdrawal by admin"
                            rows={2}
                            className="w-full"
                        />
                    </div>
                </div>
            </Dialog>
        </UserLayout>
    );
}
