<?php

namespace App\Models\Concerns;

use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait HasRoles
{
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot('tenant_id')
            ->withTimestamps();
    }

    public function hasRole(string $role, ?int $tenantId = null): bool
    {
        return $this->roles()
            ->where('name', $role)
            ->where(function ($query) use ($tenantId) {
                if ($tenantId !== null) {
                    $query->where('user_roles.tenant_id', $tenantId);
                } else {
                    $query->whereNull('user_roles.tenant_id');
                }
            })
            ->exists();
    }

    public function hasAnyRole(array $roles, ?int $tenantId = null): bool
    {
        return $this->roles()
            ->whereIn('name', $roles)
            ->where(function ($query) use ($tenantId) {
                if ($tenantId !== null) {
                    $query->where('user_roles.tenant_id', $tenantId);
                } else {
                    $query->whereNull('user_roles.tenant_id');
                }
            })
            ->exists();
    }

    public function hasPermission(string $permission): bool
    {
        return $this->roles->contains(fn (Role $role) => $role->hasPermission($permission));
    }

    public function assignRole(string $roleName, ?int $tenantId = null): void
    {
        $role = Role::where('name', $roleName)->firstOrFail();

        // Check if this exact role+tenant combination already exists
        $exists = $this->roles()
            ->where('role_id', $role->id)
            ->wherePivot('tenant_id', $tenantId)
            ->exists();

        if (! $exists) {
            $this->roles()->attach($role->id, ['tenant_id' => $tenantId]);
        }
    }

    public function removeRole(string $roleName, ?int $tenantId = null): void
    {
        $role = Role::where('name', $roleName)->first();
        if (! $role) {
            return;
        }

        $this->roles()
            ->wherePivot('role_id', $role->id)
            ->wherePivot('tenant_id', $tenantId)
            ->detach($role->id);
    }

    public function isPlatformAdmin(): bool
    {
        return $this->hasAnyRole(['platform_super_admin', 'platform_admin']);
    }

    public function isPlatformSuperAdmin(): bool
    {
        return $this->hasRole('platform_super_admin');
    }

    public function isTenantAdmin(?int $tenantId = null): bool
    {
        $tenantId = $tenantId ?? app('current_tenant')?->id;

        if (! $tenantId) {
            return false;
        }

        return $this->hasAnyRole(['tenant_admin', 'tenant_manager'], $tenantId);
    }

    public function isAdmin(): bool
    {
        if ($this->isPlatformAdmin()) {
            return true;
        }

        $tenant = app('current_tenant');

        return $tenant && $this->isTenantAdmin($tenant->id);
    }

    public function isSuperAdmin(): bool
    {
        return $this->isPlatformSuperAdmin();
    }

    public function getRoleNames(): array
    {
        return $this->roles->pluck('name')->toArray();
    }
}
