<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One stake, win or returned stake sent to an operator's wallet: written before the call, settled after (SeamlessWallet). */
class OperatorMovement extends Model
{
    protected $fillable = [
        'operator_integration_id', 'user_id', 'game_session_id', 'kind', 'reference', 'debit_reference',
        'amount', 'currency', 'status', 'error_code', 'operator_transaction_id', 'balance_after',
        'attempts', 'next_attempt_at', 'wallet_transaction_id',
    ];

    protected function casts(): array
    {
        return ['next_attempt_at' => 'datetime'];
    }
}
