<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A one-time launch code: minted by a signed launch call, exchanged once by the game page for a player token. */
class OperatorLaunch extends Model
{
    public const TTL_SECONDS = 60;

    protected $fillable = ['code_hash', 'tenant_id', 'user_id', 'game_id', 'lang', 'return_url', 'expires_at', 'used_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public static function hash(string $code): string
    {
        return hash('sha256', $code);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
