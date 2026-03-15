# Role Management UI Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add role assignment/removal UI for tenant admins (on existing users page) and platform admins (on new platform users page), with hierarchy-based restrictions and audit logging.

**Architecture:** Shared `RoleManagementService` encapsulates hierarchy validation, assignment, removal, and audit logging. Two thin controllers delegate to it — `RoleManagementController` (tenant-scoped) and `PlatformRoleManagementController` (cross-tenant). Frontend uses PrimeReact Dialog modals on each users page.

**Tech Stack:** Laravel 12, PHP 8.4, React 19, Inertia.js, PrimeReact, Pest (testing)

**Spec:** `docs/superpowers/specs/2026-03-15-role-management-ui-design.md`

---

## Chunk 1: Backend Service & Seeder

### Task 1: Create Tenant factory (if missing)

**Files:**
- Check/Create: `database/factories/TenantFactory.php`

- [ ] **Step 1: Check if Tenant factory exists**

Run: `ls database/factories/TenantFactory.php 2>/dev/null || echo "MISSING"`

If missing, create `database/factories/TenantFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'uuid' => Str::uuid()->toString(),
            'name' => $name,
            'slug' => Str::slug($name),
            'legal_name' => $name . ' Ltd',
            'contact_email' => fake()->companyEmail(),
            'country_code' => 'NA',
            'currency' => 'NAD',
            'timezone' => 'Africa/Windhoek',
            'status' => 'active',
        ];
    }
}
```

- [ ] **Step 2: Commit if created**

```bash
git add database/factories/TenantFactory.php
git commit -m "feat: add Tenant factory for testing"
```

---

### Task 2: Update RbacSeeder to grant `users.manage-roles` to `tenant_admin`

**Files:**
- Modify: `database/seeders/RbacSeeder.php:118-125`

- [ ] **Step 1: Update the tenant_admin permission list**

In `database/seeders/RbacSeeder.php`, add `'users.manage-roles'` to the `tenant_admin` array:

```php
'tenant_admin' => [
    'users.view', 'users.create', 'users.update', 'users.manage-status', 'users.manage-roles',
    'venues.*', 'voucher-codes.*', 'kyc.*',
    'reports.view', 'reports.export',
    'oauth-clients.view', 'oauth-clients.create', 'oauth-clients.update', 'oauth-clients.delete',
    'responsible-gambling.view', 'responsible-gambling.manage',
    'games.view',
],
```

- [ ] **Step 2: Run the seeder to apply**

Run: `php artisan db:seed --class=RbacSeeder`
Expected: No errors. The `tenant_admin` role now has the `users.manage-roles` permission.

- [ ] **Step 3: Commit**

```bash
git add database/seeders/RbacSeeder.php
git commit -m "feat: grant users.manage-roles permission to tenant_admin role"
```

---

### Task 3: Create RoleManagementService

**Files:**
- Create: `app/Services/RoleManagementService.php`
- Test: `tests/Feature/Services/RoleManagementServiceTest.php`

- [ ] **Step 1: Write failing tests for the service**

Create `tests/Feature/Services/RoleManagementServiceTest.php`:

```php
<?php

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RoleManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->service = app(RoleManagementService::class);
});

test('getAssignableRoles returns roles below actor level for platform_super_admin', function () {
    $actor = User::factory()->create();
    $actor->assignRole('platform_super_admin');

    $roles = $this->service->getAssignableRoles($actor);

    $names = $roles->pluck('name')->toArray();
    expect($names)->toContain('platform_admin', 'tenant_admin', 'tenant_manager', 'player')
        ->and($names)->not->toContain('platform_super_admin');
});

test('getAssignableRoles returns roles below actor level for platform_admin', function () {
    $actor = User::factory()->create();
    $actor->assignRole('platform_admin');

    $roles = $this->service->getAssignableRoles($actor);

    $names = $roles->pluck('name')->toArray();
    expect($names)->toContain('tenant_admin', 'tenant_manager', 'player')
        ->and($names)->not->toContain('platform_super_admin', 'platform_admin');
});

test('getAssignableRoles returns roles below actor level for tenant_admin', function () {
    $tenant = Tenant::factory()->create();
    $actor = User::factory()->create(['tenant_id' => $tenant->id]);
    $actor->assignRole('tenant_admin', $tenant->id);

    $roles = $this->service->getAssignableRoles($actor, $tenant->id);

    $names = $roles->pluck('name')->toArray();
    expect($names)->toContain('tenant_manager', 'player')
        ->and($names)->not->toContain('platform_super_admin', 'platform_admin', 'tenant_admin');
});

test('assignRole assigns a role to a user', function () {
    $actor = User::factory()->create();
    $actor->assignRole('platform_super_admin');
    $target = User::factory()->create();

    $this->service->assignRole($actor, $target, 'platform_admin');

    expect($target->hasRole('platform_admin'))->toBeTrue();
});

test('assignRole with tenant_id assigns tenant-scoped role', function () {
    $tenant = Tenant::factory()->create();
    $actor = User::factory()->create();
    $actor->assignRole('platform_super_admin');
    $target = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->service->assignRole($actor, $target, 'tenant_admin', $tenant->id);

    expect($target->hasRole('tenant_admin', $tenant->id))->toBeTrue();
});

test('assignRole throws AuthorizationException for self-assignment', function () {
    $actor = User::factory()->create();
    $actor->assignRole('platform_super_admin');

    $this->service->assignRole($actor, $actor, 'platform_admin');
})->throws(AuthorizationException::class);

test('assignRole throws AuthorizationException for hierarchy violation', function () {
    $actor = User::factory()->create();
    $actor->assignRole('platform_admin');
    $target = User::factory()->create();

    $this->service->assignRole($actor, $target, 'platform_super_admin');
})->throws(AuthorizationException::class);

test('assignRole throws ValidationException for duplicate role', function () {
    $actor = User::factory()->create();
    $actor->assignRole('platform_super_admin');
    $target = User::factory()->create();
    $target->assignRole('platform_admin');

    $this->service->assignRole($actor, $target, 'platform_admin');
})->throws(ValidationException::class);

test('removeRole removes a role from a user', function () {
    $actor = User::factory()->create();
    $actor->assignRole('platform_super_admin');
    $target = User::factory()->create();
    $target->assignRole('platform_admin');

    $this->service->removeRole($actor, $target, 'platform_admin');

    expect($target->hasRole('platform_admin'))->toBeFalse();
});

test('removeRole throws AuthorizationException for self-removal', function () {
    $actor = User::factory()->create();
    $actor->assignRole('platform_super_admin');

    $this->service->removeRole($actor, $actor, 'platform_super_admin');
})->throws(AuthorizationException::class);

test('removeRole throws AuthorizationException for hierarchy violation', function () {
    $actor = User::factory()->create();
    $actor->assignRole('platform_admin');
    $target = User::factory()->create();
    $target->assignRole('platform_super_admin');

    $this->service->removeRole($actor, $target, 'platform_super_admin');
})->throws(AuthorizationException::class);

test('removeRole allows removing platform_super_admin when multiple exist', function () {
    $actor = User::factory()->create();
    $actor->assignRole('platform_super_admin');
    $target = User::factory()->create();
    $target->assignRole('platform_super_admin');

    // Two super admins exist — removing one should succeed
    $this->service->removeRole($actor, $target, 'platform_super_admin');
    expect($target->hasRole('platform_super_admin'))->toBeFalse();
});

test('last platform_super_admin is protected by self-assignment and hierarchy checks', function () {
    // With only one super admin, no one can remove the role:
    // - The super admin can't remove their own role (self-assignment block)
    // - No other user has high enough privilege to remove it (hierarchy block)
    $sole = User::factory()->create();
    $sole->assignRole('platform_super_admin');
    $other = User::factory()->create();
    $other->assignRole('platform_admin');

    // Self-removal blocked
    expect(fn () => $this->service->removeRole($sole, $sole, 'platform_super_admin'))
        ->toThrow(AuthorizationException::class);

    // Lower-privilege user blocked by hierarchy
    expect(fn () => $this->service->removeRole($other, $sole, 'platform_super_admin'))
        ->toThrow(AuthorizationException::class);
});

test('removeRole throws ValidationException if user does not have the role', function () {
    $actor = User::factory()->create();
    $actor->assignRole('platform_super_admin');
    $target = User::factory()->create();

    $this->service->removeRole($actor, $target, 'platform_admin');
})->throws(ValidationException::class);
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Services/RoleManagementServiceTest.php`
Expected: All tests FAIL (class not found).

- [ ] **Step 3: Create the RoleManagementService**

Create `app/Services/RoleManagementService.php`:

```php
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
    /**
     * Role hierarchy from highest to lowest privilege.
     */
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

    /**
     * Get roles the actor is allowed to assign.
     * When $tenantId is provided, only tenant-scoped roles are returned.
     * When $tenantId is null, all roles below the actor's level are returned.
     */
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

    /**
     * Assign a role to a user.
     */
    public function assignRole(User $actor, User $target, string $roleName, ?int $tenantId = null): void
    {
        $this->guardSelfAssignment($actor, $target);

        $role = Role::where('name', $roleName)->first();
        if (! $role) {
            throw ValidationException::withMessages(['role' => ["Role '{$roleName}' does not exist."]]);
        }

        $this->guardHierarchy($actor, $role);
        $this->guardTenantExists($tenantId, $role);

        DB::transaction(function () use ($actor, $target, $role, $roleName, $tenantId) {
            // Lock the target user row to serialize concurrent role operations
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

    /**
     * Remove a role from a user.
     */
    public function removeRole(User $actor, User $target, string $roleName, ?int $tenantId = null): void
    {
        $this->guardSelfAssignment($actor, $target);

        $role = Role::where('name', $roleName)->first();
        if (! $role) {
            throw ValidationException::withMessages(['role' => ["Role '{$roleName}' does not exist."]]);
        }

        $this->guardHierarchy($actor, $role);

        DB::transaction(function () use ($actor, $target, $role, $roleName, $tenantId) {
            User::lockForUpdate()->find($target->id);

            if (! $target->hasRole($roleName, $tenantId)) {
                throw ValidationException::withMessages(['role' => ['User does not have this role.']]);
            }

            $this->guardLastSuperAdmin($roleName);

            $oldRoles = $target->getRoleNames();
            $target->removeRole($roleName, $tenantId);
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

    /**
     * Get the actor's highest hierarchy level (lowest number = highest privilege).
     * Uses the actor's highest role across all scopes.
     */
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

    private function guardHierarchy(User $actor, Role $role): void
    {
        $actorLevel = $this->getHighestLevel($actor);
        $roleLevel = self::HIERARCHY[$role->name] ?? null;

        if ($roleLevel === null || $roleLevel <= $actorLevel) {
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Services/RoleManagementServiceTest.php`
Expected: All tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/RoleManagementService.php tests/Feature/Services/RoleManagementServiceTest.php
git commit -m "feat: add RoleManagementService with hierarchy enforcement and audit logging"
```

---

## Chunk 2: Tenant Admin Backend (Controller & Routes)

### Task 4: Create RoleManagementController for tenant admin

**Files:**
- Create: `app/Http/Controllers/Admin/RoleManagementController.php`
- Modify: `routes/admin.php`
- Test: `tests/Feature/Admin/RoleManagementTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/Admin/RoleManagementTest.php`:

```php
<?php

use App\Models\Tenant;
use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->tenant = Tenant::factory()->create();
    app()->instance('current_tenant', $this->tenant);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('tenant_admin', $this->tenant->id);
});

test('list assignable roles returns tenant roles only', function () {
    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/roles');

    $response->assertOk()
        ->assertJsonPath('success', true);

    $names = collect($response->json('data'))->pluck('name')->toArray();
    expect($names)->toContain('tenant_manager', 'player')
        ->and($names)->not->toContain('platform_super_admin', 'platform_admin', 'tenant_admin');
});

test('get user roles returns roles for current tenant', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $user->assignRole('player', $this->tenant->id);

    $response = $this->actingAs($this->admin)
        ->getJson("/api/v1/admin/users/{$user->uuid}/roles");

    $response->assertOk()
        ->assertJsonPath('success', true);

    $names = collect($response->json('data'))->pluck('name')->toArray();
    expect($names)->toContain('player');
});

test('assign role to user', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/users/{$user->uuid}/roles", [
            'role' => 'tenant_manager',
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    expect($user->fresh()->hasRole('tenant_manager', $this->tenant->id))->toBeTrue();
});

test('remove role from user', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $user->assignRole('tenant_manager', $this->tenant->id);

    $response = $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/users/{$user->uuid}/roles/tenant_manager");

    $response->assertOk()
        ->assertJsonPath('success', true);

    expect($user->fresh()->hasRole('tenant_manager', $this->tenant->id))->toBeFalse();
});

test('returns 403 if user lacks manage-roles permission', function () {
    $manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $manager->assignRole('tenant_manager', $this->tenant->id);

    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $response = $this->actingAs($manager)
        ->postJson("/api/v1/admin/users/{$user->uuid}/roles", [
            'role' => 'player',
        ]);

    $response->assertForbidden();
});

test('returns 403 for hierarchy violation', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/users/{$user->uuid}/roles", [
            'role' => 'tenant_admin',
        ]);

    $response->assertForbidden();
});

test('returns 422 for duplicate assignment', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $user->assignRole('player', $this->tenant->id);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/users/{$user->uuid}/roles", [
            'role' => 'player',
        ]);

    $response->assertUnprocessable();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Admin/RoleManagementTest.php`
Expected: All tests FAIL (controller/routes not found).

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Admin/RoleManagementController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RoleManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RoleManagementController extends Controller
{
    public function __construct(
        protected RoleManagementService $roleService
    ) {}

    public function listRoles(Request $request): JsonResponse
    {
        $this->checkPermission($request);

        $tenant = $this->getTenantOrFail();
        $roles = $this->roleService->getAssignableRoles($request->user(), $tenant->id);

        return response()->json([
            'success' => true,
            'data' => $roles->map(fn ($role) => [
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
            ])->values(),
        ]);
    }

    public function getUserRoles(Request $request, string $uuid): JsonResponse
    {
        $this->checkPermission($request);

        $tenant = $this->getTenantOrFail();
        $user = User::where('uuid', $uuid)->firstOrFail();

        $roles = $user->roles()
            ->wherePivot('tenant_id', $tenant->id)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $roles->map(fn ($role) => [
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
            ])->values(),
        ]);
    }

    public function assignRole(Request $request, string $uuid): JsonResponse
    {
        $this->checkPermission($request);

        $request->validate(['role' => 'required|string']);

        $tenant = $this->getTenantOrFail();
        $target = User::where('uuid', $uuid)->firstOrFail();

        try {
            $this->roleService->assignRole($request->user(), $target, $request->input('role'), $tenant->id);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Role assigned successfully.',
        ]);
    }

    public function removeRole(Request $request, string $uuid, string $role): JsonResponse
    {
        $this->checkPermission($request);

        $tenant = $this->getTenantOrFail();
        $target = User::where('uuid', $uuid)->firstOrFail();

        try {
            $this->roleService->removeRole($request->user(), $target, $role, $tenant->id);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Role removed successfully.',
        ]);
    }

    private function checkPermission(Request $request): void
    {
        if (! $request->user()->hasPermission('users.manage-roles')) {
            abort(403, 'You do not have permission to manage roles.');
        }
    }

    private function getTenantOrFail(): \App\Models\Tenant
    {
        $tenant = app('current_tenant');
        if (! $tenant) {
            abort(422, 'Tenant context is required for this operation.');
        }
        return $tenant;
    }
}
```

- [ ] **Step 4: Add routes**

In `routes/admin.php`, add a standalone roles route inside the main `EnsureTenantAdmin` group (after line 33, before the users section):

```php
    // Assignable Roles
    Route::get('roles', [RoleManagementController::class, 'listRoles'])->name('roles.index');
```

Then inside the existing `Route::prefix('users')` group (after line 45, the reset-password route), add:

```php
        Route::get('{uuid}/roles', [RoleManagementController::class, 'getUserRoles'])->name('roles.index');
        Route::post('{uuid}/roles', [RoleManagementController::class, 'assignRole'])->name('roles.store');
        Route::delete('{uuid}/roles/{role}', [RoleManagementController::class, 'removeRole'])->name('roles.destroy');
```

Add the import at the top of the file:
```php
use App\Http\Controllers\Admin\RoleManagementController;
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Admin/RoleManagementTest.php`
Expected: All tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/RoleManagementController.php routes/admin.php tests/Feature/Admin/RoleManagementTest.php
git commit -m "feat: add tenant admin role management endpoints"
```

---

## Chunk 3: Platform Admin Backend (Controller & Routes)

### Task 5: Create PlatformRoleManagementController

**Files:**
- Create: `app/Http/Controllers/Platform/PlatformRoleManagementController.php`
- Modify: `routes/platform.php`
- Test: `tests/Feature/Platform/PlatformRoleManagementTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/Platform/PlatformRoleManagementTest.php`:

```php
<?php

use App\Models\Tenant;
use App\Models\Role;
use App\Models\User;
use App\Models\Scopes\TenantScope;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('platform_super_admin');

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('platform_admin');

    $this->tenant = Tenant::factory()->create();
});

test('list all roles returns platform and tenant roles grouped', function () {
    $response = $this->actingAs($this->superAdmin)
        ->getJson('/api/v1/platform/roles');

    $response->assertOk()
        ->assertJsonPath('success', true);

    $names = collect($response->json('data'))->pluck('name')->toArray();
    expect($names)->toContain('platform_admin', 'tenant_admin', 'tenant_manager', 'player');
});

test('platform_admin cannot see platform_super_admin or platform_admin in assignable roles', function () {
    $response = $this->actingAs($this->platformAdmin)
        ->getJson('/api/v1/platform/roles');

    $response->assertOk();

    $names = collect($response->json('data'))->pluck('name')->toArray();
    expect($names)->not->toContain('platform_super_admin', 'platform_admin');
});

test('list users returns users across all tenants', function () {
    User::factory()->create(['tenant_id' => $this->tenant->id]);
    User::factory()->create(['tenant_id' => null]);

    $response = $this->actingAs($this->superAdmin)
        ->getJson('/api/v1/platform/users');

    $response->assertOk()
        ->assertJsonPath('success', true);

    // Should include users from all tenants plus platform users
    expect($response->json('meta.total'))->toBeGreaterThanOrEqual(4);
});

test('list users supports search filter', function () {
    $user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Unique Test Name',
    ]);

    $response = $this->actingAs($this->superAdmin)
        ->getJson('/api/v1/platform/users?search=Unique+Test');

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->toArray();
    expect($names)->toContain('Unique Test Name');
});

test('list users supports tenant_id filter', function () {
    User::factory()->create(['tenant_id' => $this->tenant->id]);

    $response = $this->actingAs($this->superAdmin)
        ->getJson("/api/v1/platform/users?tenant_id={$this->tenant->id}");

    $response->assertOk();
    foreach ($response->json('data') as $user) {
        expect($user['tenant_id'])->toBe($this->tenant->id);
    }
});

test('get user roles returns all roles across scopes', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $user->assignRole('player', $this->tenant->id);

    $response = $this->actingAs($this->superAdmin)
        ->getJson("/api/v1/platform/users/{$user->uuid}/roles");

    $response->assertOk();
    $roles = $response->json('data');
    expect(collect($roles)->pluck('name')->toArray())->toContain('player');
});

test('assign platform role', function () {
    $target = User::factory()->create();

    $response = $this->actingAs($this->superAdmin)
        ->postJson("/api/v1/platform/users/{$target->uuid}/roles", [
            'role' => 'platform_admin',
        ]);

    $response->assertOk();
    expect($target->fresh()->hasRole('platform_admin'))->toBeTrue();
});

test('assign tenant-scoped role with tenant_id', function () {
    $target = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $response = $this->actingAs($this->superAdmin)
        ->postJson("/api/v1/platform/users/{$target->uuid}/roles", [
            'role' => 'tenant_admin',
            'tenant_id' => $this->tenant->id,
        ]);

    $response->assertOk();
    expect($target->fresh()->hasRole('tenant_admin', $this->tenant->id))->toBeTrue();
});

test('remove platform role', function () {
    $target = User::factory()->create();
    $target->assignRole('platform_admin');

    $response = $this->actingAs($this->superAdmin)
        ->deleteJson("/api/v1/platform/users/{$target->uuid}/roles/platform_admin");

    $response->assertOk();
    expect($target->fresh()->hasRole('platform_admin'))->toBeFalse();
});

test('remove tenant-scoped role with tenant_id query param', function () {
    $target = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $target->assignRole('tenant_admin', $this->tenant->id);

    $response = $this->actingAs($this->superAdmin)
        ->deleteJson("/api/v1/platform/users/{$target->uuid}/roles/tenant_admin?tenant_id={$this->tenant->id}");

    $response->assertOk();
    expect($target->fresh()->hasRole('tenant_admin', $this->tenant->id))->toBeFalse();
});

test('non-platform-admin cannot access platform role endpoints', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/platform/roles');

    $response->assertForbidden();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Platform/PlatformRoleManagementTest.php`
Expected: All tests FAIL.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Platform/PlatformRoleManagementController.php`:

```php
<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RoleManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformRoleManagementController extends Controller
{
    public function __construct(
        protected RoleManagementService $roleService
    ) {}

    public function listRoles(Request $request): JsonResponse
    {
        $this->checkPermission($request);

        $roles = $this->roleService->getAssignableRoles($request->user());

        return response()->json([
            'success' => true,
            'data' => $roles->map(fn ($role) => [
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
                'is_platform_role' => $role->is_platform_role,
            ])->values(),
        ]);
    }

    public function listUsers(Request $request): JsonResponse
    {
        $query = User::withoutGlobalScope(TenantScope::class)->with(['roles', 'tenant']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->input('tenant_id'));
        }

        if ($request->filled('role')) {
            $roleName = $request->input('role');
            $query->whereHas('roles', fn ($q) => $q->where('name', $roleName));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $query->orderBy('created_at', 'desc');

        $users = $query->paginate($request->input('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => collect($users->items())->map(fn (User $user) => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'tenant_id' => $user->tenant_id,
                'tenant_name' => $user->tenant?->name,
                'roles' => $user->roles->map(fn ($role) => [
                    'name' => $role->name,
                    'display_name' => $role->display_name,
                    'tenant_id' => $role->pivot->tenant_id,
                ])->values(),
                'created_at' => $user->created_at,
                'last_login_at' => $user->last_login_at,
            ]),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function getUserRoles(Request $request, string $uuid): JsonResponse
    {
        $this->checkPermission($request);

        $user = User::withoutGlobalScope(TenantScope::class)
            ->where('uuid', $uuid)
            ->firstOrFail();

        $roles = $user->roles;

        return response()->json([
            'success' => true,
            'data' => $roles->map(fn ($role) => [
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
                'is_platform_role' => $role->is_platform_role,
                'tenant_id' => $role->pivot->tenant_id,
            ])->values(),
        ]);
    }

    public function assignRole(Request $request, string $uuid): JsonResponse
    {
        $this->checkPermission($request);

        $request->validate([
            'role' => 'required|string',
            'tenant_id' => 'nullable|integer',
        ]);

        $target = User::withoutGlobalScope(TenantScope::class)
            ->where('uuid', $uuid)
            ->firstOrFail();

        try {
            $this->roleService->assignRole(
                $request->user(),
                $target,
                $request->input('role'),
                $request->input('tenant_id')
            );
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Role assigned successfully.',
        ]);
    }

    public function removeRole(Request $request, string $uuid, string $role): JsonResponse
    {
        $this->checkPermission($request);

        $target = User::withoutGlobalScope(TenantScope::class)
            ->where('uuid', $uuid)
            ->firstOrFail();

        $tenantId = $request->query('tenant_id') ? (int) $request->query('tenant_id') : null;

        try {
            $this->roleService->removeRole($request->user(), $target, $role, $tenantId);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Role removed successfully.',
        ]);
    }

    private function checkPermission(Request $request): void
    {
        if (! $request->user()->hasPermission('users.manage-roles')) {
            abort(403, 'You do not have permission to manage roles.');
        }
    }
}
```

- [ ] **Step 4: Add routes**

In `routes/platform.php`, add the web route (inside the `auth, verified, EnsurePlatformAdmin` group, after line 31):

```php
    Route::get('users', fn () => Inertia::render('platform/users/index'))->name('platform.users');
```

Add the API routes (inside the `auth, EnsurePlatformAdmin` API group, after the revenue routes at line 76):

```php
    // User & Role Management
    Route::get('roles', [PlatformRoleManagementController::class, 'listRoles'])->name('roles.index');
    Route::get('users', [PlatformRoleManagementController::class, 'listUsers'])->name('users.index');
    Route::get('users/{uuid}/roles', [PlatformRoleManagementController::class, 'getUserRoles'])->name('users.roles.index');
    Route::post('users/{uuid}/roles', [PlatformRoleManagementController::class, 'assignRole'])->name('users.roles.store');
    Route::delete('users/{uuid}/roles/{role}', [PlatformRoleManagementController::class, 'removeRole'])->name('users.roles.destroy');
```

Add the import at the top:
```php
use App\Http\Controllers\Platform\PlatformRoleManagementController;
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Platform/PlatformRoleManagementTest.php`
Expected: All tests PASS.

- [ ] **Step 6: Run all tests to check for regressions**

Run: `php artisan test`
Expected: All tests PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Platform/PlatformRoleManagementController.php routes/platform.php tests/Feature/Platform/PlatformRoleManagementTest.php
git commit -m "feat: add platform admin role management endpoints with cross-tenant user listing"
```

---

## Chunk 4: Frontend — Tenant Admin Role Dialog

### Task 6: Add role management dialog to admin users page

**Files:**
- Modify: `resources/js/pages/admin/users.tsx`

- [ ] **Step 1: Add role dialog state and imports**

In `resources/js/pages/admin/users.tsx`, add `Dialog`, `Checkbox`, and `Toast` to the PrimeReact imports, and add `useRef` to the existing React import:

```tsx
import { Checkbox } from 'primereact/checkbox';
import { Dialog } from 'primereact/dialog';
import { Toast } from 'primereact/toast';
```

Update the existing React import from:
```tsx
import { useEffect, useState } from 'react';
```
to:
```tsx
import { useEffect, useRef, useState } from 'react';
```

Add these interfaces after the existing `Stats` interface:

```tsx
interface RoleOption {
    name: string;
    display_name: string;
    description: string;
}
```

- [ ] **Step 2: Add role dialog state variables inside the component**

After the existing `useState` declarations (after line 111), add:

```tsx
    const toast = useRef<Toast>(null);
    const [roleDialogOpen, setRoleDialogOpen] = useState(false);
    const [roleDialogUser, setRoleDialogUser] = useState<User | null>(null);
    const [availableRoles, setAvailableRoles] = useState<RoleOption[]>([]);
    const [selectedRoles, setSelectedRoles] = useState<string[]>([]);
    const [originalRoles, setOriginalRoles] = useState<string[]>([]);
    const [roleLoading, setRoleLoading] = useState(false);
    const [roleSaving, setRoleSaving] = useState(false);
```

- [ ] **Step 3: Add CSRF helper and role dialog functions**

After the state variables, add:

```tsx
    const getCsrfToken = () => {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    };

    const openRoleDialog = async (user: User) => {
        setRoleDialogUser(user);
        setRoleDialogOpen(true);
        setRoleLoading(true);

        try {
            const [rolesRes, userRolesRes] = await Promise.all([
                fetch('/api/v1/admin/roles', { headers: { Accept: 'application/json' } }),
                fetch(`/api/v1/admin/users/${user.uuid}/roles`, { headers: { Accept: 'application/json' } }),
            ]);

            const rolesData = await rolesRes.json();
            const userRolesData = await userRolesRes.json();

            if (rolesData.success) setAvailableRoles(rolesData.data);
            if (userRolesData.success) {
                const names = userRolesData.data.map((r: RoleOption) => r.name);
                setSelectedRoles(names);
                setOriginalRoles(names);
            }
        } catch (error) {
            console.error('Failed to fetch roles:', error);
        } finally {
            setRoleLoading(false);
        }
    };

    const saveRoles = async () => {
        if (!roleDialogUser) return;
        setRoleSaving(true);

        try {
            const toAdd = selectedRoles.filter((r) => !originalRoles.includes(r));
            const toRemove = originalRoles.filter((r) => !selectedRoles.includes(r));

            const headers = {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            };

            for (const role of toAdd) {
                const res = await fetch(`/api/v1/admin/users/${roleDialogUser.uuid}/roles`, {
                    method: 'POST',
                    headers,
                    body: JSON.stringify({ role }),
                });
                if (!res.ok) {
                    const data = await res.json();
                    toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || `Failed to assign ${role}` });
                    return;
                }
            }

            for (const role of toRemove) {
                const res = await fetch(`/api/v1/admin/users/${roleDialogUser.uuid}/roles/${role}`, {
                    method: 'DELETE',
                    headers,
                });
                if (!res.ok) {
                    const data = await res.json();
                    toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || `Failed to remove ${role}` });
                    return;
                }
            }

            toast.current?.show({ severity: 'success', summary: 'Success', detail: 'Roles updated successfully.' });
            setRoleDialogOpen(false);
            fetchUsers();
        } catch (error) {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to update roles.' });
        } finally {
            setRoleSaving(false);
        }
    };
```

- [ ] **Step 4: Update the actions column template**

Replace the existing `actionsTemplate` function:

```tsx
    const actionsTemplate = (row: User) => (
        <div className="flex gap-1">
            <Button
                icon="pi pi-shield"
                text
                severity="secondary"
                size="small"
                tooltip="Manage roles"
                onClick={() => openRoleDialog(row)}
            />
            <Button
                icon="pi pi-eye"
                text
                severity="secondary"
                size="small"
                tooltip="View user"
            />
        </div>
    );
```

- [ ] **Step 5: Add the Toast and Dialog to the JSX**

Inside the return, add `<Toast ref={toast} />` right after `<Head title="User Management" />`, and add the role dialog before the closing `</UserLayout>`:

```tsx
            <Toast ref={toast} />
```

And before `</UserLayout>`:

```tsx
            <Dialog
                header={`Manage Roles — ${roleDialogUser?.name || ''}`}
                visible={roleDialogOpen}
                style={{ width: '28rem' }}
                onHide={() => setRoleDialogOpen(false)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button
                            label="Cancel"
                            icon="pi pi-times"
                            severity="secondary"
                            outlined
                            onClick={() => setRoleDialogOpen(false)}
                        />
                        <Button
                            label={roleSaving ? 'Saving...' : 'Save'}
                            icon="pi pi-check"
                            onClick={saveRoles}
                            disabled={roleSaving || roleLoading}
                            loading={roleSaving}
                        />
                    </div>
                }
            >
                {roleLoading ? (
                    <div className="flex justify-center py-6">
                        <i className="pi pi-spin pi-spinner text-2xl" />
                    </div>
                ) : (
                    <div className="space-y-3">
                        {availableRoles.length === 0 ? (
                            <p className="text-sm text-[var(--acu-text-muted)]">No roles available to assign.</p>
                        ) : (
                            availableRoles.map((role) => (
                                <div key={role.name} className="flex items-start gap-3 p-2 rounded hover:bg-[var(--acu-surface-hover)]">
                                    <Checkbox
                                        inputId={role.name}
                                        checked={selectedRoles.includes(role.name)}
                                        onChange={(e) => {
                                            if (e.checked) {
                                                setSelectedRoles([...selectedRoles, role.name]);
                                            } else {
                                                setSelectedRoles(selectedRoles.filter((r) => r !== role.name));
                                            }
                                        }}
                                    />
                                    <label htmlFor={role.name} className="cursor-pointer">
                                        <div className="text-sm font-medium text-[var(--acu-text)]">{role.display_name}</div>
                                        <div className="text-xs text-[var(--acu-text-light)]">{role.description}</div>
                                    </label>
                                </div>
                            ))
                        )}
                    </div>
                )}
            </Dialog>
```

- [ ] **Step 6: Verify the page renders**

Run: `php artisan serve` (if not running) and visit `/admin/users`. Verify the shield icon appears in the actions column and clicking it opens the role dialog.

- [ ] **Step 7: Commit**

```bash
git add resources/js/pages/admin/users.tsx
git commit -m "feat: add role management dialog to tenant admin users page"
```

---

## Chunk 5: Frontend — Platform Admin Users Page

### Task 7: Create platform users page with role management dialog

**Files:**
- Create: `resources/js/pages/platform/users/index.tsx`
- Modify: `resources/js/layouts/user-layout.tsx`

- [ ] **Step 1: Add "Users" to platform navigation**

In `resources/js/layouts/user-layout.tsx`, add the Users item to the `platformGroup` items array after the Platform item (line 42) and before Tenants (line 43):

```tsx
        { label: 'Users', icon: 'pi pi-users', href: '/platform/users' },
```

So the items array becomes:
```tsx
    items: [
        { label: 'Platform', icon: 'pi pi-globe', href: '/platform' },
        { label: 'Users', icon: 'pi pi-users', href: '/platform/users' },
        { label: 'Tenants', icon: 'pi pi-building', href: '/platform/tenants' },
        { label: 'Games', icon: 'pi pi-play', href: '/platform/games' },
        { label: 'Revenue', icon: 'pi pi-dollar', href: '/platform/revenue' },
    ],
```

- [ ] **Step 2: Create the platform users page**

Create `resources/js/pages/platform/users/index.tsx`:

```tsx
import PageHeader from '@/components/acumatica/Common/PageHeader';
import StatusBadge from '@/components/acumatica/Common/StatusBadge';
import UserLayout from '@/layouts/user-layout';
import type { StatusVariant } from '@/types/acumatica';
import { Head } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Checkbox } from 'primereact/checkbox';
import { Chip } from 'primereact/chip';
import { Column } from 'primereact/column';
import { DataTable } from 'primereact/datatable';
import { Dialog } from 'primereact/dialog';
import { Dropdown } from 'primereact/dropdown';
import { InputText } from 'primereact/inputtext';
import { Toast } from 'primereact/toast';
import { useEffect, useRef, useState } from 'react';

interface UserRole {
    name: string;
    display_name: string;
    tenant_id: number | null;
}

interface PlatformUser {
    uuid: string;
    name: string;
    email: string;
    status: string;
    tenant_id: number | null;
    tenant_name: string | null;
    roles: UserRole[];
    created_at: string;
    last_login_at: string | null;
}

interface RoleOption {
    name: string;
    display_name: string;
    description: string;
    is_platform_role: boolean;
}

interface AssignedRole {
    name: string;
    display_name: string;
    description: string;
    is_platform_role: boolean;
    tenant_id: number | null;
}

interface TenantOption {
    label: string;
    value: number;
}

interface Meta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

function mapUserStatusToVariant(status: string): StatusVariant {
    const map: Record<string, StatusVariant> = {
        active: 'active',
        suspended: 'suspended',
        banned: 'error',
        self_excluded: 'inactive',
    };
    return map[status] || 'inactive';
}

const statusOptions = [
    { label: 'All Status', value: '' },
    { label: 'Active', value: 'active' },
    { label: 'Suspended', value: 'suspended' },
    { label: 'Banned', value: 'banned' },
];

export default function PlatformUsersIndex() {
    const toast = useRef<Toast>(null);
    const [users, setUsers] = useState<PlatformUser[]>([]);
    const [meta, setMeta] = useState<Meta | null>(null);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [tenantFilter, setTenantFilter] = useState<number | ''>('');
    const [page, setPage] = useState(1);
    const [tenants, setTenants] = useState<TenantOption[]>([]);

    // Role dialog state
    const [roleDialogOpen, setRoleDialogOpen] = useState(false);
    const [roleDialogUser, setRoleDialogUser] = useState<PlatformUser | null>(null);
    const [availableRoles, setAvailableRoles] = useState<RoleOption[]>([]);
    const [assignedRoles, setAssignedRoles] = useState<AssignedRole[]>([]);
    const [roleLoading, setRoleLoading] = useState(false);
    const [roleSaving, setRoleSaving] = useState(false);

    // Platform role selections (checkboxes)
    const [selectedPlatformRoles, setSelectedPlatformRoles] = useState<string[]>([]);
    const [originalPlatformRoles, setOriginalPlatformRoles] = useState<string[]>([]);

    // Tenant role assignment
    const [selectedTenantForRole, setSelectedTenantForRole] = useState<number | null>(null);
    const [selectedTenantRoles, setSelectedTenantRoles] = useState<string[]>([]);
    // Tracks existing tenant role assignments as { role, tenant_id } to remove
    const [tenantRoleAssignments, setTenantRoleAssignments] = useState<{ role: string; display_name: string; tenant_id: number; tenant_name: string }[]>([]);

    const getCsrfToken = () => {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    };

    const fetchUsers = async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams();
            if (search) params.append('search', search);
            if (statusFilter) params.append('status', statusFilter);
            if (tenantFilter !== '') params.append('tenant_id', tenantFilter.toString());
            params.append('page', page.toString());

            const response = await fetch(`/api/v1/platform/users?${params}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            if (data.success) {
                setUsers(data.data);
                setMeta(data.meta);
            }
        } catch (error) {
            console.error('Failed to fetch users:', error);
        } finally {
            setLoading(false);
        }
    };

    const fetchTenants = async () => {
        try {
            const response = await fetch('/api/v1/platform/tenants?per_page=100', {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            if (data.data) {
                setTenants(data.data.map((t: { id: number; name: string }) => ({
                    label: t.name,
                    value: t.id,
                })));
            }
        } catch (error) {
            console.error('Failed to fetch tenants:', error);
        }
    };

    useEffect(() => {
        fetchUsers();
    }, [page, statusFilter, tenantFilter]);

    useEffect(() => {
        fetchTenants();
    }, []);

    const handleSearch = () => {
        setPage(1);
        fetchUsers();
    };

    const openRoleDialog = async (user: PlatformUser) => {
        setRoleDialogUser(user);
        setRoleDialogOpen(true);
        setRoleLoading(true);
        setSelectedTenantForRole(null);
        setSelectedTenantRoles([]);

        try {
            const [rolesRes, userRolesRes] = await Promise.all([
                fetch('/api/v1/platform/roles', { headers: { Accept: 'application/json' } }),
                fetch(`/api/v1/platform/users/${user.uuid}/roles`, { headers: { Accept: 'application/json' } }),
            ]);

            const rolesData = await rolesRes.json();
            const userRolesData = await userRolesRes.json();

            if (rolesData.success) setAvailableRoles(rolesData.data);
            if (userRolesData.success) {
                setAssignedRoles(userRolesData.data);

                const platformRoleNames = userRolesData.data
                    .filter((r: AssignedRole) => r.is_platform_role)
                    .map((r: AssignedRole) => r.name);
                setSelectedPlatformRoles(platformRoleNames);
                setOriginalPlatformRoles(platformRoleNames);

                const tenantAssignments = userRolesData.data
                    .filter((r: AssignedRole) => !r.is_platform_role && r.tenant_id !== null)
                    .map((r: AssignedRole) => {
                        const tenantOpt = tenants.find((t) => t.value === r.tenant_id);
                        return {
                            role: r.name,
                            display_name: r.display_name,
                            tenant_id: r.tenant_id!,
                            tenant_name: tenantOpt?.label || `Tenant #${r.tenant_id}`,
                        };
                    });
                setTenantRoleAssignments(tenantAssignments);
            }
        } catch (error) {
            console.error('Failed to fetch roles:', error);
        } finally {
            setRoleLoading(false);
        }
    };

    const saveRoles = async () => {
        if (!roleDialogUser) return;
        setRoleSaving(true);

        const headers = {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': getCsrfToken(),
        };

        try {
            // Handle platform role changes
            const platformToAdd = selectedPlatformRoles.filter((r) => !originalPlatformRoles.includes(r));
            const platformToRemove = originalPlatformRoles.filter((r) => !selectedPlatformRoles.includes(r));

            for (const role of platformToAdd) {
                const res = await fetch(`/api/v1/platform/users/${roleDialogUser.uuid}/roles`, {
                    method: 'POST',
                    headers,
                    body: JSON.stringify({ role }),
                });
                if (!res.ok) {
                    const data = await res.json();
                    toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || `Failed to assign ${role}` });
                    return;
                }
            }

            for (const role of platformToRemove) {
                const res = await fetch(`/api/v1/platform/users/${roleDialogUser.uuid}/roles/${role}`, {
                    method: 'DELETE',
                    headers,
                });
                if (!res.ok) {
                    const data = await res.json();
                    toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || `Failed to remove ${role}` });
                    return;
                }
            }

            // Handle new tenant role assignments
            if (selectedTenantForRole && selectedTenantRoles.length > 0) {
                for (const role of selectedTenantRoles) {
                    const res = await fetch(`/api/v1/platform/users/${roleDialogUser.uuid}/roles`, {
                        method: 'POST',
                        headers,
                        body: JSON.stringify({ role, tenant_id: selectedTenantForRole }),
                    });
                    if (!res.ok) {
                        const data = await res.json();
                        toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || `Failed to assign ${role}` });
                        return;
                    }
                }
            }

            toast.current?.show({ severity: 'success', summary: 'Success', detail: 'Roles updated successfully.' });
            setRoleDialogOpen(false);
            fetchUsers();
        } catch (error) {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to update roles.' });
        } finally {
            setRoleSaving(false);
        }
    };

    const removeTenantRole = async (role: string, tenantId: number) => {
        if (!roleDialogUser) return;
        setRoleSaving(true);

        try {
            const res = await fetch(
                `/api/v1/platform/users/${roleDialogUser.uuid}/roles/${role}?tenant_id=${tenantId}`,
                {
                    method: 'DELETE',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                }
            );

            if (res.ok) {
                setTenantRoleAssignments((prev) =>
                    prev.filter((a) => !(a.role === role && a.tenant_id === tenantId))
                );
                toast.current?.show({ severity: 'success', summary: 'Success', detail: 'Role removed.' });
                fetchUsers();
            } else {
                const data = await res.json();
                toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || 'Failed to remove role.' });
            }
        } catch (error) {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to remove role.' });
        } finally {
            setRoleSaving(false);
        }
    };

    const platformRoles = availableRoles.filter((r) => r.is_platform_role);
    const tenantRoles = availableRoles.filter((r) => !r.is_platform_role);

    const userTemplate = (row: PlatformUser) => (
        <div>
            <div className="font-medium text-sm text-[var(--acu-text)]">{row.name}</div>
            <div className="text-xs text-[var(--acu-text-light)]">{row.email}</div>
        </div>
    );

    const tenantTemplate = (row: PlatformUser) => (
        <span className="text-sm text-[var(--acu-text)]">
            {row.tenant_name || 'Platform'}
        </span>
    );

    const rolesTemplate = (row: PlatformUser) => (
        <div className="flex flex-wrap gap-1">
            {row.roles.map((r, i) => (
                <span key={i} className="text-xs px-2 py-0.5 rounded bg-[var(--acu-surface-hover)] text-[var(--acu-text)]">
                    {r.display_name}
                </span>
            ))}
        </div>
    );

    const statusTemplate = (row: PlatformUser) => (
        <StatusBadge status={mapUserStatusToVariant(row.status)} label={row.status} />
    );

    const actionsTemplate = (row: PlatformUser) => (
        <Button
            icon="pi pi-shield"
            text
            severity="secondary"
            size="small"
            tooltip="Manage roles"
            onClick={() => openRoleDialog(row)}
        />
    );

    const tenantFilterOptions = [
        { label: 'All Tenants', value: '' },
        ...tenants,
    ];

    return (
        <UserLayout title="Platform Users">
            <Head title="Platform Users" />
            <Toast ref={toast} />

            <div className="space-y-6">
                <PageHeader title="Users" subtitle="Manage users across all tenants">
                    <Button
                        label="Refresh"
                        icon="pi pi-refresh"
                        outlined
                        size="small"
                        onClick={fetchUsers}
                    />
                </PageHeader>

                {/* Filters */}
                <div className="acu-fieldset">
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-filter" />
                            <span>Filters</span>
                        </div>
                    </div>
                    <div className="acu-fieldset-body">
                        <div className="flex flex-wrap gap-3 items-end">
                            <div className="flex flex-1 gap-2">
                                <span className="p-input-icon-left flex-1" style={{ maxWidth: '24rem' }}>
                                    <i className="pi pi-search" />
                                    <InputText
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                                        placeholder="Search by name, email..."
                                        className="w-full"
                                    />
                                </span>
                                <Button
                                    label="Search"
                                    icon="pi pi-search"
                                    size="small"
                                    onClick={handleSearch}
                                />
                            </div>
                            <Dropdown
                                value={tenantFilter}
                                onChange={(e) => { setTenantFilter(e.value); setPage(1); }}
                                options={tenantFilterOptions}
                                placeholder="Tenant"
                                className="w-48"
                            />
                            <Dropdown
                                value={statusFilter}
                                onChange={(e) => { setStatusFilter(e.value); setPage(1); }}
                                options={statusOptions}
                                placeholder="Status"
                                className="w-40"
                            />
                        </div>
                    </div>
                </div>

                {/* Users Table */}
                <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-blue)' } as React.CSSProperties}>
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-users" />
                            <span>Users</span>
                            <span className="text-xs font-normal text-[var(--acu-text-light)] ml-1">
                                {meta?.total ? `(${users.length} of ${meta.total})` : ''}
                            </span>
                        </div>
                    </div>
                    <div className="acu-fieldset-body p-0">
                        <DataTable
                            value={users}
                            loading={loading}
                            size="small"
                            showGridlines={false}
                            emptyMessage="No users found"
                        >
                            <Column header="User" body={userTemplate} />
                            <Column header="Tenant" body={tenantTemplate} />
                            <Column header="Roles" body={rolesTemplate} />
                            <Column header="Status" body={statusTemplate} />
                            <Column header="" body={actionsTemplate} style={{ width: '4rem' }} />
                        </DataTable>

                        {meta && meta.last_page > 1 && (
                            <div className="flex items-center justify-between px-4 py-3 border-t border-[var(--acu-border)]">
                                <span className="text-xs text-[var(--acu-text-light)]">
                                    Page {meta.current_page} of {meta.last_page}
                                </span>
                                <div className="flex gap-2">
                                    <Button
                                        label="Previous"
                                        icon="pi pi-angle-left"
                                        outlined
                                        size="small"
                                        disabled={meta.current_page === 1}
                                        onClick={() => setPage((p) => Math.max(1, p - 1))}
                                    />
                                    <Button
                                        label="Next"
                                        icon="pi pi-angle-right"
                                        iconPos="right"
                                        outlined
                                        size="small"
                                        disabled={meta.current_page === meta.last_page}
                                        onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
                                    />
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* Role Management Dialog */}
            <Dialog
                header={`Manage Roles — ${roleDialogUser?.name || ''}`}
                visible={roleDialogOpen}
                style={{ width: '36rem' }}
                onHide={() => setRoleDialogOpen(false)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button
                            label="Cancel"
                            icon="pi pi-times"
                            severity="secondary"
                            outlined
                            onClick={() => setRoleDialogOpen(false)}
                        />
                        <Button
                            label={roleSaving ? 'Saving...' : 'Save'}
                            icon="pi pi-check"
                            onClick={saveRoles}
                            disabled={roleSaving || roleLoading}
                            loading={roleSaving}
                        />
                    </div>
                }
            >
                {roleLoading ? (
                    <div className="flex justify-center py-6">
                        <i className="pi pi-spin pi-spinner text-2xl" />
                    </div>
                ) : (
                    <div className="space-y-5">
                        {/* Platform Roles */}
                        {platformRoles.length > 0 && (
                            <div>
                                <h4 className="text-sm font-semibold text-[var(--acu-text)] mb-2">Platform Roles</h4>
                                <div className="space-y-2">
                                    {platformRoles.map((role) => (
                                        <div key={role.name} className="flex items-start gap-3 p-2 rounded hover:bg-[var(--acu-surface-hover)]">
                                            <Checkbox
                                                inputId={`platform-${role.name}`}
                                                checked={selectedPlatformRoles.includes(role.name)}
                                                onChange={(e) => {
                                                    if (e.checked) {
                                                        setSelectedPlatformRoles([...selectedPlatformRoles, role.name]);
                                                    } else {
                                                        setSelectedPlatformRoles(selectedPlatformRoles.filter((r) => r !== role.name));
                                                    }
                                                }}
                                            />
                                            <label htmlFor={`platform-${role.name}`} className="cursor-pointer">
                                                <div className="text-sm font-medium text-[var(--acu-text)]">{role.display_name}</div>
                                                <div className="text-xs text-[var(--acu-text-light)]">{role.description}</div>
                                            </label>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Tenant Roles */}
                        <div>
                            <h4 className="text-sm font-semibold text-[var(--acu-text)] mb-2">Tenant Roles</h4>

                            {/* Existing tenant role assignments */}
                            {tenantRoleAssignments.length > 0 && (
                                <div className="flex flex-wrap gap-2 mb-3">
                                    {tenantRoleAssignments.map((a, i) => (
                                        <Chip
                                            key={i}
                                            label={`${a.display_name} @ ${a.tenant_name}`}
                                            removable
                                            onRemove={() => removeTenantRole(a.role, a.tenant_id)}
                                        />
                                    ))}
                                </div>
                            )}

                            {/* Add new tenant role */}
                            <div className="space-y-2">
                                <Dropdown
                                    value={selectedTenantForRole}
                                    onChange={(e) => { setSelectedTenantForRole(e.value); setSelectedTenantRoles([]); }}
                                    options={tenants}
                                    placeholder="Select tenant..."
                                    className="w-full"
                                />
                                {selectedTenantForRole && (
                                    <div className="space-y-2 pl-2">
                                        {tenantRoles.map((role) => (
                                            <div key={role.name} className="flex items-start gap-3 p-2 rounded hover:bg-[var(--acu-surface-hover)]">
                                                <Checkbox
                                                    inputId={`tenant-${role.name}`}
                                                    checked={selectedTenantRoles.includes(role.name)}
                                                    onChange={(e) => {
                                                        if (e.checked) {
                                                            setSelectedTenantRoles([...selectedTenantRoles, role.name]);
                                                        } else {
                                                            setSelectedTenantRoles(selectedTenantRoles.filter((r) => r !== role.name));
                                                        }
                                                    }}
                                                />
                                                <label htmlFor={`tenant-${role.name}`} className="cursor-pointer">
                                                    <div className="text-sm font-medium text-[var(--acu-text)]">{role.display_name}</div>
                                                    <div className="text-xs text-[var(--acu-text-light)]">{role.description}</div>
                                                </label>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                )}
            </Dialog>
        </UserLayout>
    );
}
```

- [ ] **Step 3: Verify the page renders**

Run: Visit `/platform/users` in the browser. Verify:
- The page loads with the users list
- Filter dropdowns work (tenant, status, search)
- Shield icon appears in the actions column
- Clicking shield opens role dialog with platform roles and tenant roles sections
- Existing role assignments show as removable chips

- [ ] **Step 4: Commit**

```bash
git add resources/js/pages/platform/users/index.tsx resources/js/layouts/user-layout.tsx
git commit -m "feat: add platform admin users page with role management dialog"
```

---

## Chunk 6: Final Verification

### Task 8: Run full test suite and build

- [ ] **Step 1: Run all backend tests**

Run: `php artisan test`
Expected: All tests PASS.

- [ ] **Step 2: Run frontend build**

Run: `npm run build`
Expected: Build succeeds with no TypeScript errors.

---

### Task 9: Manual verification checklist

- [ ] **Step 1: Verify tenant admin flow**
  - Log in as a `tenant_admin` user
  - Navigate to `/admin/users`
  - Click the shield icon on a user
  - Verify only `tenant_manager` and `player` are shown (not `tenant_admin` or platform roles)
  - Assign `tenant_manager` to a user, verify it persists
  - Remove the role, verify it's gone

- [ ] **Step 2: Verify platform admin flow**
  - Log in as a `platform_super_admin` user
  - Verify "Users" appears in the Platform sidebar group
  - Navigate to `/platform/users`
  - Verify users from all tenants are listed
  - Click the shield icon on a user
  - Verify platform roles section shows `platform_admin`
  - Verify tenant roles section has a tenant dropdown
  - Assign `platform_admin` to a user, verify it persists
  - Assign `tenant_admin` to a user for a specific tenant, verify it shows as a chip
  - Remove a tenant role chip, verify it's removed

- [ ] **Step 3: Verify hierarchy enforcement**
  - As `platform_admin`, verify `platform_super_admin` is not in the assignable roles list
  - As `tenant_admin`, verify `tenant_admin` is not assignable
  - Verify self-assignment is blocked (if you click shield on your own row)

- [ ] **Step 4: Final commit if any adjustments made**

```bash
git add -A
git commit -m "fix: adjustments from manual verification"
```
