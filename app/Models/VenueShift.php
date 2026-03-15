<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class VenueShift extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'venue_id',
        'staff_id',
        'terminal_id',
        'opening_balance',
        'closing_balance',
        'total_loads',
        'total_cashouts',
        'codes_created',
        'started_at',
        'ended_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'closing_balance' => 'decimal:2',
            'total_loads' => 'decimal:2',
            'total_cashouts' => 'decimal:2',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (VenueShift $shift) {
            if (empty($shift->uuid)) {
                $shift->uuid = (string) Str::uuid();
            }
            if (empty($shift->started_at)) {
                $shift->started_at = now();
            }
        });
    }

    /**
     * Check if shift is currently active.
     */
    public function isActive(): bool
    {
        return is_null($this->ended_at);
    }

    /**
     * End the shift.
     */
    public function end(float $closingBalance, ?string $notes = null): void
    {
        $this->update([
            'ended_at' => now(),
            'closing_balance' => $closingBalance,
            'notes' => $notes ?? $this->notes,
        ]);
    }

    /**
     * Add to total loads.
     */
    public function recordLoad(float $amount): void
    {
        $this->increment('total_loads', $amount);
    }

    /**
     * Add to total cashouts.
     */
    public function recordCashout(float $amount): void
    {
        $this->increment('total_cashouts', $amount);
    }

    /**
     * Increment codes created count.
     */
    public function recordCodeCreated(): void
    {
        $this->increment('codes_created');
    }

    /**
     * Get shift duration in hours.
     */
    public function getDurationHoursAttribute(): ?float
    {
        if (!$this->ended_at) {
            return round($this->started_at->diffInMinutes(now()) / 60, 2);
        }

        return round($this->started_at->diffInMinutes($this->ended_at) / 60, 2);
    }

    /**
     * Calculate expected drawer balance.
     */
    public function getExpectedBalanceAttribute(): float
    {
        return $this->opening_balance + $this->total_loads - $this->total_cashouts;
    }

    /**
     * Calculate variance (actual vs expected).
     */
    public function getVarianceAttribute(): ?float
    {
        if (is_null($this->closing_balance)) {
            return null;
        }

        return $this->closing_balance - $this->expected_balance;
    }

    /**
     * Relationships.
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(VenueStaff::class, 'staff_id');
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(VenueTerminal::class, 'terminal_id');
    }

    /**
     * Scope for active shifts.
     */
    public function scopeActive($query)
    {
        return $query->whereNull('ended_at');
    }

    /**
     * Scope for shifts at a venue.
     */
    public function scopeAtVenue($query, int $venueId)
    {
        return $query->where('venue_id', $venueId);
    }
}
