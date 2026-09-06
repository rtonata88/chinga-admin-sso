<?php

namespace App\Http\Controllers;

use App\Models\UserSession;
use App\Services\FantasyAdminClient;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class UserDashboardController extends Controller
{
    public function __invoke(FantasyAdminClient $fantasy): Response
    {
        $user = auth()->user();

        $isAdmin = $user->isPlatformAdmin() || $user->isTenantAdmin();
        // chinga-fantasy stores the SSO tenant slug in its `tenant_uuid`
        // column (TODO: backfill to real UUID — see DashboardController).
        // Pass slug here so a tenant admin's dashboard is correctly
        // scoped. Platform admins keep null → cross-tenant view.
        $tenantUuid = null;
        if ($isAdmin && !$user->isPlatformAdmin()) {
            $tenant = optional($user->tenant);
            $tenantUuid = $tenant->slug ?: $tenant->uuid;
        }

        // Account overview
        $account = [
            'name' => $user->name,
            'email' => $user->email,
            'display_name' => $user->display_name,
            'avatar_url' => $user->avatar_url,
            'status' => $user->status ?? 'active',
            'email_verified' => $user->email_verified_at !== null,
            'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
            'member_since' => $user->created_at->toIso8601String(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];

        // Recent sessions
        $sessions = UserSession::where('user_id', $user->id)
            ->orderBy('last_active_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($session) => [
                'device_type' => $session->device_type,
                'browser' => $session->browser,
                'platform' => $session->platform,
                'ip_address' => $session->ip_address,
                'city' => $session->city,
                'country_code' => $session->country_code,
                'is_current' => $session->is_current,
                'last_active_at' => $session->last_active_at?->toIso8601String(),
            ]);

        $activeSessionCount = UserSession::where('user_id', $user->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->count();

        // Wallet data — only meaningful for non-admin player accounts.
        $walletData = $this->walletData($user);

        // Live wager metrics — admins only. The chinga-fantasy backend
        // owns the source of truth; we read it through the
        // service-to-service FantasyAdminClient. Anything that throws
        // is silently swallowed so the dashboard never 500s — the page
        // will render zero-state cards if the upstream is down.
        $wagerStats = null;
        $wagerSpark = null;
        $recentBets = [];

        if ($isAdmin) {
            try {
                $now = Carbon::now();
                $today = $now->copy()->startOfDay();
                $yesterday = $today->copy()->subDay();

                $todayStats = $fantasy->statsSummary($tenantUuid, $today->toIso8601String(), $now->toIso8601String());
                $yesterdayStats = $fantasy->statsSummary(
                    $tenantUuid,
                    $yesterday->toIso8601String(),
                    $today->toIso8601String(),
                );

                $weekStart = $now->copy()->subDays(7)->startOfDay();
                $byDay = $fantasy->statsByDay($tenantUuid, $weekStart->toIso8601String(), $now->toIso8601String());

                $wagerStats = [
                    'today' => $todayStats,
                    'yesterday' => $yesterdayStats,
                ];
                // Pull a small numeric series for the Handle sparkline. AdminController
                // returns rows with `bets_placed` / `total_wagered` keys.
                $wagerSpark = collect($byDay['rows'] ?? $byDay['days'] ?? [])
                    ->map(fn ($row) => (float) ($row['total_wagered'] ?? 0))
                    ->values()
                    ->take(-8)
                    ->all();

                // Recent bets across rounds — pre-joined with team picks,
                // combined odds, and potential payout. Player names get
                // resolved via a single whereIn lookup against the local
                // SSO users table; uuids missing from there fall back to
                // a short uuid label.
                $betsResp = $fantasy->recentBets($tenantUuid, 10);
                $rawBets = $betsResp['data'] ?? [];

                $userUuids = collect($rawBets)->pluck('user_uuid')->filter()->unique()->values()->all();
                $usersByUuid = empty($userUuids)
                    ? collect()
                    : \App\Models\User::whereIn('uuid', $userUuids)->get()->keyBy('uuid');

                $tenantUuids = collect($rawBets)->pluck('tenant_uuid')->filter()->unique()->values()->all();
                $tenantsByUuid = empty($tenantUuids)
                    ? collect()
                    : \App\Models\Tenant::whereIn('uuid', $tenantUuids)->get()->keyBy('uuid');

                $recentBets = collect($rawBets)
                    ->map(function ($b) use ($usersByUuid, $tenantsByUuid) {
                        $uuid = $b['user_uuid'] ?? null;
                        $u = $uuid ? ($usersByUuid->get($uuid)) : null;
                        $name = $u?->display_name ?? $u?->name ?? ($uuid ? 'Player ' . substr($uuid, 0, 6) : '—');
                        $initials = strtoupper(
                            collect(explode(' ', trim($name)))
                                ->filter()
                                ->take(2)
                                ->map(fn ($p) => $p[0] ?? '')
                                ->implode('')
                        ) ?: '??';

                        $tUuid = $b['tenant_uuid'] ?? null;
                        $tenant = $tUuid ? ($tenantsByUuid->get($tUuid)) : null;

                        return [
                            'id' => $b['id'] ?? null,
                            'placed_at' => $b['placed_at'] ?? null,
                            'player' => [
                                'name' => $name,
                                'uuid_short' => $uuid ? strtoupper(substr($uuid, 0, 6)) : null,
                                'initials' => $initials,
                            ],
                            'tenant_uuid' => $tUuid,
                            'tenant_name' => $tenant?->name ?? null,
                            'round_number' => $b['round_number'] ?? null,
                            'team_names' => $b['team_names'] ?? [],
                            'bet_amount' => isset($b['bet_amount']) ? (float) $b['bet_amount'] : 0,
                            'combined_odds' => isset($b['combined_odds']) ? (float) $b['combined_odds'] : 0,
                            'potential_payout' => isset($b['potential_payout']) ? (float) $b['potential_payout'] : 0,
                            'outcome' => $b['outcome'] ?? 'pending',
                            'winning_amount' => isset($b['winning_amount']) ? (float) $b['winning_amount'] : 0,
                        ];
                    })
                    ->all();
            } catch (Throwable $e) {
                logger()->warning('UserDashboardController fantasy stats fetch failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return Inertia::render('dashboard', [
            'account' => $account,
            'sessions' => $sessions,
            'active_session_count' => $activeSessionCount,
            'wallet' => $walletData,
            'is_admin' => $isAdmin,
            'wager_stats' => $wagerStats,
            'wager_spark' => $wagerSpark,
            'recent_bets' => $recentBets,
            'last_updated' => Carbon::now('Africa/Windhoek')->format('H:i:s') . ' CAT',
        ]);
    }

    private function walletData($user): ?array
    {
        $wallet = $user->wallet ?? null;
        if (!$wallet) {
            return null;
        }

        $recentTransactions = $wallet->transactions()
            ->with('gameSession.game')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($t) => [
                'type' => $t->type,
                'amount' => (float) $t->amount,
                'balance_after' => (float) $t->balance_after,
                'description' => $t->description,
                'game_name' => $t->gameSession?->game?->name,
                'created_at' => $t->created_at->toIso8601String(),
            ]);

        return [
            'balance' => (float) $wallet->balance,
            'currency' => $wallet->currency ?? 'NAD',
            'status' => $wallet->status,
            'total_deposited' => (float) $wallet->total_deposited,
            'total_withdrawn' => (float) $wallet->total_withdrawn,
            'total_won' => (float) $wallet->total_won,
            'total_lost' => (float) $wallet->total_lost,
            'recent_transactions' => $recentTransactions,
        ];
    }
}
