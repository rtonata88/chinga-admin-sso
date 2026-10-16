<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The hand-entered treasury inputs, one row per currency (each its own bank account); see the migrations. */
class TreasurySetting extends Model
{
    protected $fillable = ['currency', 'bank_balance', 'bank_balance_as_of', 'variance_reserve', 'tax_pct', 'updated_by'];

    protected function casts(): array
    {
        return [
            'bank_balance' => 'decimal:2',
            'bank_balance_as_of' => 'date',
            'variance_reserve' => 'decimal:2',
            'tax_pct' => 'decimal:2',
        ];
    }

    public static function current(string $currency): self
    {
        return self::query()->firstOrCreate(['currency' => $currency]);
    }
}
