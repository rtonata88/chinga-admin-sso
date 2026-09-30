<?php

use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/** The Teams page manages the v2 engine's pool: reads with gaming:read, writes with gaming:write, deactivate instead of delete. */
beforeEach(function () {
    $this->withoutVite();
    $this->seed(\Database\Seeders\RbacSeeder::class);
    config([
        'app.url' => 'http://sso.test',
        'services.sso_internal.client_id' => 'internal-id',
        'services.sso_internal.client_secret' => 'internal-secret',
    ]);
    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('platform_admin');
    Game::factory()->fantasy()->create(['backend_url' => 'http://fantasy.test']);
});

test('lists the pool from the engine with search and active filters, paged', function () {
    $teams = [];
    for ($i = 1; $i <= 30; $i++) {
        $teams[] = ['id' => $i, 'name' => "Town {$i} Testers", 'shortName' => "T{$i}", 'colour' => '#112233', 'active' => $i % 3 !== 0];
    }
    Http::fake([
        'sso.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        'fantasy.test/api/admin/teams' => Http::response(['data' => $teams]),
    ]);

    $this->actingAs($this->platformAdmin)->get('/fantasy/teams')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('fantasy/teams')->has('teams.data', 25)->where('teams.total', 30)->where('teams.last_page', 2)->where('error', null));
    $this->actingAs($this->platformAdmin)->get('/fantasy/teams?active=false')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('teams.total', 10)->where('teams.data.0.active', false));
    $this->actingAs($this->platformAdmin)->get('/fantasy/teams?search=town 7')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('teams.total', 1)->where('teams.data.0.short_name', 'T7')->where('teams.data.0.colour', '#112233'));
});

test('creates, updates and deactivates through the engine with a gaming:write token', function () {
    Http::fake([
        'sso.test/oauth/token' => Http::response(['access_token' => 'write-tok', 'expires_in' => 600]),
        'fantasy.test/api/admin/teams' => Http::response(['id' => 61, 'name' => 'Karibib Kites'], 201),
        'fantasy.test/api/admin/teams/61' => Http::response(['id' => 61, 'name' => 'Karibib Kites', 'active' => false]),
    ]);

    $this->actingAs($this->platformAdmin)
        ->post('/fantasy/teams', ['name' => 'Karibib Kites', 'short_name' => 'karki', 'colour' => '#00ff88', 'active' => true, 'position' => 7])
        ->assertRedirect()->assertSessionHas('success', 'Team created.');
    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/admin/teams')
        && $r->hasHeader('Authorization', 'Bearer write-tok')
        && $r->data() === ['name' => 'Karibib Kites', 'short_name' => 'KARKI', 'colour' => '#00FF88', 'active' => true, 'position' => 7]);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/oauth/token') && $r->data()['scope'] === 'gaming:write');

    $this->actingAs($this->platformAdmin)
        ->put('/fantasy/teams/61', ['name' => 'Karibib Kites', 'short_name' => 'KARKI', 'colour' => '#00FF88', 'active' => false])
        ->assertRedirect()->assertSessionHas('success', 'Team updated.');
    Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/api/admin/teams/61'));

    $this->actingAs($this->platformAdmin)->delete('/fantasy/teams/61')->assertRedirect()->assertSessionHas('success');
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/api/admin/teams/61'));

    // Validation stays on the SSO side: a bad colour never reaches the engine.
    $this->actingAs($this->platformAdmin)
        ->post('/fantasy/teams', ['name' => 'X', 'short_name' => 'X', 'colour' => 'red'])
        ->assertSessionHasErrors(['colour']);
});

test('an unreachable engine shows an error instead of a blank page or a 500', function () {
    Http::fake([
        'sso.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        'fantasy.test/*' => Http::response('down', 503),
    ]);
    $this->actingAs($this->platformAdmin)->get('/fantasy/teams')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('teams.data', 0)->where('error', 'Could not load the team pool. Is the Chinga Fantasy v2 engine reachable?'));
    $this->actingAs($this->platformAdmin)
        ->post('/fantasy/teams', ['name' => 'X', 'short_name' => 'XX', 'colour' => '#000000'])
        ->assertRedirect()->assertSessionHas('error');
});
