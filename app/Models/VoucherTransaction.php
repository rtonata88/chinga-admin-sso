<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class VoucherTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'voucher_code_id',
        'game_session_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'reference',
        'description',
        'performed_by_staff_id',
        'terminal_id',
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

        static::creating(function (VoucherTransaction $transaction) {
            if (empty($transaction->uuid)) {
                $transaction->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Check if this is a credit transaction (adds to balance).
     */
    public function isCredit(): bool
    {
        return in_array($this->type, ['load', 'win', 'transfer_in', 'adjustment']) && $this->amount > 0;
    }

    /**
     * Check if this is a debit transaction (subtracts from balance).
     */
    public function isDebit(): bool
    {
        return in_array($this->type, ['bet', 'cashout', 'transfer_out']) || $this->amount < 0;
    }

    /**
     * Get human-readable type label.
     */
    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            'load' => 'Credit Load',
            'win' => 'Winnings',
            'bet' => 'Bet/Wager',
            'cashout' => 'Cash Out',
            'adjustment' => 'Adjustment',
            'transfer_in' => 'Transfer In',
            'transfer_out' => 'Transfer Out',
            default => ucfirst($this->type),
        };
    }

    /**
     * Relationships.
     */
    public function voucherCode(): BelongsTo
    {
        return $this->belongsTo(VoucherCode::class);
    }

    public function gameSession(): BelongsTo
    {
        return $this->belongsTo(GameSession::class, 'game_session_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(VenueStaff::class, 'performed_by_staff_id');
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(VenueTerminal::class, 'terminal_id');
    }

    /**
     * Scope for specific transaction types.
     */
    public function scopeOfType($query, string|array $types)
    {
        return $query->whereIn('type', (array) $types);
    }

    /**
     * Scope for transactions in a date range.
     */
    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope for transactions by reference.
     */
    public function scopeByReference($query, string $reference)
    {
        return $query->where('reference', $reference);
    }
}
