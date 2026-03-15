<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class VenueTerminal extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'venue_id',
        'terminal_code',
        'name',
        'type',
        'api_key',
        'status',
        'last_heartbeat_at',
        'ip_address',
        'settings',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (VenueTerminal $terminal) {
            if (empty($terminal->uuid)) {
                $terminal->uuid = (string) Str::uuid();
            }
            if (empty($terminal->api_key)) {
                $terminal->api_key = hash('sha256', Str::random(64));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Check if terminal is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if terminal is online (recent heartbeat).
     */
    public function isOnline(int $thresholdMinutes = 5): bool
    {
        if (!$this->last_heartbeat_at) {
            return false;
        }

        return $this->last_heartbeat_at->diffInMinutes(now()) <= $thresholdMinutes;
    }

    /**
     * Update heartbeat.
     */
    public function recordHeartbeat(?string $ipAddress = null): void
    {
        $this->update([
            'last_heartbeat_at' => now(),
            'ip_address' => $ipAddress ?? $this->ip_address,
        ]);
    }

    /**
     * Generate new API key.
     */
    public function regenerateApiKey(): string
    {
        $plainKey = Str::random(64);
        $this->api_key = hash('sha256', $plainKey);
        $this->save();

        return $plainKey; // Return plain key to show to admin once
    }

    /**
     * Verify API key.
     */
    public function verifyApiKey(string $key): bool
    {
        return hash_equals($this->api_key, hash('sha256', $key));
    }

    /**
     * Venue relationship.
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * Active voucher codes using this terminal.
     */
    public function activeVoucherCodes(): HasMany
    {
        return $this->hasMany(VoucherCode::class, 'current_terminal_id');
    }

    /**
     * Sessions on this terminal.
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(VoucherSession::class, 'terminal_id');
    }

    /**
     * Transactions performed on this terminal.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(VoucherTransaction::class, 'terminal_id');
    }

    /**
     * Shifts at this terminal.
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(VenueShift::class, 'terminal_id');
    }
}
