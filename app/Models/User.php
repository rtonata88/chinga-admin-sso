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
        'date_of_birth',
        'country_code',
        'terms_accepted_at',
        'display_name',
        'avatar_url',
        'timezone',
        'language',
        'status',
        'failed_login_attempts',
        'locked_until',
        'last_login_at',
        'last_login_ip',
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
            'terms_accepted_at' => 'datetime',
            'date_of_birth' => 'date',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'failed_login_attempts' => 'integer',
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
     * Get the user's display name or fallback to name.
     */
    public function getDisplayNameAttribute(?string $value): string
    {
        return $value ?? $this->name;
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
     * Wallet relationship.
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    /**
     * Get the user's wallet or create one if it doesn't exist.
     */
    public function getOrCreateWallet(?string $currency = null): Wallet
    {
        $wallet = $this->wallet;
        if ($wallet) {
            return $wallet;
        }

        $tenant = app('current_tenant');
        $currency = $currency ?? $tenant?->currency ?? 'NAD';

        return $this->wallet()->create([
            'tenant_id' => $this->tenant_id,
            'currency' => $currency,
            'status' => 'active',
        ]);
    }

    // isAdmin() and isSuperAdmin() are provided by the HasRoles trait
}
