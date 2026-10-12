<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsMessage extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'channel_provider_id', 'msisdn', 'text', 'purpose', 'status', 'provider_reference', 'last_error', 'attempts', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
