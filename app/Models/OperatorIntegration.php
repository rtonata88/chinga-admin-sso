<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An operator's integration with us: the secret both sides sign with, and where the
 * money lives. `hosted`: in our wallet, moved by signed transfers. `seamless`: at the
 * operator, drawn on through its wallet API. One per tenant; the tenant is the operator.
 */
class OperatorIntegration extends Model
{
    public const MODE_HOSTED = 'hosted';

    public const MODE_SEAMLESS = 'seamless';

    /** A rotated-out secret keeps verifying for this long, so the operator can switch without downtime. */
    public const SECRET_OVERLAP_HOURS = 24;

    protected $fillable = [
        'tenant_id', 'operator_key', 'secret', 'previous_secret', 'secret_rotated_at',
        'wallet_mode', 'wallet_base_url', 'allowed_ips', 'embed_origins', 'status',
    ];

    protected $hidden = ['secret', 'previous_secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'secret_rotated_at' => 'datetime',
            'allowed_ips' => 'array',
            'embed_origins' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isSeamless(): bool
    {
        return $this->wallet_mode === self::MODE_SEAMLESS;
    }

    /** @return list<string> the secrets a signature may be made with right now */
    public function verifyingSecrets(): array
    {
        $secrets = [$this->secret];
        if ($this->previous_secret !== null && $this->secret_rotated_at !== null
            && $this->secret_rotated_at->gt(now()->subHours(self::SECRET_OVERLAP_HOURS))) {
            $secrets[] = $this->previous_secret;
        }

        return $secrets;
    }
}
