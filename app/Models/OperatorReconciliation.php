<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One day's match of our seamless movements against an operator's statement. */
class OperatorReconciliation extends Model
{
    protected $fillable = ['operator_integration_id', 'date', 'status', 'equal', 'only_ours', 'only_theirs', 'different', 'unreadable_lines', 'details'];

    protected function casts(): array
    {
        return ['date' => 'date', 'details' => 'array'];
    }
}
