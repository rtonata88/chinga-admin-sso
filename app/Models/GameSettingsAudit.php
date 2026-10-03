<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One settings save: who, where, the settings before and after, and the RTP before and after. Append-only. */
class GameSettingsAudit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['game_id', 'tenant_id', 'user_id', 'scope', 'before', 'after', 'rtp_before', 'rtp_after'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
