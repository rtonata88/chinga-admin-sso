<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginAttempt extends Model
{
    protected $fillable = [
        'tenant_id',
        'email',
        'ip_address',
        'successful',
        'failure_reason',
        'user_agent',
        'country_code',
        'city',
    ];

    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
        ];
    }

    /**
     * Scope for failed attempts.
     */
    public function scopeFailed($query)
    {
        return $query->where('successful', false);
    }

    /**
     * Scope for successful attempts.
     */
    public function scopeSuccessful($query)
    {
        return $query->where('successful', true);
    }

    /**
     * Scope for a specific email.
     */
    public function scopeForEmail($query, string $email)
    {
        return $query->where('email', $email);
    }

    /**
     * Scope for a specific IP address.
     */
    public function scopeForIp($query, string $ip)
    {
        return $query->where('ip_address', $ip);
    }

    /**
     * Scope for recent attempts (within specified minutes).
     */
    public function scopeRecent($query, int $minutes = 30)
    {
        return $query->where('created_at', '>=', now()->subMinutes($minutes));
    }

    /**
     * Get the count of recent failed attempts for an email.
     */
    public static function recentFailedCount(string $email, int $minutes = 30): int
    {
        return static::forEmail($email)
            ->failed()
            ->recent($minutes)
            ->count();
    }

    /**
     * Get the count of recent failed attempts for an IP.
     */
    public static function recentFailedCountByIp(string $ip, int $minutes = 30): int
    {
        return static::forIp($ip)
            ->failed()
            ->recent($minutes)
            ->count();
    }
}
