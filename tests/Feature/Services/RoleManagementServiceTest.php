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

    $this->service->removeRole($actor, $target, 'platform_super_admin');
    expect($target->hasRole('platform_super_admin'))->toBeFalse();
});

test('last platform_super_admin is protected by self-assignment and hierarchy checks', function () {
    $sole = User::factory()->create();
    $sole->assignRole('platform_super_admin');
    $other = User::factory()->create();
    $other->assignRole('platform_admin');

    expect(fn () => $this->service->removeRole($sole, $sole, 'platform_super_admin'))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $this->service->removeRole($other, $sole, 'platform_super_admin'))
        ->toThrow(AuthorizationException::class);
});

test('removeRole throws ValidationException if user does not have the role', function () {
    $actor = User::factory()->create();
    $actor->assignRole('platform_super_admin');
    $target = User::factory()->create();

    $this->service->removeRole($actor, $target, 'platform_admin');
})->throws(ValidationException::class);
