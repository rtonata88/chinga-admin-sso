<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class FantasyTeam extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'name', 'short_name', 'logo_url', 'country', 'league', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (FantasyTeam $team) {
            if (empty($team->uuid)) {
                $team->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
