<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = [
        'name',
        'display_name',
        'description',
        'is_platform_role',
    ];

    protected function casts(): array
    {
        return [
            'is_platform_role' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->withPivot('tenant_id')
            ->withTimestamps();
    }

    public function hasPermission(string $permission): bool
    {
        return $this->permissions->contains('name', $permission);
    }
}
