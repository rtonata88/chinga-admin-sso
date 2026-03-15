<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SelfExclusion extends Model
{
    use HasFactory, BelongsToTenant;

    public const TYPE_TEMPORARY = 'temporary';

    public const TYPE_PERMANENT = 'permanent';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'type',
        'reason',
        'starts_at',
        'ends_at',
        'revoked_at',
        'revoked_by',
        'revoke_reason',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function revokedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * Check if the exclusion is currently active.
     */
    public function isActive(): bool
    {
        // If revoked, not active
        if ($this->revoked_at !== null) {
            return false;
        }

        // If not started yet, not active
        if ($this->starts_at->isFuture()) {
            return false;
        }

        // Permanent exclusions are always active once started
        if ($this->type === self::TYPE_PERMANENT) {
            return true;
        }

        // Temporary exclusions check end date
        return $this->ends_at === null || $this->ends_at->isFuture();
    }

    /**
     * Check if this is a permanent exclusion.
     */
    public function isPermanent(): bool
    {
        return $this->type === self::TYPE_PERMANENT;
    }

    /**
     * Check if this is a temporary exclusion.
     */
    public function isTemporary(): bool
    {
        return $this->type === self::TYPE_TEMPORARY;
    }

    /**
     * Check if the exclusion has ended naturally (temporary only).
     */
    public function hasEnded(): bool
    {
        if ($this->isPermanent()) {
            return false;
        }

        return $this->ends_at !== null && $this->ends_at->isPast();
    }

    /**
     * Check if the exclusion was revoked.
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Get remaining days for temporary exclusion.
     */
    public function getRemainingDays(): ?int
    {
        if ($this->isPermanent() || ! $this->isActive()) {
            return null;
        }

        return max(0, now()->diffInDays($this->ends_at, false));
    }

    /**
     * Get the duration label.
     */
    public function getDurationLabel(): string
    {
        if ($this->isPermanent()) {
            return 'Permanent';
        }

        if (! $this->ends_at) {
            return 'Indefinite';
        }

        $days = $this->starts_at->diffInDays($this->ends_at);

        return match (true) {
            $days <= 1 => '24 hours',
            $days <= 7 => '7 days',
            $days <= 30 => '30 days',
            $days <= 90 => '90 days',
            default => "{$days} days",
        };
    }

    /**
     * Scope to get active exclusions.
     */
    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where(function ($q) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now())
                    ->orWhere('type', self::TYPE_PERMANENT);
            });
    }
}
