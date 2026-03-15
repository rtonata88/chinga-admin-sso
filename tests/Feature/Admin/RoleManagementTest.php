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
