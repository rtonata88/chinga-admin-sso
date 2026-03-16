<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

class Wallet extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'user_id',
        'balance',
        'currency',
        'status',
        'total_deposited',
        'total_withdrawn',
        'total_won',
        'total_lost',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'total_deposited' => 'decimal:2',
            'total_withdrawn' => 'decimal:2',
            'total_won' => 'decimal:2',
            'total_lost' => 'decimal:2',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Wallet $wallet) {
            if (empty($wallet->uuid)) {
                $wallet->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Status checks.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isFrozen(): bool
    {
        return $this->status === 'frozen';
    }

    /**
     * Check if the wallet has sufficient balance for the given amount.
     */
    public function hasSufficientBalance(string $amount): bool
    {
        return bccomp($this->balance, $amount, 2) >= 0;
    }

    /**
     * Check if the wallet has any balance.
     */
    public function hasBalance(): bool
    {
        return bccomp($this->balance, '0', 2) > 0;
    }

    /**
     * Relationships.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function gameSessions(): MorphMany
    {
        return $this->morphMany(GameSession::class, 'source');
    }
}
