<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedFilter extends Model
{
    protected $fillable = ['user_id', 'filterable_type', 'name', 'is_shared', 'is_favorite', 'is_default', 'criteria', 'sort_config', 'sort_order'];

    protected $casts = [
        'criteria' => 'array',
        'sort_config' => 'array',
        'is_shared' => 'boolean',
        'is_favorite' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForGrid($q, string $type)
    {
        return $q->where('filterable_type', $type);
    }

    public function scopeVisibleTo($q, int $id)
    {
        return $q->where(fn($q) => $q->where('user_id', $id)->orWhere('is_shared', true));
    }

    public function scopeFavorites($q)
    {
        return $q->where('is_favorite', true);
    }
}
