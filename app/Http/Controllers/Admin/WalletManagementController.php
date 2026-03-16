<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $query = Wallet::with('user');

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
        $wallet->load('user');
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
    public function deposit(Request $request, Wallet $wallet): JsonResponse
    {
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
