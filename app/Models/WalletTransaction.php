<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WalletTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'wallet_id',
        'game_session_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'reference',
        'description',
        'performed_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (WalletTransaction $transaction) {
            if (empty($transaction->uuid)) {
                $transaction->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Check if this transaction is a credit (adds to balance).
     */
    public function isCredit(): bool
    {
        if (in_array($this->type, ['deposit', 'win'])) {
            return true;
        }

        if ($this->type === 'adjustment' && bccomp($this->amount, '0', 2) > 0) {
            return true;
        }

        return false;
    }

    /**
     * Check if this transaction is a debit (subtracts from balance).
     */
    public function isDebit(): bool
    {
        if (in_array($this->type, ['withdrawal', 'bet'])) {
            return true;
        }

        if ($this->type === 'adjustment' && bccomp($this->amount, '0', 2) < 0) {
            return true;
        }

        return false;
    }

    /**
     * Relationships.
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function gameSession(): BelongsTo
    {
        return $this->belongsTo(GameSession::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
