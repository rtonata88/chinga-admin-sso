<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSession extends Model
{
    protected $fillable = [
        'user_id',
        'session_id',
        'ip_address',
        'user_agent',
        'device_type',
        'browser',
        'platform',
        'country_code',
        'city',
        'is_current',
        'last_active_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'last_active_at' => 'datetime',
            'expires_at' => 'datetime',
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
     * Check if the session is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if the session is active.
     */
    public function isActive(): bool
    {
        return !$this->isExpired();
    }

    /**
     * Update last activity.
     */
    public function touch($attribute = null): bool
    {
        if ($attribute) {
            return parent::touch($attribute);
        }

        return $this->update(['last_active_at' => now()]);
    }

    /**
     * Mark as current session.
     */
    public function markAsCurrent(): void
    {
        // Unmark all other sessions for this user
        static::where('user_id', $this->user_id)
            ->where('id', '!=', $this->id)
            ->update(['is_current' => false]);

        $this->update(['is_current' => true]);
    }

    /**
     * Scope for a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope for active sessions.
     */
    public function scopeActive($query)
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * Scope for expired sessions.
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    /**
     * Get formatted device info.
     */
    public function getDeviceInfoAttribute(): string
    {
        $parts = array_filter([
            $this->browser,
            $this->platform,
        ]);

        return implode(' on ', $parts) ?: 'Unknown device';
    }

    /**
     * Get formatted location.
     */
    public function getLocationAttribute(): string
    {
        $parts = array_filter([
            $this->city,
            $this->country_code,
        ]);

        return implode(', ', $parts) ?: 'Unknown location';
    }
}
