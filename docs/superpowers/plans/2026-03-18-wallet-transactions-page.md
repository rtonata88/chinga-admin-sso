# Wallet Transactions Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a global Wallet Transactions admin page showing all wallet + voucher transactions with filtering, and a "View Transactions" link on the Wallets page.

**Architecture:** New `WalletTransactionController` queries both `wallet_transactions` and `voucher_transactions` tables with a UNION, applying filters before the union for performance. New React page follows the existing `wallets.tsx` pattern with stats cards, filters, and a PrimeReact DataTable.

**Tech Stack:** Laravel 11, React, Inertia.js, PrimeReact, Tailwind CSS

**Spec:** `docs/superpowers/specs/2026-03-18-wallet-transactions-page-design.md`

---

### Task 1: Backend — WalletTransactionController

**Files:**
- Create: `app/Http/Controllers/Admin/WalletTransactionController.php`

- [ ] **Step 1: Create the controller**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\VoucherTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WalletTransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $source = $request->input('source', 'all');
        $search = $request->input('search');
        $type = $request->input('type');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $walletUuid = $request->input('wallet');
        $perPage = (int) $request->input('per_page', 25);

        // Multi-tenancy: scope queries to current tenant
        $tenantId = $request->user()?->tenant_id;

        $walletQuery = null;
        $voucherQuery = null;

        // Wallet transactions query
        if ($source !== 'voucher') {
            $walletQuery = DB::table('wallet_transactions')
                ->join('wallets', 'wallet_transactions.wallet_id', '=', 'wallets.id')
                ->join('users', 'wallets.user_id', '=', 'users.id')
                ->leftJoin('users as performer', 'wallet_transactions.performed_by', '=', 'performer.id')
                ->select([
                    'wallet_transactions.uuid',
                    DB::raw("'wallet' as source_type"),
                    'users.name as player_name',
                    'users.email as player_email',
                    DB::raw('NULL as voucher_code'),
                    DB::raw('NULL as venue_name'),
                    'wallet_transactions.type',
                    'wallet_transactions.amount',
                    'wallet_transactions.balance_before',
                    'wallet_transactions.balance_after',
                    'wallet_transactions.reference',
                    'wallet_transactions.description',
                    'performer.name as performed_by_name',
                    'wallets.currency',
                    'wallet_transactions.created_at',
                ]);

            // Tenant scoping
            if ($tenantId) {
                $walletQuery->where('wallets.tenant_id', $tenantId);
            }

            // Filter by specific wallet
            if ($walletUuid) {
                $wallet = Wallet::where('uuid', $walletUuid)->first();
                if ($wallet) {
                    $walletQuery->where('wallet_transactions.wallet_id', $wallet->id);
                } else {
                    // Invalid wallet UUID — return empty results
                    return response()->json([
                        'success' => true,
                        'data' => [],
                        'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => $perPage, 'total' => 0],
                        'stats' => $this->emptyStats(),
                    ]);
                }
            }

            // Search
            if ($search) {
                $walletQuery->where(function ($q) use ($search) {
                    $q->where('users.name', 'like', "%{$search}%")
                      ->orWhere('users.email', 'like', "%{$search}%");
                });
            }

            // Type filter
            if ($type) {
                $types = is_array($type) ? $type : explode(',', $type);
                $walletTypes = array_intersect($types, ['deposit', 'withdrawal', 'bet', 'win', 'adjustment']);
                if (empty($walletTypes)) {
                    $walletQuery = null; // No matching wallet types
                } else {
                    $walletQuery->whereIn('wallet_transactions.type', $walletTypes);
                }
            }

            // Date range
            if ($dateFrom && $walletQuery) {
                $walletQuery->where('wallet_transactions.created_at', '>=', $dateFrom . ' 00:00:00');
            }
            if ($dateTo && $walletQuery) {
                $walletQuery->where('wallet_transactions.created_at', '<=', $dateTo . ' 23:59:59');
            }
        }

        // Voucher transactions query
        if ($source !== 'wallet' && !$walletUuid) {
            $voucherQuery = DB::table('voucher_transactions')
                ->join('voucher_codes', 'voucher_transactions.voucher_code_id', '=', 'voucher_codes.id')
                ->join('venues', 'voucher_codes.venue_id', '=', 'venues.id')
                ->leftJoin('venue_staff', 'voucher_transactions.performed_by_staff_id', '=', 'venue_staff.id')
                ->select([
                    'voucher_transactions.uuid',
                    DB::raw("'voucher' as source_type"),
                    DB::raw('NULL as player_name'),
                    DB::raw('NULL as player_email'),
                    DB::raw("CONCAT(LEFT(voucher_codes.code, 3), '****') as voucher_code"),
                    'venues.name as venue_name',
                    'voucher_transactions.type',
                    'voucher_transactions.amount',
                    'voucher_transactions.balance_before',
                    'voucher_transactions.balance_after',
                    'voucher_transactions.reference',
                    'voucher_transactions.description',
                    'venue_staff.display_name as performed_by_name',
                    'voucher_codes.currency',
                    'voucher_transactions.created_at',
                ]);

            // Tenant scoping
            if ($tenantId) {
                $voucherQuery->where('voucher_codes.tenant_id', $tenantId);
            }

            // Search by voucher code
            if ($search) {
                $voucherQuery->where('voucher_codes.code', 'like', "%{$search}%");
            }

            // Type filter
            if ($type) {
                $types = is_array($type) ? $type : explode(',', $type);
                $voucherTypes = array_intersect($types, ['load', 'win', 'loss', 'cashout', 'adjustment', 'transfer_in', 'transfer_out']);
                if (empty($voucherTypes)) {
                    $voucherQuery = null;
                } else {
                    $voucherQuery->whereIn('voucher_transactions.type', $voucherTypes);
                }
            }

            // Date range
            if ($dateFrom && $voucherQuery) {
                $voucherQuery->where('voucher_transactions.created_at', '>=', $dateFrom . ' 00:00:00');
            }
            if ($dateTo && $voucherQuery) {
                $voucherQuery->where('voucher_transactions.created_at', '<=', $dateTo . ' 23:59:59');
            }
        }

        // Build union or single query
        if ($walletQuery && $voucherQuery) {
            $unionQuery = $walletQuery->unionAll($voucherQuery);
            $results = DB::table(DB::raw("({$unionQuery->toSql()}) as transactions"))
                ->mergeBindings($unionQuery)
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);
        } elseif ($walletQuery) {
            $results = $walletQuery->orderBy('wallet_transactions.created_at', 'desc')->paginate($perPage);
        } elseif ($voucherQuery) {
            $results = $voucherQuery->orderBy('voucher_transactions.created_at', 'desc')->paginate($perPage);
        } else {
            return response()->json([
                'success' => true,
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => $perPage, 'total' => 0],
                'stats' => $this->emptyStats(),
            ]);
        }

        // Compute stats from the same filters
        $stats = $this->computeStats($source, $search, $type, $dateFrom, $dateTo, $walletUuid, $tenantId);

        return response()->json([
            'success' => true,
            'data' => $results->items(),
            'meta' => [
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total(),
            ],
            'stats' => $stats,
        ]);
    }

    private function computeStats(
        string $source,
        ?string $search,
        mixed $type,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $walletUuid,
        ?int $tenantId
    ): array {
        $stats = $this->emptyStats();

        if ($source !== 'voucher') {
            $q = DB::table('wallet_transactions')
                ->join('wallets', 'wallet_transactions.wallet_id', '=', 'wallets.id')
                ->join('users', 'wallets.user_id', '=', 'users.id');

            if ($tenantId) {
                $q->where('wallets.tenant_id', $tenantId);
            }

            if ($walletUuid) {
                $wallet = Wallet::where('uuid', $walletUuid)->first();
                if ($wallet) {
                    $q->where('wallet_transactions.wallet_id', $wallet->id);
                }
            }

            if ($search) {
                $q->where(function ($query) use ($search) {
                    $query->where('users.name', 'like', "%{$search}%")
                          ->orWhere('users.email', 'like', "%{$search}%");
                });
            }
            if ($dateFrom) {
                $q->where('wallet_transactions.created_at', '>=', $dateFrom . ' 00:00:00');
            }
            if ($dateTo) {
                $q->where('wallet_transactions.created_at', '<=', $dateTo . ' 23:59:59');
            }

            $walletStats = $q->selectRaw("
                COUNT(*) as total,
                COALESCE(SUM(CASE WHEN wallet_transactions.type = 'deposit' THEN wallet_transactions.amount ELSE 0 END), 0) as deposits,
                COALESCE(SUM(CASE WHEN wallet_transactions.type = 'withdrawal' THEN wallet_transactions.amount ELSE 0 END), 0) as withdrawals,
                COALESCE(SUM(CASE WHEN wallet_transactions.type = 'bet' THEN wallet_transactions.amount ELSE 0 END), 0) as bets,
                COALESCE(SUM(CASE WHEN wallet_transactions.type = 'win' THEN wallet_transactions.amount ELSE 0 END), 0) as wins
            ")->first();

            $stats['total_transactions'] += $walletStats->total;
            $stats['total_deposits'] = bcadd($stats['total_deposits'], (string) $walletStats->deposits, 2);
            $stats['total_withdrawals'] = bcadd($stats['total_withdrawals'], (string) $walletStats->withdrawals, 2);
            $stats['total_bets'] = bcadd($stats['total_bets'], (string) $walletStats->bets, 2);
            $stats['total_wins'] = bcadd($stats['total_wins'], (string) $walletStats->wins, 2);
        }

        if ($source !== 'wallet' && !$walletUuid) {
            $q = DB::table('voucher_transactions')
                ->join('voucher_codes', 'voucher_transactions.voucher_code_id', '=', 'voucher_codes.id');

            if ($tenantId) {
                $q->where('voucher_codes.tenant_id', $tenantId);
            }

            if ($search) {
                $q->where('voucher_codes.code', 'like', "%{$search}%");
            }
            if ($dateFrom) {
                $q->where('voucher_transactions.created_at', '>=', $dateFrom . ' 00:00:00');
            }
            if ($dateTo) {
                $q->where('voucher_transactions.created_at', '<=', $dateTo . ' 23:59:59');
            }

            $voucherStats = $q->selectRaw("
                COUNT(*) as total,
                COALESCE(SUM(CASE WHEN voucher_transactions.type = 'load' THEN voucher_transactions.amount ELSE 0 END), 0) as loads,
                COALESCE(SUM(CASE WHEN voucher_transactions.type = 'cashout' THEN ABS(voucher_transactions.amount) ELSE 0 END), 0) as cashouts,
                COALESCE(SUM(CASE WHEN voucher_transactions.type = 'loss' THEN ABS(voucher_transactions.amount) ELSE 0 END), 0) as losses,
                COALESCE(SUM(CASE WHEN voucher_transactions.type = 'win' THEN voucher_transactions.amount ELSE 0 END), 0) as wins
            ")->first();

            $stats['total_transactions'] += $voucherStats->total;
            $stats['total_deposits'] = bcadd($stats['total_deposits'], (string) $voucherStats->loads, 2);
            $stats['total_withdrawals'] = bcadd($stats['total_withdrawals'], (string) $voucherStats->cashouts, 2);
            $stats['total_bets'] = bcadd($stats['total_bets'], (string) $voucherStats->losses, 2);
            $stats['total_wins'] = bcadd($stats['total_wins'], (string) $voucherStats->wins, 2);
        }

        return $stats;
    }

    private function emptyStats(): array
    {
        return [
            'total_transactions' => 0,
            'total_deposits' => '0.00',
            'total_withdrawals' => '0.00',
            'total_bets' => '0.00',
            'total_wins' => '0.00',
        ];
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/Admin/WalletTransactionController.php
git commit -m "feat: add WalletTransactionController for unified transaction listing"
```

---

### Task 2: Routes — Web + API

**Files:**
- Modify: `routes/web.php:25` (add wallet-transactions web route)
- Modify: `routes/admin.php:88` (add wallet-transactions API route)
- Modify: `app/Http/Controllers/Admin/DashboardController.php:103` (add walletTransactions method)

- [ ] **Step 1: Add Inertia page render method to DashboardController**

Add after the `wallets()` method at line 102:

```php
/**
 * Display wallet transactions page.
 */
public function walletTransactions(): Response
{
    return Inertia::render('admin/wallet-transactions');
}
```

- [ ] **Step 2: Add web route**

In `routes/web.php`, add after line 25 (`Route::get('wallets', ...)`):

```php
Route::get('wallet-transactions', [DashboardController::class, 'walletTransactions'])->name('admin.wallet-transactions');
```

- [ ] **Step 3: Add API route**

In `routes/admin.php`, add the use statement at the top:

```php
use App\Http\Controllers\Admin\WalletTransactionController;
```

Then add after line 88 (after the wallet activate route):

```php
// Wallet Transactions
Route::get('wallet-transactions', [WalletTransactionController::class, 'index'])->name('wallet-transactions.index');
```

- [ ] **Step 4: Commit**

```bash
git add routes/web.php routes/admin.php app/Http/Controllers/Admin/DashboardController.php
git commit -m "feat: add routes for wallet transactions page"
```

---

### Task 3: Frontend — Wallet Transactions Page

**Files:**
- Create: `resources/js/Pages/Admin/wallet-transactions.tsx`

- [ ] **Step 1: Create the React page**

```tsx
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
            params.append('page', page.toString());

            const response = await fetch(`/api/v1/admin/wallet-transactions?${params}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            if (data.success) {
                setTransactions(data.data);
                setMeta(data.meta);
                if (data.stats) setStats(data.stats);
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
    }, [page, sourceFilter, typeFilter, dateFrom, dateTo]);

    const handleSearch = () => {
        setPage(1);
        fetchTransactions();
    };

    const handleClearFilters = () => {
        setSearch('');
        setTypeFilter([]);
        setSourceFilter('all');
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
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Pages/Admin/wallet-transactions.tsx
git commit -m "feat: add wallet transactions admin page"
```

---

### Task 4: Sidebar — Add "Wallet Transactions" nav item

**Files:**
- Modify: `resources/js/layouts/user-layout.tsx:19`

- [ ] **Step 1: Add sidebar item**

In `resources/js/layouts/user-layout.tsx`, add after the Wallets item (line 19):

```typescript
{ label: 'Wallet Transactions', icon: 'pi pi-arrow-right-arrow-left', href: '/admin/wallet-transactions' },
```

The `adminGroup` items array should now be:
```typescript
items: [
    { label: 'Admin Dashboard', icon: 'pi pi-th-large', href: '/admin' },
    { label: 'Users', icon: 'pi pi-users', href: '/admin/users' },
    { label: 'Wallets', icon: 'pi pi-credit-card', href: '/admin/wallets' },
    { label: 'Wallet Transactions', icon: 'pi pi-arrow-right-arrow-left', href: '/admin/wallet-transactions' },
    { label: 'Voucher Codes', icon: 'pi pi-ticket', href: '/admin/voucher-codes' },
    { label: 'Reports', icon: 'pi pi-chart-bar', href: '/admin/reports' },
    { label: 'Audit Logs', icon: 'pi pi-list', href: '/admin/audit-logs' },
],
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/layouts/user-layout.tsx
git commit -m "feat: add Wallet Transactions to admin sidebar"
```

---

### Task 5: Wallets Page — Add "View Transactions" link

**Files:**
- Modify: `resources/js/Pages/Admin/wallets.tsx:320-361`

- [ ] **Step 1: Add View Transactions button to the actions column**

In `resources/js/Pages/Admin/wallets.tsx`, update the `actionsTemplate` function. Add a "View Transactions" link button before the existing conditional buttons:

```tsx
const actionsTemplate = (row: Wallet) => (
    <div className="flex gap-1">
        <Button
            icon="pi pi-list"
            text
            severity="info"
            size="small"
            tooltip="View Transactions"
            onClick={() => window.location.href = `/admin/wallet-transactions?wallet=${row.uuid}`}
        />
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
```

- [ ] **Step 2: Widen the actions column**

Update the actions Column width from `8rem` to `10rem`:

```tsx
<Column header="" body={actionsTemplate} style={{ width: '10rem' }} />
```

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Admin/wallets.tsx
git commit -m "feat: add View Transactions button to wallets page"
```

---

### Task 6: Verify — Build and test

- [ ] **Step 1: Build frontend assets**

```bash
npm run build
```

Expected: Build succeeds with no TypeScript errors.

- [ ] **Step 2: Run PHP linting**

```bash
php artisan route:list --path=wallet-transactions
```

Expected: Shows both the web route and API route for wallet-transactions.

- [ ] **Step 3: Manual verification checklist**

1. Navigate to `/admin/wallet-transactions` — page loads with empty state
2. Navigate to `/admin/wallets` — "View Transactions" button visible on each row
3. Click "View Transactions" on a wallet — redirects to `/admin/wallet-transactions?wallet={uuid}`
4. Filters work: search, type multi-select, source dropdown, date pickers
5. Stats update when filters change
6. Pagination works

- [ ] **Step 4: Final commit**

```bash
git add -A
git commit -m "chore: verify wallet transactions page build"
```
