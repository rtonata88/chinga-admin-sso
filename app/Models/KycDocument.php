<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class KycDocument extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'user_id',
        'document_type',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'status',
        'rejection_reason',
        'verified_by',
        'verified_at',
        'document_expiry',
        'document_number',
        'issuing_country',
        'metadata',
        'extracted_data',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'document_expiry' => 'date',
            'metadata' => 'array',
            'extracted_data' => 'array',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (KycDocument $document) {
            if (empty($document->uuid)) {
                $document->uuid = (string) Str::uuid();
            }
        });

        static::deleting(function (KycDocument $document) {
            // Delete the file when the document record is deleted
            if ($document->file_path && Storage::disk('private')->exists($document->file_path)) {
                Storage::disk('private')->delete($document->file_path);
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Status checks.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Check if document is expired.
     */
    public function isExpired(): bool
    {
        if (!$this->document_expiry) {
            return false;
        }

        return $this->document_expiry->isPast();
    }

    /**
     * Check if document will expire soon (within 30 days).
     */
    public function isExpiringSoon(int $days = 30): bool
    {
        if (!$this->document_expiry) {
            return false;
        }

        return $this->document_expiry->isBetween(now(), now()->addDays($days));
    }

    /**
     * Approve the document.
     */
    public function approve(User $verifiedBy, ?array $extractedData = null): void
    {
        $this->update([
            'status' => 'approved',
            'verified_by' => $verifiedBy->id,
            'verified_at' => now(),
            'rejection_reason' => null,
            'extracted_data' => $extractedData ?? $this->extracted_data,
        ]);
    }

    /**
     * Reject the document.
     */
    public function reject(User $verifiedBy, string $reason): void
    {
        $this->update([
            'status' => 'rejected',
            'verified_by' => $verifiedBy->id,
            'verified_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Get document type label.
     */
    public function getDocumentTypeLabelAttribute(): string
    {
        return match ($this->document_type) {
            'passport' => 'Passport',
            'national_id' => 'National ID',
            'drivers_license' => 'Driver\'s License',
            'proof_of_address' => 'Proof of Address',
            'selfie' => 'Selfie/Photo ID',
            'source_of_funds' => 'Source of Funds',
            default => ucfirst(str_replace('_', ' ', $this->document_type)),
        };
    }

    /**
     * Get the file URL (temporary signed URL for private storage).
     */
    public function getFileUrl(int $expiryMinutes = 5): ?string
    {
        if (!$this->file_path) {
            return null;
        }

        return Storage::disk('private')->temporaryUrl(
            $this->file_path,
            now()->addMinutes($expiryMinutes)
        );
    }

    /**
     * Check if this is an identity document type.
     */
    public function isIdentityDocument(): bool
    {
        return in_array($this->document_type, [
            'passport',
            'national_id',
            'drivers_license',
        ]);
    }

    /**
     * Relationships.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Scopes.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('document_type', $type);
    }

    public function scopeIdentityDocuments($query)
    {
        return $query->whereIn('document_type', [
            'passport',
            'national_id',
            'drivers_license',
        ]);
    }
}
