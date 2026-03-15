<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRoles;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasApiTokens, Notifiable, TwoFactorAuthenticatable, SoftDeletes, BelongsToTenant, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'uuid',
        'username',
        'phone',
        'phone_verified_at',
        'date_of_birth',
        'country_code',
        'terms_accepted_at',
        'display_name',
        'avatar_url',
        'timezone',
        'language',
        'status',
        'kyc_level',
        'kyc_verified_at',
        'failed_login_attempts',
        'locked_until',
        'last_login_at',
        'last_login_ip',
        'sms_mfa_phone',
        'sms_mfa_enabled',
        'preferred_mfa_method',
        'tenant_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'date_of_birth' => 'date',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'failed_login_attempts' => 'integer',
            'sms_mfa_enabled' => 'boolean',
            'kyc_level' => 'integer',
            'kyc_verified_at' => 'datetime',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (User $user) {
            if (empty($user->uuid)) {
                $user->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the route key name for Laravel.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Check if the user's phone is verified.
     */
    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }

    /**
     * Mark the user's phone as verified.
     */
    public function markPhoneAsVerified(): bool
    {
        return $this->forceFill([
            'phone_verified_at' => $this->freshTimestamp(),
        ])->save();
    }

    /**
     * Check if the account is locked.
     */
    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * Lock the account for a specified number of minutes.
     */
    public function lockAccount(int $minutes = 30): void
    {
        $this->update([
            'locked_until' => now()->addMinutes($minutes),
        ]);
    }

    /**
     * Unlock the account.
     */
    public function unlockAccount(): void
    {
        $this->update([
            'locked_until' => null,
            'failed_login_attempts' => 0,
        ]);
    }

    /**
     * Increment failed login attempts.
     */
    public function incrementFailedLoginAttempts(): int
    {
        $this->increment('failed_login_attempts');
        return $this->failed_login_attempts;
    }

    /**
     * Reset failed login attempts.
     */
    public function resetFailedLoginAttempts(): void
    {
        $this->update(['failed_login_attempts' => 0]);
    }

    /**
     * Check if the user is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if the user is self-excluded.
     */
    public function isSelfExcluded(): bool
    {
        return $this->status === 'self_excluded';
    }

    /**
     * Get the user's display name or fallback to name.
     */
    public function getDisplayNameAttribute(?string $value): string
    {
        return $value ?? $this->name;
    }

    /**
     * Phone verifications relationship.
     */
    public function phoneVerifications(): HasMany
    {
        return $this->hasMany(PhoneVerification::class);
    }

    /**
     * Login notifications relationship.
     */
    public function loginNotifications(): HasMany
    {
        return $this->hasMany(LoginNotification::class);
    }

    /**
     * User sessions relationship.
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    /**
     * Security audit logs relationship.
     */
    public function securityAuditLogs(): HasMany
    {
        return $this->hasMany(SecurityAuditLog::class);
    }

    /**
     * Check if SMS MFA is enabled.
     */
    public function hasSmsMfaEnabled(): bool
    {
        return $this->sms_mfa_enabled && $this->sms_mfa_phone !== null;
    }

    /**
     * Get preferred MFA method.
     */
    public function getPreferredMfaMethod(): string
    {
        // If SMS is preferred but not enabled, fall back to TOTP
        if ($this->preferred_mfa_method === 'sms' && !$this->hasSmsMfaEnabled()) {
            return 'totp';
        }

        return $this->preferred_mfa_method ?? 'totp';
    }

    /**
     * KYC documents relationship.
     */
    public function kycDocuments(): HasMany
    {
        return $this->hasMany(KycDocument::class);
    }

    /**
     * Check if user has completed basic KYC verification.
     */
    public function hasBasicKyc(): bool
    {
        return $this->kyc_level >= 1;
    }

    /**
     * Check if user has completed enhanced KYC verification.
     */
    public function hasEnhancedKyc(): bool
    {
        return $this->kyc_level >= 2;
    }

    /**
     * Check if user has completed full KYC verification.
     */
    public function hasFullKyc(): bool
    {
        return $this->kyc_level >= 3;
    }

    /**
     * Check if user meets minimum KYC level.
     */
    public function meetsKycLevel(int $requiredLevel): bool
    {
        return $this->kyc_level >= $requiredLevel;
    }

    /**
     * Check if user is of legal gambling age.
     */
    public function isOfLegalAge(int $minimumAge = 18): bool
    {
        if (!$this->date_of_birth) {
            return false;
        }

        return $this->date_of_birth->diffInYears(now()) >= $minimumAge;
    }

    /**
     * Get KYC level name.
     */
    public function getKycLevelNameAttribute(): string
    {
        return match ($this->kyc_level) {
            0 => 'Unverified',
            1 => 'Basic',
            2 => 'Enhanced',
            3 => 'Full',
            default => 'Unknown',
        };
    }

    /**
     * Responsible gambling settings relationship.
     */
    public function responsibleGamblingSettings(): HasOne
    {
        return $this->hasOne(ResponsibleGamblingSetting::class);
    }

    /**
     * Self-exclusions relationship.
     */
    public function selfExclusions(): HasMany
    {
        return $this->hasMany(SelfExclusion::class);
    }

    /**
     * Get active self-exclusion.
     */
    public function activeSelfExclusion(): ?SelfExclusion
    {
        return $this->selfExclusions()
            ->active()
            ->first();
    }

    /**
     * Check if user has an active self-exclusion.
     */
    public function hasActiveSelfExclusion(): bool
    {
        return $this->selfExclusions()
            ->active()
            ->exists();
    }

    // isAdmin() and isSuperAdmin() are provided by the HasRoles trait
}
