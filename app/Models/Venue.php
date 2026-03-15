<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Venue extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'name',
        'slug',
        'business_name',
        'license_number',
        'address_line_1',
        'address_line_2',
        'city',
        'region',
        'postal_code',
        'country_code',
        'latitude',
        'longitude',
        'phone',
        'email',
        'timezone',
        'currency',
        'status',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Venue $venue) {
            if (empty($venue->uuid)) {
                $venue->uuid = (string) Str::uuid();
            }
            if (empty($venue->slug)) {
                $venue->slug = Str::slug($venue->name);
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Check if venue is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Get venue settings with defaults.
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /**
     * Get code format settings.
     */
    public function getCodeLength(): int
    {
        return $this->getSetting('code_format.length', 6);
    }

    public function getCodeType(): string
    {
        return $this->getSetting('code_format.type', 'alphanumeric');
    }

    public function getDefaultCodeExpiryHours(): int
    {
        return $this->getSetting('code_defaults.expiry_hours', 24);
    }

    public function getMaxCodeBalance(): float
    {
        return (float) $this->getSetting('code_defaults.max_balance', 10000.00);
    }

    public function requiresPin(): bool
    {
        return (bool) $this->getSetting('code_defaults.require_pin', false);
    }

    /**
     * Staff members relationship.
     */
    public function staff(): HasMany
    {
        return $this->hasMany(VenueStaff::class);
    }

    /**
     * Terminals relationship.
     */
    public function terminals(): HasMany
    {
        return $this->hasMany(VenueTerminal::class);
    }

    /**
     * Voucher codes relationship.
     */
    public function voucherCodes(): HasMany
    {
        return $this->hasMany(VoucherCode::class);
    }

    /**
     * Shifts relationship.
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(VenueShift::class);
    }

    /**
     * Get full address as string.
     */
    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address_line_1,
            $this->address_line_2,
            $this->city,
            $this->region,
            $this->postal_code,
            $this->country_code,
        ]);

        return implode(', ', $parts);
    }
}
