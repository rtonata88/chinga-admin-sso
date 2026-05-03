<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WithdrawalRequest;
use App\Services\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-facing withdrawal queue API. Tenant admins see only their tenant's
 * withdrawals; platform admins see everything (handled by EnsureTenantAdmin
 * + tenant scoping below).
 */
class WithdrawalController extends Controller
{
    public function __construct(
        protected WithdrawalService $service,
    ) {}

    /**
     * GET /api/v1/admin/withdrawals
     * Filterable queue. Tenant-scoped automatically for non-platform admins.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = WithdrawalRequest::with([
            'user:id,uuid,name,email,username',
            'tenant:id,uuid,name,slug',
            'reviewedBy:id,name',
            'paidBy:id,name',
        ])->orderBy('status')->orderByDesc('created_at');

        if (!$user->isPlatformAdmin()) {
            $tenant = app('current_tenant');
            $tenantId = $tenant?->id ?? $user->tenant_id;
            $query->where('tenant_id', $tenantId);
        }

        if ($status = $request->query('status')) {
            $statuses = is_array($status) ? $status : explode(',', $status);
            $query->whereIn('status', $statuses);
        }

        $limit = min((int) $request->query('limit', 100), 500);
        $items = $query->limit($limit)->get();

        // Aggregate counts per status for the queue header.
        $countsQuery = WithdrawalRequest::query();
        if (!$user->isPlatformAdmin()) {
            $tenant = app('current_tenant');
            $tenantId = $tenant?->id ?? $user->tenant_id;
            $countsQuery->where('tenant_id', $tenantId);
        }
        $counts = $countsQuery
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        return response()->json([
            'data' => $items->map(fn ($r) => $this->present($r)),
            'counts' => $counts,
        ]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $w = $this->find($request, $uuid);
        $w->load(['user', 'tenant', 'reviewedBy', 'paidBy', 'holdTransaction', 'refundTransaction']);
        return response()->json(['data' => $this->present($w, full: true)]);
    }

    public function approve(Request $request, string $uuid): JsonResponse
    {
        $w = $this->find($request, $uuid);
        $request->validate(['notes' => ['nullable', 'string', 'max:500']]);
        try {
            $w = $this->service->approve($w, $request->user(), $request->input('notes'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['data' => $this->present($w)]);
    }

    public function reject(Request $request, string $uuid): JsonResponse
    {
        $w = $this->find($request, $uuid);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $w = $this->service->reject($w, $request->user(), $validated['reason']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['data' => $this->present($w)]);
    }

    public function markPaid(Request $request, string $uuid): JsonResponse
    {
        $w = $this->find($request, $uuid);
        $request->validate(['external_reference' => ['nullable', 'string', 'max:255']]);
        try {
            $w = $this->service->markPaid($w, $request->user(), $request->input('external_reference'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['data' => $this->present($w)]);
    }

    private function find(Request $request, string $uuid): WithdrawalRequest
    {
        $query = WithdrawalRequest::where('uuid', $uuid);
        $user = $request->user();
        if (!$user->isPlatformAdmin()) {
            $tenant = app('current_tenant');
            $tenantId = $tenant?->id ?? $user->tenant_id;
            $query->where('tenant_id', $tenantId);
        }
        return $query->firstOrFail();
    }

    private function present(WithdrawalRequest $r, bool $full = false): array
    {
        $base = [
            'uuid' => $r->uuid,
            'tenant' => $r->tenant ? [
                'uuid' => $r->tenant->uuid,
                'name' => $r->tenant->name,
                'slug' => $r->tenant->slug,
            ] : null,
            'user' => $r->user ? [
                'uuid' => $r->user->uuid,
                'name' => $r->user->name,
                'email' => $r->user->email,
                'username' => $r->user->username,
            ] : null,
            'amount' => $r->amount,
            'fee_amount' => $r->fee_amount,
            'net_amount' => $r->net_amount,
            'currency' => $r->currency,
            'payment_method' => $r->payment_method,
            'payment_details' => $r->payment_details,
            'status' => $r->status,
            'external_reference' => $r->external_reference,
            'rejection_reason' => $r->rejection_reason,
            'reviewed_by' => $r->reviewedBy?->name,
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'paid_by' => $r->paidBy?->name,
            'paid_at' => $r->paid_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
        ];

        if ($full) {
            $base['notes'] = $r->notes;
            $base['hold_transaction_id'] = $r->hold_transaction_id;
            $base['refund_transaction_id'] = $r->refund_transaction_id;
        }

        return $base;
    }
}
