<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormConfiguration extends Model
{
    protected $fillable = ['form_name', 'scope', 'user_id', 'fieldsets', 'grid_columns', 'tab_order', 'is_active'];

    protected $casts = [
        'fieldsets' => 'array',
        'grid_columns' => 'array',
        'tab_order' => 'array',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForForm($q, string $n)
    {
        return $q->where('form_name', $n)->where('is_active', true);
    }

    public function scopeSystem($q)
    {
        return $q->where('scope', 'system');
    }

    public function scopeForUser($q, int $id)
    {
        return $q->where('scope', 'user')->where('user_id', $id);
    }
}
