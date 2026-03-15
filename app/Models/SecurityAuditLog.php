<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityAuditLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'admin_id',
        'event_type',
        'ip_address',
        'user_agent',
        'country_code',
        'city',
        'entity_type',
        'entity_id',
        'old_values',
        'new_values',
        'metadata',
        'severity',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
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
     * Admin relationship.
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * Scope for a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope for a specific event type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('event_type', $type);
    }

    /**
     * Scope for a specific severity.
     */
    public function scopeOfSeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    /**
     * Scope for critical events.
     */
    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical');
    }

    /**
     * Scope for recent events.
     */
    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Event type constants.
     */
    const TYPE_LOGIN = 'login';
    const TYPE_LOGIN_FAILED = 'login_failed';
    const TYPE_LOGOUT = 'logout';
    const TYPE_PASSWORD_CHANGED = 'password_changed';
    const TYPE_PASSWORD_RESET = 'password_reset';
    const TYPE_EMAIL_CHANGED = 'email_changed';
    const TYPE_PHONE_CHANGED = 'phone_changed';
    const TYPE_MFA_ENABLED = 'mfa_enabled';
    const TYPE_MFA_DISABLED = 'mfa_disabled';
    const TYPE_ACCOUNT_LOCKED = 'account_locked';
    const TYPE_ACCOUNT_UNLOCKED = 'account_unlocked';
    const TYPE_SESSION_REVOKED = 'session_revoked';
    const TYPE_NEW_DEVICE = 'new_device';
    const TYPE_NEW_LOCATION = 'new_location';
    const TYPE_SELF_EXCLUSION = 'self_exclusion';
    const TYPE_ADMIN_ACTION = 'admin_action';
}
