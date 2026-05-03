<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WithdrawalRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'user_id',
        'wallet_id',
        'hold_transaction_id',
        'refund_transaction_id',
        'amount',
        'fee_amount',
        'net_amount',
        'currency',
        'payment_method',
        'payment_details',
        'status',
        'external_reference',
        'rejection_reason',
        'notes',
        'reviewed_by',
        'reviewed_at',
        'paid_by',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'payment_details' => 'array',
            'reviewed_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (WithdrawalRequest $request) {
            if (empty($request->uuid)) {
                $request->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['paid', 'rejected', 'cancelled'], true);
    }

    public function isRefundable(): bool
    {
        // Money is held until it's actually paid. Reject/cancel before paid → refund.
        return in_array($this->status, ['requested', 'approved'], true);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function holdTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'hold_transaction_id');
    }

    public function refundTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'refund_transaction_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
