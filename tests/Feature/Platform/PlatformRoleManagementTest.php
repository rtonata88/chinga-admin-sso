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
