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
import { InputTextarea } from 'primereact/inputtextarea';
import { Toast } from 'primereact/toast';
import { useEffect, useRef, useState } from 'react';

interface WalletUser {
    uuid: string;
    name: string;
    email: string;
}

interface Wallet {
    id: number;
    uuid: string;
    user: WalletUser;
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

function formatCurrency(amount: number | string, currency: string = 'NAD'): string {
    const num = typeof amount === 'string' ? parseFloat(amount) : amount;
    return `${currency} ${num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function mapWalletStatus(status: string): StatusVariant {
    switch (status) {
        case 'active':
            return 'active';
        case 'frozen':
            return 'suspended';
        case 'closed':
            return 'inactive';
        default:
            return 'inactive';
    }
}

interface StatCardProps {
    icon: string;
    iconColor: string;
    title: string;
    value: string | number;
}

function StatCard({ icon, iconColor, title, value }: StatCardProps) {
    return (
        <div className="acu-fieldset">
            <div className="p-4">
                <div className="flex items-center justify-between mb-3">
                    <span className="text-xs font-semibold uppercase tracking-wide text-[var(--acu-text-muted)]">
                        {title}
                    </span>
                    <div
                        className="w-8 h-8 rounded-lg flex items-center justify-center"
                        style={{ backgroundColor: `${iconColor}15`, color: iconColor }}
                    >
                        <i className={`${icon} text-sm`} />
                    </div>
                </div>
                <div className="text-2xl font-bold text-[var(--acu-text)]">{value}</div>
            </div>
        </div>
    );
}

const statusOptions = [
    { label: 'All Status', value: '' },
    { label: 'Active', value: 'active' },
    { label: 'Frozen', value: 'frozen' },
    { label: 'Closed', value: 'closed' },
];

export default function Wallets() {
    const toast = useRef<Toast>(null);
    const [wallets, setWallets] = useState<Wallet[]>([]);
    const [meta, setMeta] = useState<Meta | null>(null);
    const [stats, setStats] = useState<Stats | null>(null);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [page, setPage] = useState(1);

    // Deposit dialog
    const [depositOpen, setDepositOpen] = useState(false);
    const [depositWallet, setDepositWallet] = useState<Wallet | null>(null);
    const [depositAmount, setDepositAmount] = useState('');
    const [depositReference, setDepositReference] = useState('');
    const [depositLoading, setDepositLoading] = useState(false);

    // Withdraw dialog
    const [withdrawOpen, setWithdrawOpen] = useState(false);
    const [withdrawWallet, setWithdrawWallet] = useState<Wallet | null>(null);
    const [withdrawAmount, setWithdrawAmount] = useState('');
    const [withdrawReference, setWithdrawReference] = useState('');
    const [withdrawLoading, setWithdrawLoading] = useState(false);

    const getCsrfToken = () => {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    };

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
                if (data.stats) {
                    setStats(data.stats);
                }
            }
        } catch (error) {
            console.error('Failed to fetch wallets:', error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchWallets();
    }, [page, statusFilter]);

    const handleSearch = () => {
        setPage(1);
        fetchWallets();
    };

    const openDeposit = (wallet: Wallet) => {
        setDepositWallet(wallet);
        setDepositAmount('');
        setDepositReference('');
        setDepositOpen(true);
    };

    const openWithdraw = (wallet: Wallet) => {
        setWithdrawWallet(wallet);
        setWithdrawAmount('');
        setWithdrawReference('');
        setWithdrawOpen(true);
    };

    const handleDeposit = async () => {
        if (!depositWallet || !depositAmount) return;
        setDepositLoading(true);

        try {
            const response = await fetch(`/api/v1/admin/wallets/${depositWallet.uuid}/deposit`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
                    amount: parseFloat(depositAmount),
                    reference: depositReference || undefined,
                }),
            });
            const data = await response.json();
            if (data.success) {
                toast.current?.show({ severity: 'success', summary: 'Success', detail: 'Deposit successful.' });
                setDepositOpen(false);
                fetchWallets();
            } else {
                toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || 'Deposit failed.' });
            }
        } catch (error) {
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
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
                    amount: parseFloat(withdrawAmount),
                    reference: withdrawReference || undefined,
                }),
            });
            const data = await response.json();
            if (data.success) {
                toast.current?.show({ severity: 'success', summary: 'Success', detail: 'Withdrawal successful.' });
                setWithdrawOpen(false);
                fetchWallets();
            } else {
                toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || 'Withdrawal failed.' });
            }
        } catch (error) {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Withdrawal failed.' });
        } finally {
            setWithdrawLoading(false);
        }
    };

    const handleFreeze = async (wallet: Wallet) => {
        try {
            const response = await fetch(`/api/v1/admin/wallets/${wallet.uuid}/freeze`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
            });
            const data = await response.json();
            if (data.success) {
                toast.current?.show({ severity: 'success', summary: 'Success', detail: 'Wallet frozen.' });
                fetchWallets();
            } else {
                toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || 'Failed to freeze wallet.' });
            }
        } catch (error) {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to freeze wallet.' });
        }
    };

    const handleActivate = async (wallet: Wallet) => {
        try {
            const response = await fetch(`/api/v1/admin/wallets/${wallet.uuid}/activate`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
            });
            const data = await response.json();
            if (data.success) {
                toast.current?.show({ severity: 'success', summary: 'Success', detail: 'Wallet activated.' });
                fetchWallets();
            } else {
                toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || 'Failed to activate wallet.' });
            }
        } catch (error) {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to activate wallet.' });
        }
    };

    // Column templates
    const userTemplate = (row: Wallet) => (
        <div>
            <div className="font-medium text-sm text-[var(--acu-text)]">{row.user?.name}</div>
            <div className="text-xs text-[var(--acu-text-light)]">{row.user?.email}</div>
        </div>
    );

    const balanceTemplate = (row: Wallet) => (
        <span className="font-semibold text-sm text-[var(--acu-text)]">
            {formatCurrency(row.balance, row.currency)}
        </span>
    );

    const currencyTemplate = (row: Wallet) => (
        <span className="text-sm text-[var(--acu-text)]">{row.currency}</span>
    );

    const statusTemplate = (row: Wallet) => (
        <StatusBadge status={mapWalletStatus(row.status)} label={row.status} />
    );

    const depositedTemplate = (row: Wallet) => (
        <span className="text-sm text-[var(--acu-text-light)]">
            {formatCurrency(row.total_deposited, row.currency)}
        </span>
    );

    const withdrawnTemplate = (row: Wallet) => (
        <span className="text-sm text-[var(--acu-text-light)]">
            {formatCurrency(row.total_withdrawn, row.currency)}
        </span>
    );

    const actionsTemplate = (row: Wallet) => (
        <div className="flex gap-1">
            {row.status === 'active' && (
                <>
                    <Button
                        icon="pi pi-plus"
                        text
                        severity="success"
                        size="small"
                        tooltip="Deposit"
                        onClick={() => openDeposit(row)}
                    />
                    <Button
                        icon="pi pi-minus"
                        text
                        severity="warning"
                        size="small"
                        tooltip="Withdraw"
                        onClick={() => openWithdraw(row)}
                    />
                    <Button
                        icon="pi pi-lock"
                        text
                        severity="danger"
                        size="small"
                        tooltip="Freeze"
                        onClick={() => handleFreeze(row)}
                    />
                </>
            )}
            {row.status === 'frozen' && (
                <Button
                    icon="pi pi-lock-open"
                    text
                    severity="success"
                    size="small"
                    tooltip="Activate"
                    onClick={() => handleActivate(row)}
                />
            )}
        </div>
    );

    return (
        <UserLayout title="Wallets">
            <Head title="Wallet Management" />
            <Toast ref={toast} />

            <div className="space-y-6">
                <PageHeader title="Wallet Management" subtitle="Manage user wallets, deposits, and withdrawals">
                    <Button
                        label="Refresh"
                        icon="pi pi-refresh"
                        outlined
                        size="small"
                        onClick={fetchWallets}
                    />
                </PageHeader>

                {/* Stats */}
                {stats && (
                    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                        <StatCard
                            icon="pi pi-credit-card"
                            iconColor="#3B82F6"
                            title="Total Wallets"
                            value={stats.total_wallets.toLocaleString()}
                        />
                        <StatCard
                            icon="pi pi-dollar"
                            iconColor="#10B981"
                            title="Total Balance"
                            value={formatCurrency(stats.total_balance)}
                        />
                        <StatCard
                            icon="pi pi-check-circle"
                            iconColor="#8B5CF6"
                            title="Active"
                            value={stats.active_wallets}
                        />
                        <StatCard
                            icon="pi pi-lock"
                            iconColor="#F59E0B"
                            title="Frozen"
                            value={stats.frozen_wallets}
                        />
                    </div>
                )}

                {/* Filters */}
                <div className="acu-fieldset">
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-filter" />
                            <span>Filters</span>
                        </div>
                    </div>
                    <div className="acu-fieldset-body">
                        <div className="flex flex-wrap gap-3 items-end">
                            <div className="flex flex-1 gap-2">
                                <span className="p-input-icon-left flex-1" style={{ maxWidth: '24rem' }}>
                                    <i className="pi pi-search" />
                                    <InputText
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                                        placeholder="Search by user name or email..."
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
                                onChange={(e) => {
                                    setStatusFilter(e.value);
                                    setPage(1);
                                }}
                                options={statusOptions}
                                placeholder="Status"
                                className="w-40"
                            />
                        </div>
                    </div>
                </div>

                {/* Wallets Table */}
                <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-blue)' } as React.CSSProperties}>
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-credit-card" />
                            <span>Wallets</span>
                            <span className="text-xs font-normal text-[var(--acu-text-light)] ml-1">
                                {meta?.total ? `(${wallets.length} of ${meta.total})` : ''}
                            </span>
                        </div>
                    </div>
                    <div className="acu-fieldset-body p-0">
                        <DataTable
                            value={wallets}
                            loading={loading}
                            size="small"
                            showGridlines={false}
                            emptyMessage="No wallets found"
                            dataKey="uuid"
                        >
                            <Column header="User" body={userTemplate} />
                            <Column header="Balance" body={balanceTemplate} />
                            <Column header="Currency" body={currencyTemplate} style={{ width: '6rem' }} />
                            <Column header="Status" body={statusTemplate} />
                            <Column header="Total Deposited" body={depositedTemplate} />
                            <Column header="Total Withdrawn" body={withdrawnTemplate} />
                            <Column header="" body={actionsTemplate} style={{ width: '8rem' }} />
                        </DataTable>

                        {/* Pagination */}
                        {meta && meta.last_page > 1 && (
                            <div className="flex items-center justify-between px-4 py-3 border-t border-[var(--acu-border)]">
                                <span className="text-xs text-[var(--acu-text-light)]">
                                    Page {meta.current_page} of {meta.last_page}
                                </span>
                                <div className="flex gap-2">
                                    <Button
                                        label="Previous"
                                        icon="pi pi-angle-left"
                                        outlined
                                        size="small"
                                        disabled={meta.current_page === 1}
                                        onClick={() => setPage((p) => Math.max(1, p - 1))}
                                    />
                                    <Button
                                        label="Next"
                                        icon="pi pi-angle-right"
                                        iconPos="right"
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

            {/* Deposit Dialog */}
            <Dialog
                header={`Deposit — ${depositWallet?.user?.name || ''}`}
                visible={depositOpen}
                style={{ width: '28rem' }}
                onHide={() => setDepositOpen(false)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button
                            label="Cancel"
                            icon="pi pi-times"
                            severity="secondary"
                            outlined
                            onClick={() => setDepositOpen(false)}
                        />
                        <Button
                            label={depositLoading ? 'Processing...' : 'Deposit'}
                            icon="pi pi-check"
                            onClick={handleDeposit}
                            disabled={depositLoading || !depositAmount}
                            loading={depositLoading}
                        />
                    </div>
                }
            >
                <div className="space-y-4">
                    {depositWallet && (
                        <p className="text-sm text-[var(--acu-text-light)]">
                            Current balance: <strong>{formatCurrency(depositWallet.balance, depositWallet.currency)}</strong>
                        </p>
                    )}
                    <div className="flex flex-col gap-1">
                        <label className="text-sm font-medium text-[var(--acu-text)]">Amount ({depositWallet?.currency || 'NAD'})</label>
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
                        <label className="text-sm font-medium text-[var(--acu-text)]">Reference / Note (optional)</label>
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

            {/* Withdraw Dialog */}
            <Dialog
                header={`Withdraw — ${withdrawWallet?.user?.name || ''}`}
                visible={withdrawOpen}
                style={{ width: '28rem' }}
                onHide={() => setWithdrawOpen(false)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button
                            label="Cancel"
                            icon="pi pi-times"
                            severity="secondary"
                            outlined
                            onClick={() => setWithdrawOpen(false)}
                        />
                        <Button
                            label={withdrawLoading ? 'Processing...' : 'Withdraw'}
                            icon="pi pi-check"
                            severity="warning"
                            onClick={handleWithdraw}
                            disabled={withdrawLoading || !withdrawAmount}
                            loading={withdrawLoading}
                        />
                    </div>
                }
            >
                <div className="space-y-4">
                    {withdrawWallet && (
                        <p className="text-sm text-[var(--acu-text-light)]">
                            Current balance: <strong>{formatCurrency(withdrawWallet.balance, withdrawWallet.currency)}</strong>
                        </p>
                    )}
                    <div className="flex flex-col gap-1">
                        <label className="text-sm font-medium text-[var(--acu-text)]">Amount ({withdrawWallet?.currency || 'NAD'})</label>
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
                        <label className="text-sm font-medium text-[var(--acu-text)]">Reference / Note (optional)</label>
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
