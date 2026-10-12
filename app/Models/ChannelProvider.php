<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A tenant's USSD, mobile-money, KYC or SMS provider (USSD design §5). Its config, secrets included, is encrypted at rest. */
class ChannelProvider extends Model
{
    protected $fillable = ['uuid', 'tenant_id', 'kind', 'driver', 'name', 'config', 'status'];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return ['config' => 'encrypted:array'];
    }

    protected static function booted(): void
    {
        static::creating(function (ChannelProvider $p) {
            $p->uuid ??= (string) Str::uuid();
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
