<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One signed call in or out: what was called, how it ended, how long it took. Never the body itself, only its hash. */
class OperatorCall extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['operator_integration_id', 'direction', 'endpoint', 'reference', 'http_status', 'outcome', 'duration_ms', 'body_sha256', 'error'];
}
