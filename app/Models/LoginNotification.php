<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginNotification extends Model
{
    protected $fillable = [
        'user_id',
        'ip_address',
        'user_agent',
        'device_fingerprint',
        'device_type',
        'browser',
        'platform',
        'country_code',
        'city',
        'is_new_device',
        'is_new_location',
        'notification_sent',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'is_new_device' => 'boolean',
            'is_new_location' => 'boolean',
            'notification_sent' => 'boolean',
            'notified_at' => 'datetime',
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
     * Mark as notified.
     */
    public function markAsNotified(): void
    {
        $this->update([
            'notification_sent' => true,
            'notified_at' => now(),
        ]);
    }

    /**
     * Scope for a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope for notifications that require sending.
     */
    public function scopeRequiresNotification($query)
    {
        return $query->where('notification_sent', false)
            ->where(function ($q) {
                $q->where('is_new_device', true)
                    ->orWhere('is_new_location', true);
            });
    }

    /**
     * Check if user has previously logged in from this device.
     */
    public static function hasDeviceFingerprint(int $userId, string $fingerprint): bool
    {
        return static::forUser($userId)
            ->where('device_fingerprint', $fingerprint)
            ->exists();
    }

    /**
     * Check if user has previously logged in from this location.
     */
    public static function hasLocation(int $userId, ?string $countryCode, ?string $city): bool
    {
        return static::forUser($userId)
            ->where('country_code', $countryCode)
            ->where('city', $city)
            ->exists();
    }
}
