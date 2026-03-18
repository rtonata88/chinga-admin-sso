import PageHeader from '@/components/acumatica/Common/PageHeader';
import StatusBadge from '@/components/acumatica/Common/StatusBadge';
import UserLayout from '@/layouts/user-layout';
import type { StatusVariant } from '@/types/acumatica';
import { Head } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Calendar } from 'primereact/calendar';
import { Column } from 'primereact/column';
import { DataTable } from 'primereact/datatable';
import { Dropdown } from 'primereact/dropdown';
import { InputText } from 'primereact/inputtext';
import { MultiSelect } from 'primereact/multiselect';
import { Toast } from 'primereact/toast';
import { useEffect, useRef, useState } from 'react';

interface Transaction {
    uuid: string;
    source_type: 'wallet' | 'voucher';
    player_name: string | null;
    player_email: string | null;
    voucher_code: string | null;
    venue_name: string | null;
    type: string;
    amount: string;
    balance_before: string;
    balance_after: string;
    reference: string | null;
    description: string | null;
    performed_by_name: string | null;
    currency: string;
    game_name: string | null;
    created_at: string;
}

interface Meta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Stats {
    total_transactions: number;
    total_deposits: string;
    total_withdrawals: string;
    total_bets: string;
    total_wins: string;
}

function formatCurrency(amount: number | string, currency: string = 'NAD'): string {
    const num = typeof amount === 'string' ? parseFloat(amount) : amount;
    return `${currency} ${num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDate(dateString: string): string {
    return new Date(dateString).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

const creditTypes = ['deposit', 'win', 'load', 'transfer_in'];
const debitTypes = ['withdrawal', 'bet', 'loss', 'cashout', 'transfer_out'];

function getTypeBadgeVariant(type: string): StatusVariant {
    if (creditTypes.includes(type)) return 'active';
    if (debitTypes.includes(type)) return 'suspended';
    return 'inactive'; // adjustment
}

function formatTypeLabel(type: string): string {
    return type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
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

const typeOptions = [
    { label: 'Deposit', value: 'deposit' },
    { label: 'Withdrawal', value: 'withdrawal' },
    { label: 'Bet', value: 'bet' },
    { label: 'Win', value: 'win' },
    { label: 'Adjustment', value: 'adjustment' },
    { label: 'Load', value: 'load' },
    { label: 'Loss', value: 'loss' },
    { label: 'Cashout', value: 'cashout' },
    { label: 'Transfer In', value: 'transfer_in' },
    { label: 'Transfer Out', value: 'transfer_out' },
];

const sourceOptions = [
    { label: 'All Sources', value: 'all' },
    { label: 'Wallet', value: 'wallet' },
    { label: 'Voucher', value: 'voucher' },
];

export default function WalletTransactions() {
    const toast = useRef<Toast>(null);
    const [transactions, setTransactions] = useState<Transaction[]>([]);
    const [meta, setMeta] = useState<Meta | null>(null);
    const [stats, setStats] = useState<Stats | null>(null);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState<string[]>([]);
    const [sourceFilter, setSourceFilter] = useState('all');
    const [dateFrom, setDateFrom] = useState<Date | null>(null);
    const [dateTo, setDateTo] = useState<Date | null>(null);
    const [page, setPage] = useState(1);
    const [gameFilter, setGameFilter] = useState<number | ''>('');
    const [gameOptions, setGameOptions] = useState<{ label: string; value: number | '' }[]>([]);

    // Check for wallet query param (from Wallets page "View Transactions" link)
    const [walletUuid] = useState(() => {
        const params = new URLSearchParams(window.location.search);
        return params.get('wallet') || '';
    });

    const fetchTransactions = async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams();
            if (search) params.append('search', search);
            if (typeFilter.length > 0) params.append('type', typeFilter.join(','));
            if (sourceFilter !== 'all') params.append('source', sourceFilter);
            if (dateFrom) params.append('date_from', dateFrom.toISOString().split('T')[0]);
            if (dateTo) params.append('date_to', dateTo.toISOString().split('T')[0]);
            if (walletUuid) params.append('wallet', walletUuid);
            if (gameFilter !== '') params.append('game', gameFilter.toString());
            params.append('page', page.toString());

            const response = await fetch(`/api/v1/admin/wallet-transactions?${params}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            if (data.success) {
                setTransactions(data.data);
                setMeta(data.meta);
                if (data.stats) setStats(data.stats);
                if (data.games) {
                    setGameOptions([
                        { label: 'All Games', value: '' },
                        ...data.games.map((g: { id: number; name: string }) => ({ label: g.name, value: g.id })),
                    ]);
                }
            }
        } catch (error) {
            console.error('Failed to fetch transactions:', error);
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to load transactions.' });
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchTransactions();
    }, [page, sourceFilter, typeFilter, gameFilter, dateFrom, dateTo]);

    const handleSearch = () => {
        setPage(1);
        fetchTransactions();
    };

    const handleClearFilters = () => {
        setSearch('');
        setTypeFilter([]);
        setSourceFilter('all');
        setGameFilter('');
        setDateFrom(null);
        setDateTo(null);
        setPage(1);
        // Trigger refetch after state resets (setTimeout ensures state updates are batched first)
        setTimeout(() => fetchTransactions(), 0);
    };

    // Column templates
    const dateTemplate = (row: Transaction) => (
        <span className="text-sm text-[var(--acu-text)]">{formatDate(row.created_at)}</span>
    );

    const playerTemplate = (row: Transaction) => {
        if (row.source_type === 'wallet') {
            return (
                <div>
                    <div className="font-medium text-sm text-[var(--acu-text)]">{row.player_name}</div>
                    <div className="text-xs text-[var(--acu-text-light)]">{row.player_email}</div>
                </div>
            );
        }
        return (
            <div>
                <div className="font-medium text-sm text-[var(--acu-text)]">{row.voucher_code}</div>
                <div className="text-xs text-[var(--acu-text-light)]">{row.venue_name}</div>
            </div>
        );
    };

    const typeTemplate = (row: Transaction) => (
        <StatusBadge status={getTypeBadgeVariant(row.type)} label={formatTypeLabel(row.type)} />
    );

    const gameTemplate = (row: Transaction) => (
        <span className="text-sm text-[var(--acu-text)]">{row.game_name || '—'}</span>
    );

    const amountTemplate = (row: Transaction) => {
        const isCredit = creditTypes.includes(row.type);
        const absAmount = Math.abs(parseFloat(row.amount));
        return (
            <span className={`font-semibold text-sm ${isCredit ? 'text-green-600' : 'text-red-600'}`}>
                {isCredit ? '+' : '-'}{formatCurrency(absAmount, row.currency)}
            </span>
        );
    };

    const balanceTemplate = (row: Transaction) => (
        <div className="text-sm text-[var(--acu-text-light)]">
            {formatCurrency(row.balance_before, row.currency)} → {formatCurrency(row.balance_after, row.currency)}
        </div>
    );

    const referenceTemplate = (row: Transaction) => (
        <span className="text-sm text-[var(--acu-text-light)]">{row.reference || '—'}</span>
    );

    const performedByTemplate = (row: Transaction) => (
        <span className="text-sm text-[var(--acu-text-light)]">{row.performed_by_name || '—'}</span>
    );

    const sourceTemplate = (row: Transaction) => (
        <StatusBadge
            status={row.source_type === 'wallet' ? 'active' : 'inactive'}
            label={row.source_type === 'wallet' ? 'Wallet' : 'Voucher'}
        />
    );

    return (
        <UserLayout title="Wallet Transactions">
            <Head title="Wallet Transactions" />
            <Toast ref={toast} />

            <div className="space-y-6">
                <PageHeader title="Wallet Transactions" subtitle="View all wallet and voucher transactions">
                    <Button label="Refresh" icon="pi pi-refresh" outlined size="small" onClick={fetchTransactions} />
                </PageHeader>

                {/* Stats */}
                {stats && (
                    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-5">
                        <StatCard
                            icon="pi pi-list"
                            iconColor="#3B82F6"
                            title="Total Transactions"
                            value={stats.total_transactions.toLocaleString()}
                        />
                        <StatCard
                            icon="pi pi-plus-circle"
                            iconColor="#10B981"
                            title="Deposits / Loads"
                            value={formatCurrency(stats.total_deposits)}
                        />
                        <StatCard
                            icon="pi pi-minus-circle"
                            iconColor="#EF4444"
                            title="Withdrawals / Cashouts"
                            value={formatCurrency(stats.total_withdrawals)}
                        />
                        <StatCard
                            icon="pi pi-play"
                            iconColor="#F59E0B"
                            title="Bets / Losses"
                            value={formatCurrency(stats.total_bets)}
                        />
                        <StatCard
                            icon="pi pi-star"
                            iconColor="#8B5CF6"
                            title="Wins"
                            value={formatCurrency(stats.total_wins)}
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
                        <Button
                            label="Clear"
                            icon="pi pi-times"
                            text
                            size="small"
                            onClick={handleClearFilters}
                        />
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
                                        placeholder="Search by player name, email, or voucher code..."
                                        className="w-full"
                                    />
                                </span>
                                <Button label="Search" icon="pi pi-search" size="small" onClick={handleSearch} />
                            </div>
                            <MultiSelect
                                value={typeFilter}
                                onChange={(e) => {
                                    setTypeFilter(e.value);
                                    setPage(1);
                                }}
                                options={typeOptions}
                                placeholder="Type"
                                className="w-48"
                                maxSelectedLabels={1}
                            />
                            <Dropdown
                                value={sourceFilter}
                                onChange={(e) => {
                                    setSourceFilter(e.value);
                                    setPage(1);
                                }}
                                options={sourceOptions}
                                placeholder="Source"
                                className="w-40"
                            />
                            <Dropdown
                                value={gameFilter}
                                onChange={(e) => {
                                    setGameFilter(e.value);
                                    setPage(1);
                                }}
                                options={gameOptions}
                                placeholder="Game"
                                className="w-48"
                            />
                            <Calendar
                                value={dateFrom}
                                onChange={(e) => {
                                    setDateFrom(e.value as Date | null);
                                    setPage(1);
                                }}
                                placeholder="From"
                                dateFormat="yy-mm-dd"
                                showIcon
                                className="w-44"
                            />
                            <Calendar
                                value={dateTo}
                                onChange={(e) => {
                                    setDateTo(e.value as Date | null);
                                    setPage(1);
                                }}
                                placeholder="To"
                                dateFormat="yy-mm-dd"
                                showIcon
                                className="w-44"
                            />
                        </div>
                    </div>
                </div>

                {/* Transactions Table */}
                <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-blue)' } as React.CSSProperties}>
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-list" />
                            <span>Transactions</span>
                            <span className="text-xs font-normal text-[var(--acu-text-light)] ml-1">
                                {meta?.total ? `(${transactions.length} of ${meta.total})` : ''}
                            </span>
                        </div>
                    </div>
                    <div className="acu-fieldset-body p-0">
                        <DataTable
                            value={transactions}
                            loading={loading}
                            size="small"
                            showGridlines={false}
                            emptyMessage="No transactions found"
                            dataKey="uuid"
                        >
                            <Column header="Date" body={dateTemplate} style={{ width: '10rem' }} />
                            <Column header="Player / Source" body={playerTemplate} />
                            <Column header="Type" body={typeTemplate} style={{ width: '8rem' }} />
                            <Column header="Game" body={gameTemplate} style={{ width: '9rem' }} />
                            <Column header="Amount" body={amountTemplate} style={{ width: '10rem' }} />
                            <Column header="Balance" body={balanceTemplate} style={{ width: '14rem' }} />
                            <Column header="Reference" body={referenceTemplate} style={{ width: '8rem' }} />
                            <Column header="Performed By" body={performedByTemplate} style={{ width: '9rem' }} />
                            <Column header="Source" body={sourceTemplate} style={{ width: '6rem' }} />
                        </DataTable>

                        {/* Pagination */}
                        {meta && meta.last_page > 1 && (
                            <div className="flex items-center justify-between px-4 py-3 border-t border-[var(--acu-border)]">
                                <span className="text-xs text-[var(--acu-text-light)]">
                                    Page {meta.current_page} of {meta.last_page} ({meta.total} total)
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
        </UserLayout>
    );
}
