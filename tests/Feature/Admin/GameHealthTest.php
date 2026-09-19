<?php

use App\Models\Game;
use App\Models\Tenant;
use App\Models\User;
use App\Support\GameCatalogue;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('platform_admin');
    $this->tenant = Tenant::factory()->create();
    $this->tenantAdmin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenantAdmin->assignRole('tenant_admin', $this->tenant->id);

    $this->up = Game::factory()->create(['name' => 'Up Game', 'backend_url' => 'http://up.test']);
    $this->down = Game::factory()->development()->create(['name' => 'Down Game', 'backend_url' => 'http://down.test']);
    $this->noBackend = Game::factory()->create(['name' => 'No Backend', 'backend_url' => null]);
    $this->inactive = Game::factory()->create(['name' => 'Inactive', 'status' => 'inactive', 'backend_url' => 'http://inactive.test']);

    Http::fake([
        'up.test/api/health' => Http::response(['status' => 'ok', 'db' => 'ok']),
        'down.test/api/health' => Http::response(['status' => 'down', 'db' => 'error'], 503),
        'inactive.test/*' => Http::response(['status' => 'ok']),
    ]);
});

test('platform admin gets one health entry per active or development game with a backend', function () {
    $response = $this->actingAs($this->platformAdmin)->getJson('/api/v1/admin/games-health')->assertOk();

    $byName = collect($response->json('data'))->keyBy('name');
    expect($byName->keys()->sort()->values()->all())->toBe(['Down Game', 'Up Game'])
        ->and($byName['Up Game']['status'])->toBe('ok')
        ->and($byName['Up Game']['game_uuid'])->toBe($this->up->uuid)
        ->and($byName['Down Game']['status'])->toBe('down')
        ->and($byName['Down Game']['message'])->toContain('503');

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'inactive.test'));
});

test('tenant admin only sees games enabled for their tenant', function () {
    $this->up->tenants()->attach($this->tenant->id, ['enabled' => true]);
    $this->down->update(['status' => 'active']);
    $this->down->tenants()->attach($this->tenant->id, ['enabled' => false]);

    $response = $this->actingAs($this->tenantAdmin)->getJson('/api/v1/admin/games-health')->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Up Game']);
});

test('a player is refused', function () {
    $player = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->actingAs($player)->getJson('/api/v1/admin/games-health')->assertForbidden();
});

test('the legacy fantasy-health endpoint still answers', function () {
    Game::factory()->fantasy()->create(['backend_url' => 'http://up.test']);

    $this->actingAs($this->platformAdmin)->getJson('/api/v1/admin/fantasy-health')
        ->assertOk()
        ->assertJsonPath('data.status', 'ok');
});

test('GameCatalogue::forUser distinguishes platform admins, tenant admins and players', function () {
    $this->up->tenants()->attach($this->tenant->id, ['enabled' => true]);
    $this->noBackend->tenants()->attach($this->tenant->id, ['enabled' => true]);
    $this->down->tenants()->attach($this->tenant->id, ['enabled' => true]); // development: hidden from tenants

    expect(GameCatalogue::forUser($this->platformAdmin)->pluck('name')->sort()->values()->all())
        ->toBe(['Down Game', 'No Backend', 'Up Game']);
    expect(GameCatalogue::forUser($this->tenantAdmin, $this->tenant)->pluck('name')->sort()->values()->all())
        ->toBe(['No Backend', 'Up Game']);
    expect(GameCatalogue::forUser(User::factory()->create(['tenant_id' => $this->tenant->id]))->all())->toBe([]);
});
