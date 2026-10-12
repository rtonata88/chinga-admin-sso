<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** One deposit (mm_dep_<id>) or withdrawal (mm_wd_<id>) on a mobile-money rail (USSD design U-D8, U-D9). */
class MobileMoneyTransaction extends Model
{
    protected $fillable = [
        'uuid', 'tenant_id', 'user_id', 'channel_provider_id', 'direction', 'amount', 'fee', 'currency', 'msisdn',
        'reference', 'provider_reference', 'status', 'withdrawal_request_id', 'wallet_transaction_id', 'last_error',
        'status_checks', 'next_check_at', 'callbacks', 'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'callbacks' => 'array',
            'next_check_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MobileMoneyTransaction $t) {
            $t->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ChannelProvider::class, 'channel_provider_id');
    }
}
