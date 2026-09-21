<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The single row of hand-entered treasury inputs; see the migration. */
class TreasurySetting extends Model
{
    protected $fillable = ['bank_balance', 'bank_balance_as_of', 'variance_reserve', 'tax_pct', 'updated_by'];

    protected function casts(): array
    {
        return [
            'bank_balance' => 'decimal:2',
            'bank_balance_as_of' => 'date',
            'variance_reserve' => 'decimal:2',
            'tax_pct' => 'decimal:2',
        ];
    }

    public static function current(): self
    {
        return self::query()->orderBy('id')->firstOrCreate([]);
    }
}
