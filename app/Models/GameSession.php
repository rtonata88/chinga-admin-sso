<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class GameSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'session_token',
        'tenant_id',
        'game_id',
        'source_type',
        'source_id',
        'terminal_id',
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

        static::creating(function (GameSession $session) {
            if (empty($session->uuid)) {
                $session->uuid = (string) Str::uuid();
            }
            if (empty($session->session_token)) {
                $session->session_token = 'gs_' . Str::random(64);
            }
            if (empty($session->started_at)) {
                $session->started_at = now();
            }
        });
    }

    /**
     * Check if the session is still active.
     * A session is active if it hasn't ended and was updated within the last 30 minutes.
     */
    public function isActive(): bool
    {
        return is_null($this->ended_at)
            && $this->updated_at->diffInMinutes(now()) <= 30;
    }

    /**
     * End the session.
     */
    public function end(string $reason, ?string $balanceEnd = null): void
    {
        $this->update([
            'ended_at' => now(),
            'end_reason' => $reason,
            'balance_end' => $balanceEnd ?? $this->getSourceBalance(),
        ]);

        // If source is a VoucherCode, reset its status
        if ($this->source_type === VoucherCode::class) {
            $this->source->update([
                'status' => 'active',
                'current_terminal_id' => null,
                'current_session_id' => null,
            ]);
        }
    }

    /**
     * Get the current balance of the source.
     */
    private function getSourceBalance(): string
    {
        return $this->source->fresh()->balance;
    }

    /**
     * Net result of the session (balance_end - balance_start).
     */
    public function getNetResultAttribute(): ?string
    {
        if (is_null($this->balance_end)) {
            return null;
        }

        return bcsub($this->balance_end, $this->balance_start, 2);
    }

    /**
     * Duration of the session in minutes.
     */
    public function getDurationMinutesAttribute(): ?float
    {
        $endTime = $this->ended_at ?? now();

        return $this->started_at->diffInSeconds($endTime) / 60;
    }

    /**
     * Relationships.
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(VenueTerminal::class, 'terminal_id');
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function voucherTransactions(): HasMany
    {
        return $this->hasMany(VoucherTransaction::class, 'game_session_id');
    }

    /**
     * Scope for active sessions.
     */
    public function scopeActive($query)
    {
        return $query->whereNull('ended_at')
            ->where('updated_at', '>=', now()->subMinutes(30));
    }

    /**
     * Scope to find by session token.
     */
    public function scopeByToken($query, string $token)
    {
        return $query->where('session_token', $token);
    }
}
