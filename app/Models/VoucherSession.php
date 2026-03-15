<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class VoucherSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'voucher_code_id',
        'terminal_id',
        'game_client_id',
        'session_token',
        'ip_address',
        'balance_start',
        'balance_end',
        'started_at',
        'ended_at',
        'end_reason',
    ];

    protected function casts(): array
    {
        return [
            'balance_start' => 'decimal:2',
            'balance_end' => 'decimal:2',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (VoucherSession $session) {
            if (empty($session->uuid)) {
                $session->uuid = (string) Str::uuid();
            }
            if (empty($session->session_token)) {
                $session->session_token = Str::random(64);
            }
            if (empty($session->started_at)) {
                $session->started_at = now();
            }
        });
    }

    /**
     * Check if session is active.
     */
    public function isActive(): bool
    {
        return is_null($this->ended_at);
    }

    /**
     * End the session.
     */
    public function end(string $reason, ?float $balanceEnd = null): void
    {
        $this->update([
            'ended_at' => now(),
            'end_reason' => $reason,
            'balance_end' => $balanceEnd ?? $this->voucherCode->balance,
        ]);

        // Update the voucher code status
        $this->voucherCode->update([
            'status' => 'active',
            'current_terminal_id' => null,
            'current_session_id' => null,
        ]);
    }

    /**
     * Calculate session duration in minutes.
     */
    public function getDurationMinutesAttribute(): ?int
    {
        if (!$this->ended_at) {
            return $this->started_at->diffInMinutes(now());
        }

        return $this->started_at->diffInMinutes($this->ended_at);
    }

    /**
     * Calculate net result (profit/loss) for the session.
     */
    public function getNetResultAttribute(): ?float
    {
        if (is_null($this->balance_end)) {
            return null;
        }

        return $this->balance_end - $this->balance_start;
    }

    /**
     * Relationships.
     */
    public function voucherCode(): BelongsTo
    {
        return $this->belongsTo(VoucherCode::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(VenueTerminal::class, 'terminal_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(VoucherTransaction::class, 'session_id');
    }

    /**
     * Scope for active sessions.
     */
    public function scopeActive($query)
    {
        return $query->whereNull('ended_at');
    }

    /**
     * Scope to find by session token.
     */
    public function scopeByToken($query, string $token)
    {
        return $query->where('session_token', $token);
    }
}
