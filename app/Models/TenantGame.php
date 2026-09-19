<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * tenant_games pivot. Casting custom_settings here is what makes tenant
 * overrides round-trip as arrays; before this, readers got a raw JSON
 * string and writers had to json_encode by hand.
 */
class TenantGame extends Pivot
{
    protected $table = 'tenant_games';

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'custom_settings' => 'array',
        ];
    }
}
