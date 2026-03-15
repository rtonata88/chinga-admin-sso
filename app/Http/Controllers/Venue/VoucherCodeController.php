<?php

namespace App\Http\Controllers\Venue;

use App\Http\Controllers\Controller;
use App\Models\VoucherCode;
use App\Services\Venue\VoucherCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VoucherCodeController extends Controller
{
    public function __construct(
        protected VoucherCodeService $voucherCodeService
    ) {}

    /**
     * List voucher codes for the venue.
     */
    public function index(Request $request): JsonResponse
    {
        $staff = $request->user();
        $venue = $staff->venue;

        $query = VoucherCode::where('venue_id', $venue->id)
            ->with(['createdBy:id,display_name']);

        // Filters
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->input('search')) {
            $query->where('code', 'like', "%{$search}%");
        }

        if ($request->boolean('has_balance')) {
            $query->where('balance', '>', 0);
        }

        $codes = $query->orderByDesc('created_at')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'codes' => $codes->through(fn ($code) => [
                'uuid' => $code->uuid,
                'code' => $code->code,
                'masked_code' => $code->masked_code,
                'balance' => $code->balance,
                'currency' => $code->currency,
                'status' => $code->status,
                'has_pin' => $code->hasPin(),
                'created_by' => $code->createdBy?->display_name,
                'expires_at' => $code->expires_at?->toIso8601String(),
                'last_activity_at' => $code->last_activity_at?->toIso8601String(),
                'created_at' => $code->created_at->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $codes->currentPage(),
                'last_page' => $codes->lastPage(),
                'per_page' => $codes->perPage(),
                'total' => $codes->total(),
            ],
        ]);
    }

    /**
     * Create a new voucher code.
     */
    public function store(Request $request): JsonResponse
    {
        $staff = $request->user();

        if (!$staff->canCreateCodes()) {
            return response()->json([
                'message' => 'You do not have permission to create codes.',
            ], 403);
        }

        $request->validate([
            'initial_balance' => ['nullable', 'numeric', 'min:0'],
            'pin' => ['nullable', 'string', 'size:4', 'regex:/^[0-9]{4}$/'],
            'expiry_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
        ]);

        try {
            $code = $this->voucherCodeService->createCode(
                $staff->venue,
                $staff,
                $request->input('initial_balance'),
                $request->input('pin'),
                $request->input('expiry_hours')
            );

            // Record in shift if active
            if ($shift = $staff->currentShift()) {
                $shift->recordCodeCreated();
                if ($request->input('initial_balance') > 0) {
                    $shift->recordLoad($request->input('initial_balance'));
                }
            }

            return response()->json([
                'message' => 'Voucher code created successfully.',
                'code' => [
                    'uuid' => $code->uuid,
                    'code' => $code->code, // Show full code on creation
                    'balance' => $code->balance,
                    'currency' => $code->currency,
                    'status' => $code->status,
                    'has_pin' => $code->hasPin(),
                    'expires_at' => $code->expires_at?->toIso8601String(),
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get voucher code details.
     */
    public function show(Request $request, string $code): JsonResponse
    {
        $staff = $request->user();

        $voucherCode = $this->voucherCodeService->findCodeAtVenue(
            $code,
            $staff->venue
        );

        if (!$voucherCode) {
            return response()->json([
                'message' => 'Voucher code not found.',
            ], 404);
        }

        return response()->json([
            'code' => [
                'uuid' => $voucherCode->uuid,
                'code' => $voucherCode->code,
                'masked_code' => $voucherCode->masked_code,
                'balance' => $voucherCode->balance,
                'currency' => $voucherCode->currency,
                'status' => $voucherCode->status,
                'has_pin' => $voucherCode->hasPin(),
                'is_expired' => $voucherCode->isExpired(),
                'total_loaded' => $voucherCode->total_loaded,
                'total_won' => $voucherCode->total_won,
                'total_lost' => $voucherCode->total_lost,
                'total_cashed_out' => $voucherCode->total_cashed_out,
                'created_by' => $voucherCode->createdBy?->display_name,
                'expires_at' => $voucherCode->expires_at?->toIso8601String(),
                'last_activity_at' => $voucherCode->last_activity_at?->toIso8601String(),
                'created_at' => $voucherCode->created_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Quick balance check.
     */
    public function balance(Request $request, string $code): JsonResponse
    {
        $staff = $request->user();

        $voucherCode = $this->voucherCodeService->findCodeAtVenue(
            $code,
            $staff->venue
        );

        if (!$voucherCode) {
            return response()->json([
                'message' => 'Voucher code not found.',
            ], 404);
        }

        return response()->json([
            'code' => $voucherCode->masked_code,
            'balance' => $voucherCode->balance,
            'currency' => $voucherCode->currency,
            'status' => $voucherCode->status,
        ]);
    }

    /**
     * Load credits onto a voucher code.
     */
    public function load(Request $request, string $code): JsonResponse
    {
        $staff = $request->user();

        if (!$staff->canLoadCredits()) {
            return response()->json([
                'message' => 'You do not have permission to load credits.',
            ], 403);
        }

        $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $voucherCode = $this->voucherCodeService->findCodeAtVenue(
            $code,
            $staff->venue
        );

        if (!$voucherCode) {
            return response()->json([
                'message' => 'Voucher code not found.',
            ], 404);
        }

        if ($voucherCode->status === 'deactivated') {
            return response()->json([
                'message' => 'Cannot load credits onto a deactivated code.',
            ], 422);
        }

        try {
            $transaction = $this->voucherCodeService->loadCredits(
                $voucherCode,
                $request->input('amount'),
                $staff,
                $request->input('description')
            );

            // Record in shift if active
            if ($shift = $staff->currentShift()) {
                $shift->recordLoad($request->input('amount'));
            }

            return response()->json([
                'message' => 'Credits loaded successfully.',
                'transaction' => [
                    'uuid' => $transaction->uuid,
                    'amount' => $transaction->amount,
                    'balance_after' => $transaction->balance_after,
                ],
                'code' => [
                    'balance' => $voucherCode->fresh()->balance,
                    'status' => $voucherCode->status,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Cash out a voucher code.
     */
    public function cashout(Request $request, string $code): JsonResponse
    {
        $staff = $request->user();

        if (!$staff->canCashout()) {
            return response()->json([
                'message' => 'You do not have permission to process cashouts.',
            ], 403);
        }

        $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $voucherCode = $this->voucherCodeService->findCodeAtVenue(
            $code,
            $staff->venue
        );

        if (!$voucherCode) {
            return response()->json([
                'message' => 'Voucher code not found.',
            ], 404);
        }

        if ($voucherCode->balance <= 0) {
            return response()->json([
                'message' => 'No balance to cash out.',
            ], 422);
        }

        try {
            $amount = $request->input('amount') ?? $voucherCode->balance;

            $transaction = $this->voucherCodeService->cashout(
                $voucherCode,
                $staff,
                $amount,
                $request->input('description')
            );

            // Record in shift if active
            if ($shift = $staff->currentShift()) {
                $shift->recordCashout($amount);
            }

            return response()->json([
                'message' => 'Cashout processed successfully.',
                'transaction' => [
                    'uuid' => $transaction->uuid,
                    'amount' => abs($transaction->amount),
                    'balance_after' => $transaction->balance_after,
                ],
                'code' => [
                    'balance' => $voucherCode->fresh()->balance,
                    'status' => $voucherCode->fresh()->status,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Deactivate a voucher code.
     */
    public function deactivate(Request $request, string $code): JsonResponse
    {
        $staff = $request->user();

        if (!$staff->isManager()) {
            return response()->json([
                'message' => 'Only managers can deactivate codes.',
            ], 403);
        }

        $voucherCode = $this->voucherCodeService->findCodeAtVenue(
            $code,
            $staff->venue
        );

        if (!$voucherCode) {
            return response()->json([
                'message' => 'Voucher code not found.',
            ], 404);
        }

        $this->voucherCodeService->deactivate($voucherCode, $staff);

        return response()->json([
            'message' => 'Voucher code deactivated.',
            'code' => [
                'status' => 'deactivated',
                'balance' => $voucherCode->balance,
            ],
        ]);
    }

    /**
     * Transfer balance between codes.
     */
    public function transfer(Request $request, string $code): JsonResponse
    {
        $staff = $request->user();

        if (!$staff->isManager()) {
            return response()->json([
                'message' => 'Only managers can transfer balances.',
            ], 403);
        }

        $request->validate([
            'to_code' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $fromCode = $this->voucherCodeService->findCodeAtVenue(
            $code,
            $staff->venue
        );

        $toCode = $this->voucherCodeService->findCodeAtVenue(
            $request->input('to_code'),
            $staff->venue
        );

        if (!$fromCode || !$toCode) {
            return response()->json([
                'message' => 'One or both voucher codes not found.',
            ], 404);
        }

        try {
            $this->voucherCodeService->transfer(
                $fromCode,
                $toCode,
                $request->input('amount'),
                $staff
            );

            return response()->json([
                'message' => 'Transfer completed successfully.',
                'from_code' => [
                    'code' => $fromCode->masked_code,
                    'balance' => $fromCode->fresh()->balance,
                ],
                'to_code' => [
                    'code' => $toCode->masked_code,
                    'balance' => $toCode->fresh()->balance,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Set or update PIN for a code.
     */
    public function setPin(Request $request, string $code): JsonResponse
    {
        $staff = $request->user();

        $request->validate([
            'pin' => ['required', 'string', 'size:4', 'regex:/^[0-9]{4}$/'],
        ]);

        $voucherCode = $this->voucherCodeService->findCodeAtVenue(
            $code,
            $staff->venue
        );

        if (!$voucherCode) {
            return response()->json([
                'message' => 'Voucher code not found.',
            ], 404);
        }

        $voucherCode->setPin($request->input('pin'));

        return response()->json([
            'message' => 'PIN set successfully.',
        ]);
    }

    /**
     * Extend voucher code expiration.
     */
    public function extend(Request $request, string $code): JsonResponse
    {
        $staff = $request->user();

        if (!$staff->isManager()) {
            return response()->json([
                'message' => 'Only managers can extend code expiration.',
            ], 403);
        }

        $request->validate([
            'hours' => ['required', 'integer', 'min:1', 'max:8760'],
        ]);

        $voucherCode = $this->voucherCodeService->findCodeAtVenue(
            $code,
            $staff->venue
        );

        if (!$voucherCode) {
            return response()->json([
                'message' => 'Voucher code not found.',
            ], 404);
        }

        $voucherCode->update([
            'expires_at' => now()->addHours($request->input('hours')),
            'status' => $voucherCode->status === 'expired' ? 'active' : $voucherCode->status,
        ]);

        return response()->json([
            'message' => 'Expiration extended.',
            'expires_at' => $voucherCode->fresh()->expires_at->toIso8601String(),
        ]);
    }

    /**
     * Get transaction history for a code.
     */
    public function transactions(Request $request, string $code): JsonResponse
    {
        $staff = $request->user();

        $voucherCode = $this->voucherCodeService->findCodeAtVenue(
            $code,
            $staff->venue
        );

        if (!$voucherCode) {
            return response()->json([
                'message' => 'Voucher code not found.',
            ], 404);
        }

        $transactions = $voucherCode->transactions()
            ->with(['performedBy:id,display_name'])
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 50));

        return response()->json([
            'transactions' => $transactions->through(fn ($tx) => [
                'uuid' => $tx->uuid,
                'type' => $tx->type,
                'type_label' => $tx->type_label,
                'amount' => $tx->amount,
                'balance_before' => $tx->balance_before,
                'balance_after' => $tx->balance_after,
                'description' => $tx->description,
                'reference' => $tx->reference,
                'performed_by' => $tx->performedBy?->display_name,
                'created_at' => $tx->created_at->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }
}
