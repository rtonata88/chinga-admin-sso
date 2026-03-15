<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class VoucherCode extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'venue_id',
        'code',
        'pin',
        'balance',
        'currency',
        'status',
        'created_by_staff_id',
        'created_by_admin_id',
        'current_terminal_id',
        'current_session_id',
        'total_loaded',
        'total_won',
        'total_lost',
        'total_cashed_out',
        'last_activity_at',
        'expires_at',
    ];

    protected $hidden = [
        'pin',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'total_loaded' => 'decimal:2',
            'total_won' => 'decimal:2',
            'total_lost' => 'decimal:2',
            'total_cashed_out' => 'decimal:2',
            'last_activity_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (VoucherCode $code) {
            if (empty($code->uuid)) {
                $code->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /**
     * Status checks.
     */
    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'in_use']);
    }

    public function isInUse(): bool
    {
        return $this->status === 'in_use';
    }

    public function isExpired(): bool
    {
        if ($this->status === 'expired') {
            return true;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return true;
        }

        return false;
    }

    public function canBeUsed(): bool
    {
        return $this->isActive() && !$this->isExpired() && $this->balance > 0;
    }

    /**
     * PIN operations.
     */
    public function hasPin(): bool
    {
        return !empty($this->pin);
    }

    public function verifyPin(string $pin): bool
    {
        if (!$this->hasPin()) {
            return true; // No PIN required
        }

        return password_verify($pin, $this->pin);
    }

    public function setPin(string $pin): void
    {
        $this->pin = bcrypt($pin);
        $this->save();
    }

    public function removePin(): void
    {
        $this->pin = null;
        $this->save();
    }

    /**
     * Balance operations.
     */
    public function hasBalance(): bool
    {
        return $this->balance > 0;
    }

    public function hasSufficientBalance(float $amount): bool
    {
        return $this->balance >= $amount;
    }

    /**
     * Get masked code for display.
     */
    public function getMaskedCodeAttribute(): string
    {
        $length = strlen($this->code);
        if ($length <= 3) {
            return $this->code;
        }

        $visible = 3;
        $masked = str_repeat('*', $length - $visible);

        return substr($this->code, 0, $visible) . $masked;
    }

    /**
     * Update last activity timestamp.
     */
    public function touchActivity(): void
    {
        $this->update(['last_activity_at' => now()]);
    }

    /**
     * Relationships.
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(VenueStaff::class, 'created_by_staff_id');
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    public function currentTerminal(): BelongsTo
    {
        return $this->belongsTo(VenueTerminal::class, 'current_terminal_id');
    }

    public function currentSession(): BelongsTo
    {
        return $this->belongsTo(VoucherSession::class, 'current_session_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(VoucherSession::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(VoucherTransaction::class);
    }

    /**
     * Scope for active codes.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['active', 'in_use']);
    }

    /**
     * Scope for codes at a specific venue.
     */
    public function scopeAtVenue($query, int $venueId)
    {
        return $query->where('venue_id', $venueId);
    }

    /**
     * Scope for non-expired codes.
     */
    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }
}
