<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Auth\SecurityAuditService;
use App\Services\Kyc\KycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class KycReviewController extends Controller
{
    public function __construct(
        protected KycService $kycService,
        protected SecurityAuditService $auditService
    ) {}

    /**
     * List KYC documents for review.
     */
    public function index(Request $request): JsonResponse
    {
        $query = KycDocument::with('user:id,uuid,name,email');

        // Filter by status
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        } else {
            // Default to pending
            $query->where('status', 'pending');
        }

        // Filter by document type
        if ($type = $request->input('document_type')) {
            $query->where('document_type', $type);
        }

        // Filter by user
        if ($userUuid = $request->input('user_uuid')) {
            $user = User::where('uuid', $userUuid)->first();
            if ($user) {
                $query->where('user_id', $user->id);
            }
        }

        // Sort
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'asc');
        $query->orderBy($sortBy, $sortDir);

        $documents = $query->paginate($request->input('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $documents->map(fn ($doc) => [
                'uuid' => $doc->uuid,
                'user' => [
                    'uuid' => $doc->user->uuid,
                    'name' => $doc->user->name,
                    'email' => $doc->user->email,
                ],
                'document_type' => $doc->document_type,
                'document_type_label' => $doc->document_type_label,
                'status' => $doc->status,
                'rejection_reason' => $doc->rejection_reason,
                'document_expiry' => $doc->document_expiry?->toDateString(),
                'is_expired' => $doc->isExpired(),
                'verified_at' => $doc->verified_at?->toIso8601String(),
                'created_at' => $doc->created_at->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
            ],
        ]);
    }

    /**
     * Get document details with secure URL.
     */
    public function show(string $uuid): JsonResponse
    {
        $document = KycDocument::with('user:id,uuid,name,email,kyc_level')
            ->where('uuid', $uuid)
            ->firstOrFail();

        // Generate temporary URL for viewing
        $temporaryUrl = null;
        if (Storage::disk('private')->exists($document->file_path)) {
            $temporaryUrl = Storage::disk('private')->temporaryUrl(
                $document->file_path,
                now()->addMinutes(15)
            );
        }

        return response()->json([
            'success' => true,
            'data' => [
                'uuid' => $document->uuid,
                'user' => [
                    'uuid' => $document->user->uuid,
                    'name' => $document->user->name,
                    'email' => $document->user->email,
                    'kyc_level' => $document->user->kyc_level,
                ],
                'document_type' => $document->document_type,
                'document_type_label' => $document->document_type_label,
                'status' => $document->status,
                'rejection_reason' => $document->rejection_reason,
                'document_expiry' => $document->document_expiry?->toDateString(),
                'is_expired' => $document->isExpired(),
                'verified_at' => $document->verified_at?->toIso8601String(),
                'verified_by' => $document->verified_by,
                'created_at' => $document->created_at->toIso8601String(),
                'file_url' => $temporaryUrl,
            ],
        ]);
    }

    /**
     * Approve a KYC document.
     */
    public function approve(Request $request, string $uuid): JsonResponse
    {
        $document = KycDocument::with('user')->where('uuid', $uuid)->firstOrFail();

        if ($document->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending documents can be approved.',
            ], 422);
        }

        $validated = $request->validate([
            'document_expiry' => ['nullable', 'date', 'after:today'],
        ]);

        $document->approve($request->user()->id, $validated['document_expiry'] ?? null);

        // Recalculate user's KYC level
        $this->kycService->recalculateLevel($document->user);

        $this->auditService->log(
            user: $document->user,
            action: 'admin.kyc.approve',
            description: "Admin approved {$document->document_type_label} for user {$document->user->email}",
            newValues: ['document_uuid' => $document->uuid],
            performedBy: $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'Document approved successfully.',
            'data' => [
                'new_kyc_level' => $document->user->fresh()->kyc_level,
            ],
        ]);
    }

    /**
     * Reject a KYC document.
     */
    public function reject(Request $request, string $uuid): JsonResponse
    {
        $document = KycDocument::with('user')->where('uuid', $uuid)->firstOrFail();

        if ($document->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending documents can be rejected.',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $document->reject($request->user()->id, $validated['reason']);

        $this->auditService->log(
            user: $document->user,
            action: 'admin.kyc.reject',
            description: "Admin rejected {$document->document_type_label} for user {$document->user->email}",
            newValues: [
                'document_uuid' => $document->uuid,
                'reason' => $validated['reason'],
            ],
            performedBy: $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'Document rejected successfully.',
        ]);
    }

    /**
     * Manually set user's KYC level.
     */
    public function setLevel(Request $request, string $userUuid): JsonResponse
    {
        $user = User::where('uuid', $userUuid)->firstOrFail();

        $validated = $request->validate([
            'level' => ['required', 'integer', 'min:0', 'max:3'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $oldLevel = $user->kyc_level;
        $user->update([
            'kyc_level' => $validated['level'],
            'kyc_verified_at' => $validated['level'] > 0 ? now() : null,
        ]);

        $this->auditService->log(
            user: $user,
            action: 'admin.kyc.set_level',
            description: "Admin manually set KYC level for user {$user->email}",
            oldValues: ['kyc_level' => $oldLevel],
            newValues: [
                'kyc_level' => $validated['level'],
                'reason' => $validated['reason'] ?? null,
            ],
            performedBy: $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'KYC level updated successfully.',
        ]);
    }

    /**
     * Get KYC statistics.
     */
    public function stats(): JsonResponse
    {
        $stats = [
            'documents' => [
                'pending' => KycDocument::where('status', 'pending')->count(),
                'approved' => KycDocument::where('status', 'approved')->count(),
                'rejected' => KycDocument::where('status', 'rejected')->count(),
            ],
            'by_type' => KycDocument::selectRaw('document_type, status, count(*) as count')
                ->groupBy('document_type', 'status')
                ->get()
                ->groupBy('document_type')
                ->map(fn ($items) => $items->pluck('count', 'status')),
            'users_by_level' => [
                'unverified' => User::where('kyc_level', 0)->count(),
                'basic' => User::where('kyc_level', 1)->count(),
                'enhanced' => User::where('kyc_level', 2)->count(),
                'full' => User::where('kyc_level', 3)->count(),
            ],
            'pending_oldest' => KycDocument::where('status', 'pending')
                ->oldest()
                ->first()
                ?->created_at?->toIso8601String(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
