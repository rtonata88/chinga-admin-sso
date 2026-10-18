<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A hosted-mode transfer between an operator's wallet and ours; the operator's reference makes a repeat a no-op. */
class OperatorTransfer extends Model
{
    protected $fillable = ['operator_integration_id', 'user_id', 'reference', 'direction', 'amount', 'currency', 'wallet_transaction_id'];
}
