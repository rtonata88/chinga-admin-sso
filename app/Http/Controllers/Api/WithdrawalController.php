<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Venue;
use App\Models\WithdrawalRequest;
use App\Services\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Player-facing withdrawal API. Mounted under `auth:api` so $request->user()
 * is the player. All endpoints scope to the calling player's own withdrawals.
 */
class WithdrawalController extends Controller
{
    public function __construct(
        protected WithdrawalService $service,
    ) {}

    /**
     * GET /api/v1/wallet/withdrawals/options
     * Allowed methods + limits + venues for this player's tenant — used by the
     * Cash Out form to render only relevant options.
     */
    public function options(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = Tenant::findOrFail($user->tenant_id);
        $config = $this->service->tenantConfig($tenant);
        $wallet = $user->getOrCreateWallet();

        $venues = Venue::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['uuid', 'name', 'city']);

        return response()->json([
            'data' => [
                'allowed_methods' => $config['allowed_methods'],
                'min_amount' => $config['min_amount'],
                'max_amount' => $config['max_amount'],
                'daily_limit' => $config['daily_limit'],
                'fee_pct' => $config['fee_pct'],
                'auto_approve_under' => $config['auto_approve_under'],
                'currency' => $wallet->currency,
                'available_balance' => $wallet->balance,
                'venues' => $venues,
            ],
        ]);
    }

    /**
     * GET /api/v1/wallet/withdrawals
     * List the calling player's withdrawal requests, most-recent first.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $requests = WithdrawalRequest::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit((int) $request->query('limit', 50))
            ->get();

        return response()->json(['data' => $requests->map(fn ($r) => $this->present($r))]);
    }

    /**
     * POST /api/v1/wallet/withdrawals
     * Create a withdrawal request. Wallet is debited immediately (held).
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', 'string'],
            'payment_details' => ['nullable', 'array'],
        ]);

        try {
            $withdrawal = $this->service->request($request->user(), [
                'amount' => (string) $request->input('amount'),
                'payment_method' => $request->input('payment_method'),
                'payment_details' => $request->input('payment_details', []),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'Insufficient') ? 402 : 422;
            return response()->json(['message' => $e->getMessage()], $code);
        }

        return response()->json(['data' => $this->present($withdrawal)], 201);
    }

    /**
     * POST /api/v1/wallet/withdrawals/{uuid}/cancel
     */
    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $withdrawal = WithdrawalRequest::where('uuid', $uuid)->firstOrFail();
        if ($withdrawal->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not your withdrawal.'], 403);
        }
        try {
            $withdrawal = $this->service->cancel($withdrawal, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['data' => $this->present($withdrawal)]);
    }

    private function present(WithdrawalRequest $r): array
    {
        return [
            'uuid' => $r->uuid,
            'amount' => $r->amount,
            'fee_amount' => $r->fee_amount,
            'net_amount' => $r->net_amount,
            'currency' => $r->currency,
            'payment_method' => $r->payment_method,
            'payment_details' => $r->payment_details,
            'status' => $r->status,
            'external_reference' => $r->external_reference,
            'rejection_reason' => $r->rejection_reason,
            'created_at' => $r->created_at?->toIso8601String(),
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'paid_at' => $r->paid_at?->toIso8601String(),
        ];
    }
}
