<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'legal_name',
        'registration_number',
        'license_number',
        'contact_email',
        'contact_phone',
        'logo_url',
        'domain',
        'country_code',
        'currency',
        'timezone',
        'status',
        'settings',
        'revenue_share_pct',
        'contract_starts_at',
        'contract_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'revenue_share_pct' => 'decimal:2',
            'contract_starts_at' => 'datetime',
            'contract_ends_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Tenant $tenant) {
            if (empty($tenant->uuid)) {
                $tenant->uuid = (string) Str::uuid();
            }
            if (empty($tenant->slug)) {
                $tenant->slug = Str::slug($tenant->name);
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isTerminated(): bool
    {
        return $this->status === 'terminated';
    }

    public function getBranding(): ?array
    {
        return $this->settings['branding'] ?? null;
    }

    // Relationships

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function venues(): HasMany
    {
        return $this->hasMany(Venue::class);
    }

    public function voucherCodes(): HasMany
    {
        return $this->hasMany(VoucherCode::class);
    }

    public function securityAuditLogs(): HasMany
    {
        return $this->hasMany(SecurityAuditLog::class);
    }

    public function revenueRecords(): HasMany
    {
        return $this->hasMany(TenantRevenueRecord::class);
    }

    public function games(): BelongsToMany
    {
        return $this->belongsToMany(Game::class, 'tenant_games')
            ->withPivot(['enabled', 'custom_settings'])
            ->withTimestamps();
    }

    public function enabledGames(): BelongsToMany
    {
        return $this->games()->wherePivot('enabled', true);
    }
}
