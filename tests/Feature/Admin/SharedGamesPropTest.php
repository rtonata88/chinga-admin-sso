<?php

use App\Models\Game;
use App\Models\Tenant;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('platform_admin');
    $this->tenant = Tenant::factory()->create();
    $this->tenantAdmin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenantAdmin->assignRole('tenant_admin', $this->tenant->id);

    // No backend URLs: nav only needs the catalogue, and the pages under
    // test must not try to reach an engine.
    $this->fantasy = Game::factory()->fantasy()->create(['launch_url' => 'https://play.fantasy.test']);
    $this->crash = Game::factory()->development()->create(['name' => 'Vrrr Pha', 'slug' => 'vrrr-pha']);
    $this->inactive = Game::factory()->create(['name' => 'Retired', 'status' => 'inactive']);
    $this->fantasy->tenants()->attach($this->tenant->id, ['enabled' => true]);
    $this->crash->tenants()->attach($this->tenant->id, ['enabled' => true]);
});

test('platform admins get every active and development game in the shared props', function () {
    $this->actingAs($this->platformAdmin)
        ->get('/tenant-overview')
        ->assertInertia(fn (Assert $page) => $page
            ->has('games', 2)
            ->where('games.0.slug', 'chinga-fantasy')
            ->where('games.0.name', 'Chinga Fantasy')
            ->where('games.0.uuid', $this->fantasy->uuid)
            ->where('games.0.launch_url', 'https://play.fantasy.test')
            ->where('games.1.slug', 'vrrr-pha')
            ->where('games.1.status', 'development')
        );
});

test('tenant admins get only the active games enabled for their tenant', function () {
    $this->actingAs($this->tenantAdmin)
        ->get('/tenant-overview')
        ->assertInertia(fn (Assert $page) => $page
            ->has('games', 1)
            ->where('games.0.slug', 'chinga-fantasy')
        );
});

test('players get an empty games list', function () {
    $player = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->actingAs($player)
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('games', []));
});
