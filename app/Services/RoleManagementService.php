<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\SecurityAuditService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoleManagementService
{
    private const HIERARCHY = [
        'platform_super_admin' => 0,
        'platform_admin' => 1,
        'tenant_admin' => 2,
        'tenant_manager' => 3,
        'player' => 4,
    ];

    public function __construct(
        protected SecurityAuditService $auditService
    ) {}

    public function getAssignableRoles(User $actor, ?int $tenantId = null): Collection
    {
        $actorLevel = $this->getHighestLevel($actor);

        return Role::query()
            ->when($tenantId !== null, function ($q) {
                $q->where('is_platform_role', false);
            })
            ->get()
            ->filter(function (Role $role) use ($actorLevel) {
                $roleLevel = self::HIERARCHY[$role->name] ?? null;
                return $roleLevel !== null && $roleLevel > $actorLevel;
            })
            ->values();
    }

    public function assignRole(User $actor, User $target, string $roleName, ?int $tenantId = null): void
    {
        $this->guardSelfAssignment($actor, $target);

        $role = Role::where('name', $roleName)->first();
        if (! $role) {
            throw ValidationException::withMessages(['role' => ["Role '{$roleName}' does not exist."]]);
        }

        $this->guardHierarchy($actor, $role, strict: true);
        $this->guardTenantExists($tenantId, $role);

        DB::transaction(function () use ($actor, $target, $role, $roleName, $tenantId) {
            User::lockForUpdate()->find($target->id);

            if ($target->hasRole($roleName, $tenantId)) {
                throw ValidationException::withMessages(['role' => ['User already has this role.']]);
            }

            $oldRoles = $target->getRoleNames();
            $target->roles()->attach($role->id, ['tenant_id' => $tenantId]);
            $target->load('roles');
            $newRoles = $target->getRoleNames();

            $this->auditService->logAdminAction($actor, $target, 'role_assigned', [
                'role' => $roleName,
                'tenant_id' => $tenantId,
                'old_roles' => $oldRoles,
                'new_roles' => $newRoles,
            ]);
        });
    }

    public function removeRole(User $actor, User $target, string $roleName, ?int $tenantId = null): void
    {
        $this->guardSelfAssignment($actor, $target);

        $role = Role::where('name', $roleName)->first();
        if (! $role) {
            throw ValidationException::withMessages(['role' => ["Role '{$roleName}' does not exist."]]);
        }

        $this->guardHierarchy($actor, $role, strict: false);
        $this->guardTenantExists($tenantId, $role);

        DB::transaction(function () use ($actor, $target, $role, $roleName, $tenantId) {
            User::lockForUpdate()->find($target->id);

            if (! $target->hasRole($roleName, $tenantId)) {
                throw ValidationException::withMessages(['role' => ['User does not have this role.']]);
            }

            $this->guardLastSuperAdmin($roleName);

            $oldRoles = $target->getRoleNames();
            $target->roles()->wherePivot('role_id', $role->id)->wherePivot('tenant_id', $tenantId)->detach($role->id);
            $target->load('roles');
            $newRoles = $target->getRoleNames();

            $this->auditService->logAdminAction($actor, $target, 'role_removed', [
                'role' => $roleName,
                'tenant_id' => $tenantId,
                'old_roles' => $oldRoles,
                'new_roles' => $newRoles,
            ]);
        });
    }

    private function getHighestLevel(User $actor): int
    {
        $level = PHP_INT_MAX;

        foreach ($actor->roles as $role) {
            $roleLevel = self::HIERARCHY[$role->name] ?? null;
            if ($roleLevel !== null && $roleLevel < $level) {
                $level = $roleLevel;
            }
        }

        return $level;
    }

    private function guardSelfAssignment(User $actor, User $target): void
    {
        if ($actor->id === $target->id) {
            throw new AuthorizationException('You cannot modify your own roles.');
        }
    }

    private function guardHierarchy(User $actor, Role $role, bool $strict = true): void
    {
        $actorLevel = $this->getHighestLevel($actor);
        $roleLevel = self::HIERARCHY[$role->name] ?? null;

        if ($roleLevel === null) {
            throw new AuthorizationException('You cannot manage this role.');
        }

        // Strict mode (for assign): cannot assign roles at or above your own level
        // Non-strict mode (for remove): cannot remove roles above your own level
        $blocked = $strict ? $roleLevel <= $actorLevel : $roleLevel < $actorLevel;

        if ($blocked) {
            throw new AuthorizationException('You cannot assign or remove a role at or above your own level.');
        }
    }

    private function guardTenantExists(?int $tenantId, Role $role): void
    {
        if (! $role->is_platform_role && $tenantId === null) {
            throw ValidationException::withMessages(['tenant_id' => ['Tenant is required for tenant-scoped roles.']]);
        }

        if ($tenantId !== null && ! Tenant::where('id', $tenantId)->exists()) {
            throw ValidationException::withMessages(['tenant_id' => ['Tenant does not exist.']]);
        }
    }

    private function guardLastSuperAdmin(string $roleName): void
    {
        if ($roleName !== 'platform_super_admin') {
            return;
        }

        $count = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('roles.name', 'platform_super_admin')
            ->whereNull('user_roles.tenant_id')
            ->count();

        if ($count <= 1) {
            throw ValidationException::withMessages(['role' => ['Cannot remove the last platform super admin.']]);
        }
    }
}
