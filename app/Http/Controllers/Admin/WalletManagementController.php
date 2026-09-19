<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WalletManagementController extends Controller
{
    public function __construct(
        protected WalletService $walletService
    ) {}

    /**
     * List wallets for current tenant with search/filter/pagination and stats.
     */
    public function index(Request $request): JsonResponse
    {
        // The operator is part of the identity of a wallet: the same email can hold one account, and
        // one wallet, under several tenants. A platform admin's list spans them all.
        $query = Wallet::with(['user', 'tenant:id,uuid,slug,name']);

        // Search by user name or email
        if ($search = $request->input('search')) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Filter by currency
        if ($currency = $request->input('currency')) {
            $query->where('currency', $currency);
        }

        // Sort
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);

        $wallets = $query->paginate($request->input('per_page', 25));

        // Stats
        $statsQuery = Wallet::query();
        $stats = [
            'total_wallets' => $statsQuery->count(),
            'active_wallets' => (clone $statsQuery)->where('status', 'active')->count(),
            'frozen_wallets' => (clone $statsQuery)->where('status', 'frozen')->count(),
            'closed_wallets' => (clone $statsQuery)->where('status', 'closed')->count(),
            'total_balance' => (clone $statsQuery)->where('status', 'active')->sum('balance'),
        ];

        return response()->json([
            'success' => true,
            'data' => $wallets->items(),
            'meta' => [
                'current_page' => $wallets->currentPage(),
                'last_page' => $wallets->lastPage(),
                'per_page' => $wallets->perPage(),
                'total' => $wallets->total(),
            ],
            'stats' => $stats,
        ]);
    }

    /**
     * Show wallet details with recent transactions.
     */
    public function show(Wallet $wallet): JsonResponse
    {
        $wallet->load(['user', 'tenant:id,uuid,slug,name']);
        $recentTransactions = $wallet->transactions()
            ->with('performedBy')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'wallet' => $wallet,
                'transactions' => $recentTransactions,
            ],
        ]);
    }

    /**
     * Deposit funds into a wallet.
     */

    /**
     * Money moves on a wallet only by an admin of the tenant that owns it. A platform admin
     * has no tenant context, sees every operator's wallets, and must not create or remove
     * balance on an operator's books: that operator's own admin does it, on their own
     * ledger. Freezing stays available to everyone as a safety action.
     */
    private function refuseUnlessOwnTenant(Request $request, Wallet $wallet): ?JsonResponse
    {
        $actor = $request->user();
        if ($actor && $actor->tenant_id !== null && (int) $actor->tenant_id === (int) $wallet->tenant_id) {
            return null;
        }
        $owner = Tenant::withoutGlobalScopes()->find($wallet->tenant_id);
        Log::warning('wallet.cross_tenant_money_refused', [
            'actor_id' => $actor?->id,
            'actor_tenant_id' => $actor?->tenant_id,
            'wallet_id' => $wallet->id,
            'wallet_tenant_id' => $wallet->tenant_id,
        ]);

        return response()->json([
            'success' => false,
            'code' => 'cross_tenant_wallet',
            'message' => $owner
                ? "This wallet belongs to {$owner->name}. Only an admin of {$owner->name} can add or remove balance on it."
                : 'Only an admin of the tenant that owns this wallet can add or remove balance on it.',
        ], 403);
    }

    public function deposit(Request $request, Wallet $wallet): JsonResponse
    {
        if ($refused = $this->refuseUnlessOwnTenant($request, $wallet)) {
            return $refused;
        }
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'nullable|string|max:255',
        ]);

        try {
            $transaction = $this->walletService->deposit(
                $wallet,
                (string) $validated['amount'],
                $request->user(),
                $validated['reference'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Deposit successful.',
                'data' => $transaction,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Withdraw funds from a wallet.
     */
    public function withdraw(Request $request, Wallet $wallet): JsonResponse
    {
        if ($refused = $this->refuseUnlessOwnTenant($request, $wallet)) {
            return $refused;
        }
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'nullable|string|max:255',
        ]);

        try {
            $transaction = $this->walletService->withdraw(
                $wallet,
                (string) $validated['amount'],
                $request->user(),
                $validated['reference'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Withdrawal successful.',
                'data' => $transaction,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Freeze a wallet.
     */
    public function freeze(Wallet $wallet): JsonResponse
    {
        if ($wallet->status === 'frozen') {
            return response()->json([
                'success' => false,
                'message' => 'Wallet is already frozen.',
            ], 422);
        }

        $wallet->update(['status' => 'frozen']);

        return response()->json([
            'success' => true,
            'message' => 'Wallet frozen successfully.',
        ]);
    }

    /**
     * Activate a wallet.
     */
    public function activate(Wallet $wallet): JsonResponse
    {
        if ($wallet->status === 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Wallet is already active.',
            ], 422);
        }

        $wallet->update(['status' => 'active']);

        return response()->json([
            'success' => true,
            'message' => 'Wallet activated successfully.',
        ]);
    }
}
