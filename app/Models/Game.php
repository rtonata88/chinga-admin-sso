<?php

namespace App\Models;

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
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
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

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_games')
            ->withPivot(['enabled', 'custom_settings'])
            ->withTimestamps();
    }
}
