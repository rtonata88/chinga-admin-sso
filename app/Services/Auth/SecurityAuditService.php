<?php

namespace App\Services\Auth;

use App\Models\SecurityAuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SecurityAuditService
{
    /**
     * Log a security event.
     */
    public function log(
        string $eventType,
        ?User $user = null,
        ?User $admin = null,
        array $options = []
    ): SecurityAuditLog {
        $request = request();

        return SecurityAuditLog::create([
            'tenant_id' => $user?->tenant_id ?? app('current_tenant')?->id,
            'user_id' => $user?->id,
            'admin_id' => $admin?->id,
            'event_type' => $eventType,
            'ip_address' => $request->ip() ?? '0.0.0.0',
            'user_agent' => $request->userAgent(),
            'country_code' => $options['country_code'] ?? null,
            'city' => $options['city'] ?? null,
            'entity_type' => $options['entity_type'] ?? null,
            'entity_id' => $options['entity_id'] ?? null,
            'old_values' => $options['old_values'] ?? null,
            'new_values' => $options['new_values'] ?? null,
            'metadata' => $options['metadata'] ?? null,
            'severity' => $options['severity'] ?? 'info',
        ]);
    }

    /**
     * Log a successful login.
     */
    public function logLogin(User $user, array $metadata = []): SecurityAuditLog
    {
        return $this->log(SecurityAuditLog::TYPE_LOGIN, $user, null, [
            'metadata' => $metadata,
            'severity' => 'info',
        ]);
    }

    /**
     * Log a failed login attempt.
     */
    public function logLoginFailed(string $email, string $reason, array $metadata = []): SecurityAuditLog
    {
        $user = User::where('email', $email)->first();

        return $this->log(SecurityAuditLog::TYPE_LOGIN_FAILED, $user, null, [
            'metadata' => array_merge(['email' => $email, 'reason' => $reason], $metadata),
            'severity' => 'warning',
        ]);
    }

    /**
     * Log a logout.
     */
    public function logLogout(User $user): SecurityAuditLog
    {
        return $this->log(SecurityAuditLog::TYPE_LOGOUT, $user, null, [
            'severity' => 'info',
        ]);
    }

    /**
     * Log a password change.
     */
    public function logPasswordChanged(User $user, ?User $admin = null): SecurityAuditLog
    {
        return $this->log(SecurityAuditLog::TYPE_PASSWORD_CHANGED, $user, $admin, [
            'severity' => 'warning',
        ]);
    }

    /**
     * Log a password reset.
     */
    public function logPasswordReset(User $user): SecurityAuditLog
    {
        return $this->log(SecurityAuditLog::TYPE_PASSWORD_RESET, $user, null, [
            'severity' => 'warning',
        ]);
    }

    /**
     * Log an email change.
     */
    public function logEmailChanged(User $user, string $oldEmail, string $newEmail): SecurityAuditLog
    {
        return $this->log(SecurityAuditLog::TYPE_EMAIL_CHANGED, $user, null, [
            'old_values' => ['email' => $oldEmail],
            'new_values' => ['email' => $newEmail],
            'severity' => 'warning',
        ]);
    }

    /**
     * Log a phone change.
     */
    public function logPhoneChanged(User $user, ?string $oldPhone, ?string $newPhone): SecurityAuditLog
    {
        return $this->log(SecurityAuditLog::TYPE_PHONE_CHANGED, $user, null, [
            'old_values' => ['phone' => $oldPhone],
            'new_values' => ['phone' => $newPhone],
            'severity' => 'info',
        ]);
    }

    /**
     * Log MFA enabled.
     */
    public function logMfaEnabled(User $user, string $method): SecurityAuditLog
    {
        return $this->log(SecurityAuditLog::TYPE_MFA_ENABLED, $user, null, [
            'metadata' => ['method' => $method],
            'severity' => 'info',
        ]);
    }

    /**
     * Log MFA disabled.
     */
    public function logMfaDisabled(User $user, string $method): SecurityAuditLog
    {
        return $this->log(SecurityAuditLog::TYPE_MFA_DISABLED, $user, null, [
            'metadata' => ['method' => $method],
            'severity' => 'warning',
        ]);
    }

    /**
     * Log account locked.
     */
    public function logAccountLocked(User $user, string $reason): SecurityAuditLog
    {
        return $this->log(SecurityAuditLog::TYPE_ACCOUNT_LOCKED, $user, null, [
            'metadata' => ['reason' => $reason],
            'severity' => 'critical',
        ]);
    }

    /**
     * Log account unlocked.
     */
    public function logAccountUnlocked(User $user, ?User $admin = null): SecurityAuditLog
    {
        return $this->log(SecurityAuditLog::TYPE_ACCOUNT_UNLOCKED, $user, $admin, [
            'severity' => 'info',
        ]);
    }

    /**
     * Log session revoked.
     */
    public function logSessionRevoked(User $user, int $sessionCount): SecurityAuditLog
    {
        return $this->log(SecurityAuditLog::TYPE_SESSION_REVOKED, $user, null, [
            'metadata' => ['sessions_revoked' => $sessionCount],
            'severity' => 'warning',
        ]);
    }

    /**
     * Log admin action.
     */
    public function logAdminAction(
        User $admin,
        User $targetUser,
        string $action,
        array $details = []
    ): SecurityAuditLog {
        return $this->log(SecurityAuditLog::TYPE_ADMIN_ACTION, $targetUser, $admin, [
            'metadata' => array_merge(['action' => $action], $details),
            'severity' => 'warning',
        ]);
    }

    /**
     * Get audit logs for a user.
     */
    public function getLogsForUser(User $user, int $limit = 50): Collection
    {
        return SecurityAuditLog::forUser($user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get recent critical events.
     */
    public function getRecentCriticalEvents(int $hours = 24): Collection
    {
        return SecurityAuditLog::critical()
            ->where('created_at', '>=', now()->subHours($hours))
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Search audit logs.
     */
    public function search(array $filters, int $perPage = 25)
    {
        $query = SecurityAuditLog::query();

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (!empty($filters['event_type'])) {
            $query->where('event_type', $filters['event_type']);
        }

        if (!empty($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }

        if (!empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        if (!empty($filters['ip_address'])) {
            $query->where('ip_address', $filters['ip_address']);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * Clean up old audit logs.
     */
    public function cleanupOld(int $days = 365): int
    {
        return SecurityAuditLog::where('created_at', '<', now()->subDays($days))
            ->delete();
    }
}
