<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhoneVerification extends Model
{
    protected $fillable = [
        'user_id',
        'phone',
        'otp_code',
        'purpose',
        'attempts',
        'expires_at',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * User relationship.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if the verification has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if the verification is still valid.
     */
    public function isValid(): bool
    {
        return !$this->isExpired() && $this->verified_at === null;
    }

    /**
     * Check if max attempts have been exceeded.
     */
    public function hasExceededAttempts(int $maxAttempts = 5): bool
    {
        return $this->attempts >= $maxAttempts;
    }

    /**
     * Increment attempts.
     */
    public function incrementAttempts(): void
    {
        $this->increment('attempts');
    }

    /**
     * Mark as verified.
     */
    public function markAsVerified(): void
    {
        $this->update(['verified_at' => now()]);
    }

    /**
     * Scope for pending verifications.
     */
    public function scopePending($query)
    {
        return $query->whereNull('verified_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Scope for a specific phone number.
     */
    public function scopeForPhone($query, string $phone)
    {
        return $query->where('phone', $phone);
    }

    /**
     * Scope for a specific purpose.
     */
    public function scopeForPurpose($query, string $purpose)
    {
        return $query->where('purpose', $purpose);
    }
}
