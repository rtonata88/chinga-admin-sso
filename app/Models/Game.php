<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Game extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'description',
        'type',
        'status',
        'version',
        'thumbnail_url',
        'backend_url',
        'launch_url',
        'settings',
        'settings_schema',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'settings_schema' => 'array',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Game $game) {
            if (empty($game->uuid)) {
                $game->uuid = (string) Str::uuid();
            }
            if (empty($game->slug)) {
                $game->slug = Str::slug($game->name);
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** Games that have an admin backend to talk to (health, stats, rounds). */
    public function scopeWithBackend(Builder $query): Builder
    {
        return $query->whereNotNull('backend_url')->where('backend_url', '!=', '');
    }

    public function hasBackend(): bool
    {
        return is_string($this->backend_url) && $this->backend_url !== '';
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_games')
            ->using(TenantGame::class)
            ->withPivot(['enabled', 'custom_settings'])
            ->withTimestamps();
    }

    /**
     * OAuth clients authorized to call service-level endpoints (e.g.
     * settlement credit) against sessions of this game.
     */
    public function oauthClients(): BelongsToMany
    {
        return $this->belongsToMany(
            \Laravel\Passport\Client::class,
            'oauth_client_games',
            'game_id',
            'oauth_client_id',
        )->withTimestamps();
    }
}
