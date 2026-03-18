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
        $gameFilter = $request->input('game');
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
                ->leftJoin('game_sessions', 'wallet_transactions.game_session_id', '=', 'game_sessions.id')
                ->leftJoin('games', 'game_sessions.game_id', '=', 'games.id')
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
                    'games.name as game_name',
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

            if ($gameFilter && $walletQuery) {
                $walletQuery->where('game_sessions.game_id', $gameFilter);
            }
        }

        // Voucher transactions query
        if ($source !== 'wallet' && !$walletUuid) {
            $voucherQuery = DB::table('voucher_transactions')
                ->join('voucher_codes', 'voucher_transactions.voucher_code_id', '=', 'voucher_codes.id')
                ->join('venues', 'voucher_codes.venue_id', '=', 'venues.id')
                ->leftJoin('venue_staff', 'voucher_transactions.performed_by_staff_id', '=', 'venue_staff.id')
                ->leftJoin('game_sessions', 'voucher_transactions.game_session_id', '=', 'game_sessions.id')
                ->leftJoin('games', 'game_sessions.game_id', '=', 'games.id')
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
                    'games.name as game_name',
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

            if ($gameFilter && $voucherQuery) {
                $voucherQuery->where('game_sessions.game_id', $gameFilter);
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
        $stats = $this->computeStats($source, $search, $type, $dateFrom, $dateTo, $walletUuid, $tenantId, $gameFilter);

        $games = DB::table('games')
            ->select('games.id', 'games.name')
            ->orderBy('games.name')
            ->get();

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
            'games' => $games,
        ]);
    }

    private function computeStats(
        string $source,
        ?string $search,
        mixed $type,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $walletUuid,
        ?int $tenantId,
        ?string $gameFilter = null
    ): array {
        $stats = $this->emptyStats();

        if ($source !== 'voucher') {
            $q = DB::table('wallet_transactions')
                ->join('wallets', 'wallet_transactions.wallet_id', '=', 'wallets.id')
                ->join('users', 'wallets.user_id', '=', 'users.id');

            if ($gameFilter) {
                $q->join('game_sessions', 'wallet_transactions.game_session_id', '=', 'game_sessions.id')
                  ->where('game_sessions.game_id', $gameFilter);
            }

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

            if ($gameFilter) {
                $q->join('game_sessions', 'voucher_transactions.game_session_id', '=', 'game_sessions.id')
                  ->where('game_sessions.game_id', $gameFilter);
            }

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
