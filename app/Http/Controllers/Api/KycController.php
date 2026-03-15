<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Kyc\KycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KycController extends Controller
{
    public function __construct(
        protected KycService $kycService
    ) {}

    /**
     * Get KYC status for the current user.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $status = $this->kycService->getStatus($user);

        return response()->json([
            'kyc' => $status,
            'limits' => $this->kycService->getLimits($status['level']),
        ]);
    }

    /**
     * Upload a KYC document.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'document_type' => ['required', 'string', 'in:' . implode(',', KycService::DOCUMENT_TYPES)],
            'file' => ['required', 'file', 'max:10240'], // 10MB
            'document_number' => ['nullable', 'string', 'max:100'],
            'issuing_country' => ['nullable', 'string', 'size:2'],
            'document_expiry' => ['nullable', 'date', 'after:today'],
        ]);

        $user = $request->user();
        $file = $request->file('file');

        try {
            $document = $this->kycService->uploadDocument(
                $user,
                $file,
                $request->input('document_type'),
                [
                    'document_number' => $request->input('document_number'),
                    'issuing_country' => $request->input('issuing_country'),
                    'document_expiry' => $request->input('document_expiry'),
                    'uploaded_ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]
            );

            return response()->json([
                'message' => 'Document uploaded successfully. It will be reviewed shortly.',
                'document' => [
                    'uuid' => $document->uuid,
                    'document_type' => $document->document_type,
                    'document_type_label' => $document->document_type_label,
                    'status' => $document->status,
                    'created_at' => $document->created_at->toIso8601String(),
                ],
            ], 201);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'file' => [$e->getMessage()],
            ]);
        }
    }

    /**
     * List user's KYC documents.
     */
    public function documents(Request $request): JsonResponse
    {
        $user = $request->user();
        $status = $request->input('status');

        $documents = $this->kycService->getDocuments($user, $status);

        return response()->json([
            'documents' => $documents->map(fn ($doc) => [
                'uuid' => $doc->uuid,
                'document_type' => $doc->document_type,
                'document_type_label' => $doc->document_type_label,
                'status' => $doc->status,
                'rejection_reason' => $doc->rejection_reason,
                'verified_at' => $doc->verified_at?->toIso8601String(),
                'document_expiry' => $doc->document_expiry?->toDateString(),
                'is_expired' => $doc->isExpired(),
                'is_expiring_soon' => $doc->isExpiringSoon(),
                'created_at' => $doc->created_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Get a specific document.
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();
        $document = $this->kycService->getDocument($user, $uuid);

        if (!$document) {
            return response()->json([
                'message' => 'Document not found.',
            ], 404);
        }

        return response()->json([
            'document' => [
                'uuid' => $document->uuid,
                'document_type' => $document->document_type,
                'document_type_label' => $document->document_type_label,
                'original_filename' => $document->original_filename,
                'status' => $document->status,
                'rejection_reason' => $document->rejection_reason,
                'verified_at' => $document->verified_at?->toIso8601String(),
                'document_number' => $document->document_number ? $this->maskDocumentNumber($document->document_number) : null,
                'issuing_country' => $document->issuing_country,
                'document_expiry' => $document->document_expiry?->toDateString(),
                'is_expired' => $document->isExpired(),
                'is_expiring_soon' => $document->isExpiringSoon(),
                'created_at' => $document->created_at->toIso8601String(),
                'updated_at' => $document->updated_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Delete a pending document.
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();
        $document = $this->kycService->getDocument($user, $uuid);

        if (!$document) {
            return response()->json([
                'message' => 'Document not found.',
            ], 404);
        }

        if (!$document->isPending()) {
            return response()->json([
                'message' => 'Only pending documents can be deleted.',
            ], 422);
        }

        $this->kycService->deleteDocument($document);

        return response()->json([
            'message' => 'Document deleted successfully.',
        ]);
    }

    /**
     * Get required documents for current level upgrade.
     */
    public function requirements(Request $request): JsonResponse
    {
        $user = $request->user();
        $status = $this->kycService->getStatus($user);

        $requiredDocuments = [];

        // Based on current level, determine what documents are needed
        if ($status['level'] < KycService::LEVEL_ENHANCED) {
            $requiredDocuments[] = [
                'type' => 'identity',
                'label' => 'Identity Document',
                'description' => 'Upload a valid passport, national ID, or driver\'s license',
                'accepted_types' => ['passport', 'national_id', 'drivers_license'],
                'required' => true,
            ];
            $requiredDocuments[] = [
                'type' => 'selfie',
                'label' => 'Selfie with ID',
                'description' => 'Upload a photo of yourself holding your ID document',
                'accepted_types' => ['selfie'],
                'required' => false,
            ];
        }

        if ($status['level'] < KycService::LEVEL_FULL) {
            $requiredDocuments[] = [
                'type' => 'proof_of_address',
                'label' => 'Proof of Address',
                'description' => 'Upload a utility bill or bank statement from the last 3 months',
                'accepted_types' => ['proof_of_address'],
                'required' => $status['level'] >= KycService::LEVEL_ENHANCED,
            ];
            $requiredDocuments[] = [
                'type' => 'source_of_funds',
                'label' => 'Source of Funds',
                'description' => 'Upload documentation proving your source of income',
                'accepted_types' => ['source_of_funds'],
                'required' => false,
            ];
        }

        return response()->json([
            'current_level' => $status['level'],
            'current_level_name' => $status['level_name'],
            'requirements' => $requiredDocuments,
            'accepted_file_types' => ['JPEG', 'PNG', 'WebP', 'PDF'],
            'max_file_size' => '10MB',
        ]);
    }

    /**
     * Mask document number for privacy.
     */
    private function maskDocumentNumber(string $number): string
    {
        $length = strlen($number);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        $visible = 4;
        $masked = str_repeat('*', $length - $visible);

        return $masked . substr($number, -$visible);
    }
}
