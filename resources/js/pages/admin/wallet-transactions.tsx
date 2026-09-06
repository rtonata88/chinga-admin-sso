// resources/js/pages/admin/wallet-transactions.tsx
//
// Wallet & voucher transactions, brass-on-ink to match
// /tenant-overview. KPI strip (5-up) → filter bar (multi-toggle
// type chips, source chips, game select, date range, search) →
// transactions table → pager.

import { KpiCard, formatCount, formatCurrencyCompact, formatNAD } from '@/components/operator/kpi-card';
import UserLayout from '@/layouts/user-layout';
import { Head } from '@inertiajs/react';
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

const CREDIT_TYPES = new Set(['deposit', 'win', 'load', 'transfer_in']);
const DEBIT_TYPES = new Set(['withdrawal', 'bet', 'loss', 'cashout', 'transfer_out']);

const TYPE_OPTIONS: { label: string; value: string }[] = [
    { label: 'Deposit', value: 'deposit' },
    { label: 'Withdrawal', value: 'withdrawal' },
    { label: 'Bet', value: 'bet' },
    { label: 'Win', value: 'win' },
    { label: 'Loss', value: 'loss' },
    { label: 'Adjustment', value: 'adjustment' },
    { label: 'Load', value: 'load' },
    { label: 'Cashout', value: 'cashout' },
    { label: 'Transfer in', value: 'transfer_in' },
    { label: 'Transfer out', value: 'transfer_out' },
];

const SOURCE_FILTERS = [
    { label: 'All sources', value: 'all' },
    { label: 'Wallet', value: 'wallet' },
    { label: 'Voucher', value: 'voucher' },
];

const DATETIME_FMT = new Intl.DateTimeFormat('en-GB', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
});

function formatDateTime(iso: string): string {
    return DATETIME_FMT.format(new Date(iso));
}

function formatTypeLabel(type: string): string {
    return type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function typeColor(type: string): string {
    if (CREDIT_TYPES.has(type)) return 'var(--cg-pos)';
    if (DEBIT_TYPES.has(type)) return 'var(--cg-neg)';
    return 'var(--cg-fg-3)';
}

export default function WalletTransactions() {
    const toast = useRef<Toast>(null);
    const [transactions, setTransactions] = useState<Transaction[]>([]);
    const [meta, setMeta] = useState<Meta | null>(null);
    const [stats, setStats] = useState<Stats | null>(null);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState<string[]>([]);
    const [sourceFilter, setSourceFilter] = useState('all');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [page, setPage] = useState(1);
    const [gameFilter, setGameFilter] = useState<string>('');
    const [gameOptions, setGameOptions] = useState<{ id: number; name: string }[]>([]);

    // Optional wallet scoping via ?wallet=<uuid> query param.
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
            if (dateFrom) params.append('date_from', dateFrom);
            if (dateTo) params.append('date_to', dateTo);
            if (walletUuid) params.append('wallet', walletUuid);
            if (gameFilter) params.append('game', gameFilter);
            params.append('page', page.toString());

            const response = await fetch(`/api/v1/admin/wallet-transactions?${params}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            if (data.success) {
                setTransactions(data.data);
                setMeta(data.meta);
                if (data.stats) setStats(data.stats);
                if (data.games) setGameOptions(data.games);
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
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [page, sourceFilter, typeFilter, gameFilter, dateFrom, dateTo]);

    const submitSearch = () => { setPage(1); fetchTransactions(); };

    const toggleType = (value: string) => {
        setTypeFilter((curr) =>
            curr.includes(value) ? curr.filter((v) => v !== value) : [...curr, value]
        );
        setPage(1);
    };

    const clearFilters = () => {
        setSearch('');
        setTypeFilter([]);
        setSourceFilter('all');
        setGameFilter('');
        setDateFrom('');
        setDateTo('');
        setPage(1);
        setTimeout(() => fetchTransactions(), 0);
    };

    const hasActiveFilter =
        search || typeFilter.length > 0 || sourceFilter !== 'all' || gameFilter || dateFrom || dateTo;

    return (
        <UserLayout title="Wallet transactions">
            <Head title="Wallet transactions · Admin" />
            <Toast ref={toast} />

            <div className="cgo-page">
                {/* Page header */}
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Admin</div>
                        <h1 className="cgo-title">Wallet transactions</h1>
                        <div className="cgo-subtitle">
                            All wallet and voucher movements — deposits, withdrawals, bets, wins.
                            {walletUuid && <> Scoped to a single wallet.</>}
                        </div>
                    </div>
                    <div style={{ display: 'flex', gap: 8 }}>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={fetchTransactions}
                        >
                            Refresh
                        </button>
                    </div>
                </div>

                {/* KPI strip — 5 metrics. */}
                <div className="cgo-kpis cgo-kpis--5">
                    <KpiCard
                        label="Total · transactions"
                        value={stats ? formatCount(stats.total_transactions) : '—'}
                        meta="all activity"
                    />
                    <KpiCard
                        label="Deposits & loads"
                        value={stats ? formatCurrencyCompact(parseFloat(stats.total_deposits)) : '—'}
                        meta="money in"
                    />
                    <KpiCard
                        label="Withdrawals & cashouts"
                        value={stats ? formatCurrencyCompact(parseFloat(stats.total_withdrawals)) : '—'}
                        meta="money out"
                    />
                    <KpiCard
                        label="Bets & losses"
                        value={stats ? formatCurrencyCompact(parseFloat(stats.total_bets)) : '—'}
                        meta="staked"
                    />
                    <KpiCard
                        label="Wins"
                        value={stats ? formatCurrencyCompact(parseFloat(stats.total_wins)) : '—'}
                        brass
                        meta="paid to players"
                    />
                </div>

                {/* Filter bar */}
                <div className="cgo-filterbar">
                    {/* Source */}
                    {SOURCE_FILTERS.map((f) => (
                        <button
                            key={f.value}
                            type="button"
                            className={`cgo-chip${sourceFilter === f.value ? ' active' : ''}`}
                            onClick={() => { setSourceFilter(f.value); setPage(1); }}
                        >
                            {f.label}
                        </button>
                    ))}
                    <div className="cgo-filter-divider" />
                    {/* Type — multi-toggle */}
                    {TYPE_OPTIONS.map((t) => (
                        <button
                            key={t.value}
                            type="button"
                            className={`cgo-chip${typeFilter.includes(t.value) ? ' active' : ''}`}
                            onClick={() => toggleType(t.value)}
                        >
                            {t.label}
                        </button>
                    ))}
                    <div className="cgo-right" style={{ flexWrap: 'wrap' }}>
                        {/* Game select */}
                        <label className="cgo-input" style={{ minWidth: 140 }}>
                            <select
                                value={gameFilter}
                                onChange={(e) => { setGameFilter(e.target.value); setPage(1); }}
                                style={{
                                    all: 'unset', flex: 1,
                                    color: 'inherit', font: 'inherit', cursor: 'pointer',
                                }}
                            >
                                <option value="">All games</option>
                                {gameOptions.map((g) => (
                                    <option key={g.id} value={g.id}>{g.name}</option>
                                ))}
                            </select>
                        </label>
                        {/* Date range */}
                        <label className="cgo-input" style={{ minWidth: 140 }}>
                            <input
                                type="date"
                                value={dateFrom}
                                onChange={(e) => { setDateFrom(e.target.value); setPage(1); }}
                                placeholder="From"
                                style={{ minWidth: 120 }}
                            />
                        </label>
                        <label className="cgo-input" style={{ minWidth: 140 }}>
                            <input
                                type="date"
                                value={dateTo}
                                onChange={(e) => { setDateTo(e.target.value); setPage(1); }}
                                placeholder="To"
                                style={{ minWidth: 120 }}
                            />
                        </label>
                        {/* Search */}
                        <label className="cgo-input">
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && submitSearch()}
                                placeholder="Search player, email, voucher code…"
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
                        {hasActiveFilter && (
                            <button
                                type="button"
                                className="cg-btn cg-btn--text cg-btn--sm"
                                onClick={clearFilters}
                            >
                                Clear
                            </button>
                        )}
                    </div>
                </div>

                {/* Transactions table */}
                <div
                    className="cgo-table-wrap cgo-table-wrap--scroll"
                    style={{ borderRadius: '0 0 8px 8px', borderTop: 0 }}
                >
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ width: 150 }}>Date</th>
                                <th style={{ minWidth: 200 }}>Player / source</th>
                                <th style={{ width: 110 }}>Type</th>
                                <th style={{ width: 110 }}>Game</th>
                                <th className="cgo-r" style={{ width: 130 }}>Amount</th>
                                <th className="cgo-r" style={{ width: 200 }}>Balance</th>
                                <th style={{ width: 110 }}>Reference</th>
                                <th style={{ width: 130 }}>Performed by</th>
                                <th style={{ width: 80 }}>Source</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan={9} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        Loading…
                                    </td>
                                </tr>
                            ) : transactions.length === 0 ? (
                                <tr>
                                    <td colSpan={9} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        No transactions match the current filters.
                                    </td>
                                </tr>
                            ) : (
                                transactions.map((t) => {
                                    const isCredit = CREDIT_TYPES.has(t.type);
                                    const isDebit = DEBIT_TYPES.has(t.type);
                                    const absAmount = Math.abs(parseFloat(t.amount));
                                    const sign = isCredit ? '+' : isDebit ? '−' : '';
                                    const amountColor = isCredit ? 'var(--cg-pos)' : isDebit ? 'var(--cg-neg)' : 'var(--cg-fg-1)';
                                    return (
                                        <tr key={t.uuid}>
                                            <td>
                                                <span className="cgo-uid">{formatDateTime(t.created_at)}</span>
                                            </td>
                                            <td style={{ maxWidth: 260 }}>
                                                {t.source_type === 'wallet' ? (
                                                    <>
                                                        <div className="cgo-name cgo-cell-clip" title={t.player_name || ''}>
                                                            {t.player_name || '—'}
                                                        </div>
                                                        <div className="cgo-uid">{t.player_email || '—'}</div>
                                                    </>
                                                ) : (
                                                    <>
                                                        <div className="cgo-name cgo-cell-clip" title={t.voucher_code || ''}>
                                                            {t.voucher_code || '—'}
                                                        </div>
                                                        <div className="cgo-uid">{t.venue_name || '—'}</div>
                                                    </>
                                                )}
                                            </td>
                                            <td>
                                                <span style={{ color: typeColor(t.type), fontSize: 12, fontWeight: 500 }}>
                                                    {formatTypeLabel(t.type)}
                                                </span>
                                            </td>
                                            <td>
                                                <span className="cgo-uid">{t.game_name || '—'}</span>
                                            </td>
                                            <td className="cgo-r">
                                                <span
                                                    className="cgo-stake"
                                                    style={{ color: amountColor }}
                                                >
                                                    {sign}<span className="cgo-ccy">{t.currency}</span>
                                                    {formatNAD(absAmount)}
                                                </span>
                                            </td>
                                            <td className="cgo-r">
                                                <span className="cgo-uid" style={{ fontFamily: 'var(--cg-mono)' }}>
                                                    {formatNAD(parseFloat(t.balance_before))} → {formatNAD(parseFloat(t.balance_after))}
                                                </span>
                                            </td>
                                            <td>
                                                <span className="cgo-uid cgo-cell-clip" title={t.reference || ''}>
                                                    {t.reference || '—'}
                                                </span>
                                            </td>
                                            <td>
                                                <span className="cgo-uid">{t.performed_by_name || '—'}</span>
                                            </td>
                                            <td>
                                                <span
                                                    className={`cgo-pill ${t.source_type === 'wallet' ? 'live' : 'settled'}`}
                                                >
                                                    {t.source_type}
                                                </span>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>

                    {/* Footer + pager */}
                    {meta && (
                        <div className="cgo-table-foot">
                            <span>
                                {meta.total > 0 ? (
                                    <>
                                        Showing <b className="cgo-mono">{transactions.length}</b> of{' '}
                                        <b className="cgo-mono">{meta.total.toLocaleString()}</b> transactions
                                    </>
                                ) : (
                                    'No transactions'
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
        </UserLayout>
    );
}
