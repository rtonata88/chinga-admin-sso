<?php

namespace App\Services\Kyc;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\Auth\SecurityAuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class KycService
{
    /**
     * KYC Verification Levels:
     * 0 = Unverified (email only)
     * 1 = Basic (email + phone + DOB verified)
     * 2 = Enhanced (ID document verified)
     * 3 = Full (ID + proof of address + source of funds)
     */
    public const LEVEL_UNVERIFIED = 0;
    public const LEVEL_BASIC = 1;
    public const LEVEL_ENHANCED = 2;
    public const LEVEL_FULL = 3;

    public const DOCUMENT_TYPES = [
        'passport',
        'national_id',
        'drivers_license',
        'proof_of_address',
        'selfie',
        'source_of_funds',
    ];

    public const IDENTITY_DOCUMENTS = [
        'passport',
        'national_id',
        'drivers_license',
    ];

    public const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    public const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB

    public function __construct(
        protected SecurityAuditService $auditService
    ) {}

    /**
     * Get the current KYC status for a user.
     */
    public function getStatus(User $user): array
    {
        $documents = $user->kycDocuments()->get();

        $approvedDocuments = $documents->where('status', 'approved');
        $pendingDocuments = $documents->where('status', 'pending');
        $rejectedDocuments = $documents->where('status', 'rejected');

        // Check what's verified
        $hasVerifiedEmail = $user->hasVerifiedEmail();
        $hasVerifiedPhone = $user->hasVerifiedPhone();
        $hasDateOfBirth = !empty($user->date_of_birth);
        $hasIdentityDocument = $approvedDocuments->whereIn('document_type', self::IDENTITY_DOCUMENTS)->isNotEmpty();
        $hasSelfie = $approvedDocuments->where('document_type', 'selfie')->isNotEmpty();
        $hasProofOfAddress = $approvedDocuments->where('document_type', 'proof_of_address')->isNotEmpty();
        $hasSourceOfFunds = $approvedDocuments->where('document_type', 'source_of_funds')->isNotEmpty();

        // Calculate current level
        $currentLevel = $this->calculateKycLevel($user, $approvedDocuments);

        // Determine what's needed for next level
        $nextLevelRequirements = $this->getNextLevelRequirements($currentLevel, [
            'email_verified' => $hasVerifiedEmail,
            'phone_verified' => $hasVerifiedPhone,
            'date_of_birth' => $hasDateOfBirth,
            'identity_document' => $hasIdentityDocument,
            'selfie' => $hasSelfie,
            'proof_of_address' => $hasProofOfAddress,
            'source_of_funds' => $hasSourceOfFunds,
        ]);

        return [
            'level' => $currentLevel,
            'level_name' => $this->getLevelName($currentLevel),
            'verified_at' => $user->kyc_verified_at?->toIso8601String(),
            'verification_status' => [
                'email_verified' => $hasVerifiedEmail,
                'phone_verified' => $hasVerifiedPhone,
                'date_of_birth_provided' => $hasDateOfBirth,
                'identity_document_verified' => $hasIdentityDocument,
                'selfie_verified' => $hasSelfie,
                'proof_of_address_verified' => $hasProofOfAddress,
                'source_of_funds_verified' => $hasSourceOfFunds,
            ],
            'documents' => [
                'approved' => $approvedDocuments->count(),
                'pending' => $pendingDocuments->count(),
                'rejected' => $rejectedDocuments->count(),
            ],
            'next_level_requirements' => $nextLevelRequirements,
            'can_upgrade' => !empty($nextLevelRequirements),
        ];
    }

    /**
     * Calculate the KYC level based on verified documents.
     */
    public function calculateKycLevel(User $user, $approvedDocuments = null): int
    {
        if ($approvedDocuments === null) {
            $approvedDocuments = $user->kycDocuments()->approved()->get();
        }

        // Level 3: Full verification
        $hasIdentity = $approvedDocuments->whereIn('document_type', self::IDENTITY_DOCUMENTS)->isNotEmpty();
        $hasProofOfAddress = $approvedDocuments->where('document_type', 'proof_of_address')->isNotEmpty();
        $hasSourceOfFunds = $approvedDocuments->where('document_type', 'source_of_funds')->isNotEmpty();

        if ($hasIdentity && $hasProofOfAddress && $hasSourceOfFunds) {
            return self::LEVEL_FULL;
        }

        // Level 2: Enhanced verification (ID document)
        if ($hasIdentity) {
            return self::LEVEL_ENHANCED;
        }

        // Level 1: Basic verification (email + phone + DOB)
        if ($user->hasVerifiedEmail() && $user->hasVerifiedPhone() && $user->date_of_birth) {
            return self::LEVEL_BASIC;
        }

        return self::LEVEL_UNVERIFIED;
    }

    /**
     * Get the name for a KYC level.
     */
    public function getLevelName(int $level): string
    {
        return match ($level) {
            self::LEVEL_UNVERIFIED => 'Unverified',
            self::LEVEL_BASIC => 'Basic',
            self::LEVEL_ENHANCED => 'Enhanced',
            self::LEVEL_FULL => 'Full',
            default => 'Unknown',
        };
    }

    /**
     * Get requirements for the next KYC level.
     */
    protected function getNextLevelRequirements(int $currentLevel, array $status): array
    {
        $requirements = [];

        if ($currentLevel === self::LEVEL_FULL) {
            return []; // Already at max level
        }

        if ($currentLevel < self::LEVEL_BASIC) {
            if (!$status['email_verified']) {
                $requirements[] = 'Verify email address';
            }
            if (!$status['phone_verified']) {
                $requirements[] = 'Verify phone number';
            }
            if (!$status['date_of_birth']) {
                $requirements[] = 'Provide date of birth';
            }
        }

        if ($currentLevel < self::LEVEL_ENHANCED) {
            if (!$status['identity_document']) {
                $requirements[] = 'Upload identity document (passport, national ID, or driver\'s license)';
            }
        }

        if ($currentLevel < self::LEVEL_FULL) {
            if (!$status['proof_of_address']) {
                $requirements[] = 'Upload proof of address';
            }
            if (!$status['source_of_funds']) {
                $requirements[] = 'Upload source of funds documentation';
            }
        }

        return $requirements;
    }

    /**
     * Upload a KYC document.
     */
    public function uploadDocument(
        User $user,
        UploadedFile $file,
        string $documentType,
        ?array $metadata = null
    ): KycDocument {
        // Validate document type
        if (!in_array($documentType, self::DOCUMENT_TYPES)) {
            throw new \InvalidArgumentException('Invalid document type.');
        }

        // Validate file
        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES)) {
            throw new \InvalidArgumentException('Invalid file type. Allowed: JPEG, PNG, WebP, PDF.');
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('File size exceeds 10MB limit.');
        }

        // Generate secure file path
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid() . '.' . $extension;
        $path = "kyc/{$user->id}/{$documentType}/{$filename}";

        // Store file in private disk
        Storage::disk('private')->put($path, file_get_contents($file->getRealPath()));

        // Check for existing pending document of same type
        $existingPending = $user->kycDocuments()
            ->where('document_type', $documentType)
            ->where('status', 'pending')
            ->first();

        if ($existingPending) {
            // Delete the old file and update the record
            if (Storage::disk('private')->exists($existingPending->file_path)) {
                Storage::disk('private')->delete($existingPending->file_path);
            }

            $existingPending->update([
                'file_path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'metadata' => $metadata,
                'rejection_reason' => null,
            ]);

            return $existingPending->fresh();
        }

        // Create new document record
        return KycDocument::create([
            'user_id' => $user->id,
            'document_type' => $documentType,
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'status' => 'pending',
            'metadata' => $metadata,
        ]);
    }

    /**
     * Get documents for a user.
     */
    public function getDocuments(User $user, ?string $status = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = $user->kycDocuments()->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
        }

        return $query->get();
    }

    /**
     * Get a specific document.
     */
    public function getDocument(User $user, string $uuid): ?KycDocument
    {
        return $user->kycDocuments()->where('uuid', $uuid)->first();
    }

    /**
     * Approve a document (admin action).
     */
    public function approveDocument(
        KycDocument $document,
        User $verifiedBy,
        ?array $extractedData = null,
        ?string $documentNumber = null,
        ?string $issuingCountry = null,
        ?\DateTimeInterface $documentExpiry = null
    ): void {
        DB::transaction(function () use ($document, $verifiedBy, $extractedData, $documentNumber, $issuingCountry, $documentExpiry) {
            $document->update([
                'status' => 'approved',
                'verified_by' => $verifiedBy->id,
                'verified_at' => now(),
                'rejection_reason' => null,
                'extracted_data' => $extractedData,
                'document_number' => $documentNumber,
                'issuing_country' => $issuingCountry,
                'document_expiry' => $documentExpiry,
            ]);

            // Recalculate user's KYC level
            $this->updateUserKycLevel($document->user);
        });
    }

    /**
     * Reject a document (admin action).
     */
    public function rejectDocument(
        KycDocument $document,
        User $verifiedBy,
        string $reason
    ): void {
        $document->update([
            'status' => 'rejected',
            'verified_by' => $verifiedBy->id,
            'verified_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Update user's KYC level based on approved documents.
     */
    public function updateUserKycLevel(User $user): void
    {
        $newLevel = $this->calculateKycLevel($user);
        $oldLevel = $user->kyc_level;

        if ($newLevel !== $oldLevel) {
            $user->update([
                'kyc_level' => $newLevel,
                'kyc_verified_at' => $newLevel > 0 ? now() : null,
            ]);

            // Log the KYC level change
            $this->auditService->log(
                $user,
                'kyc_level_changed',
                'info',
                null,
                [
                    'old_level' => $oldLevel,
                    'new_level' => $newLevel,
                    'old_level_name' => $this->getLevelName($oldLevel),
                    'new_level_name' => $this->getLevelName($newLevel),
                ]
            );
        }
    }

    /**
     * Delete a pending document.
     */
    public function deleteDocument(KycDocument $document): bool
    {
        if (!$document->isPending()) {
            throw new \RuntimeException('Only pending documents can be deleted.');
        }

        return $document->delete();
    }

    /**
     * Check if user meets minimum KYC level.
     */
    public function meetsMinimumLevel(User $user, int $requiredLevel): bool
    {
        return $user->kyc_level >= $requiredLevel;
    }

    /**
     * Get pending documents for admin review.
     */
    public function getPendingDocuments(int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return KycDocument::pending()
            ->with(['user:id,uuid,name,email'])
            ->orderBy('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Check if user is of legal age (18+).
     */
    public function isOfLegalAge(User $user, int $minimumAge = 18): bool
    {
        if (!$user->date_of_birth) {
            return false;
        }

        return $user->date_of_birth->diffInYears(now()) >= $minimumAge;
    }

    /**
     * Get verification limits based on KYC level.
     */
    public function getLimits(int $kycLevel): array
    {
        return match ($kycLevel) {
            self::LEVEL_UNVERIFIED => [
                'daily_deposit' => 0,
                'monthly_deposit' => 0,
                'withdrawal' => 0,
                'can_play' => false,
            ],
            self::LEVEL_BASIC => [
                'daily_deposit' => 1000,
                'monthly_deposit' => 5000,
                'withdrawal' => 2000,
                'can_play' => true,
            ],
            self::LEVEL_ENHANCED => [
                'daily_deposit' => 10000,
                'monthly_deposit' => 50000,
                'withdrawal' => 25000,
                'can_play' => true,
            ],
            self::LEVEL_FULL => [
                'daily_deposit' => null, // No limit
                'monthly_deposit' => null,
                'withdrawal' => null,
                'can_play' => true,
            ],
            default => [
                'daily_deposit' => 0,
                'monthly_deposit' => 0,
                'withdrawal' => 0,
                'can_play' => false,
            ],
        };
    }
}
