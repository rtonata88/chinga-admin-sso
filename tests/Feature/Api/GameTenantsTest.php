<?php

use App\Models\Game;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\VrrrPhaGameSeeder;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->seed(VrrrPhaGameSeeder::class);
    $this->game = Game::where('slug', 'vrrr-pha')->firstOrFail();
    $this->client = Client::where('name', 'Vrrr Pha Engine')->firstOrFail();
    $this->client->forceFill(['secret' => 'engine-secret'])->save();

    $this->enabled = Tenant::factory()->create(['slug' => 'alpha', 'country_code' => 'NA', 'currency' => 'NAD']);
    $this->disabled = Tenant::factory()->create(['slug' => 'bravo']);
    $this->suspended = Tenant::factory()->create(['slug' => 'charlie', 'status' => 'suspended']);
    $this->unrelated = Tenant::factory()->create(['slug' => 'delta']);
    $this->game->tenants()->attach($this->enabled->id, ['enabled' => true]);
    $this->game->tenants()->attach($this->disabled->id, ['enabled' => false]);
    $this->game->tenants()->attach($this->suspended->id, ['enabled' => true]);
});

function engineToken(Client $client, string $scope = 'gaming:read'): string
{
    return test()->postJson('/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->id,
        'client_secret' => 'engine-secret',
        'scope' => $scope,
    ])->assertOk()->json('access_token');
}

test('a bound engine client lists only the active tenants with the game enabled', function () {
    $response = $this->withToken(engineToken($this->client))
        ->getJson("/api/v1/games/{$this->game->uuid}/tenants")
        ->assertOk();

    $rows = collect($response->json('data'));
    expect($rows->pluck('slug')->all())->toBe(['alpha'])
        ->and($rows[0]['uuid'])->toBe($this->enabled->uuid)
        ->and($rows[0]['country_code'])->toBe('NA')
        ->and($rows[0]['currency'])->toBe('NAD')
        ->and($rows[0]['status'])->toBe('active')
        ->and($rows[0])->toHaveKey('name');
});

test('a client not bound to the game is refused', function () {
    $other = app(ClientRepository::class)->createClientCredentialsGrantClient('Other Engine');
    $other->forceFill(['secret' => 'engine-secret'])->save();

    $this->withToken(engineToken($other))
        ->getJson("/api/v1/games/{$this->game->uuid}/tenants")
        ->assertForbidden();
});

test('the gaming:read scope is required', function () {
    $this->withToken(engineToken($this->client, 'wallet:write'))
        ->getJson("/api/v1/games/{$this->game->uuid}/tenants")
        ->assertForbidden();
});

test('a player session token is rejected', function () {
    $player = User::factory()->create();

    $this->actingAs($player, 'api')
        ->getJson("/api/v1/games/{$this->game->uuid}/tenants")
        ->assertStatus(401);
});

test('an unknown game is a 404', function () {
    $this->withToken(engineToken($this->client))
        ->getJson('/api/v1/games/00000000-0000-0000-0000-000000000000/tenants')
        ->assertNotFound();
});

test('the config endpoint echoes the tenant it matched and 404s on an unknown one', function () {
    $this->game->tenants()->updateExistingPivot($this->enabled->id, ['custom_settings' => ['min_bet_amount' => 20]]);

    $this->getJson("/api/v1/games/{$this->game->uuid}/config?tenant_uuid={$this->enabled->uuid}")
        ->assertOk()
        ->assertJsonPath('tenant_uuid', $this->enabled->uuid)
        ->assertJsonPath('settings.min_bet_amount', 20);

    $this->getJson("/api/v1/games/{$this->game->uuid}/config")
        ->assertOk()
        ->assertJsonPath('tenant_uuid', null)
        ->assertJsonPath('settings.min_bet_amount', 5);

    $this->getJson("/api/v1/games/{$this->game->uuid}/config?tenant_uuid=00000000-0000-0000-0000-000000000000")
        ->assertNotFound();

    $this->getJson("/api/v1/games/{$this->game->uuid}/config?tenant_uuid={$this->enabled->slug}")
        ->assertNotFound();
});

test('service credit identifies the engine client from a pure client_credentials token', function () {
    // No session -> 404 proves the client was identified (the old code returned 401 before that check).
    $this->withToken(engineToken($this->client, 'wallet:write'))
        ->postJson('/api/v1/game/service/credit', ['session_token' => 'gs_missing', 'amount' => '1.00', 'reference' => 'vp_win_1'])
        ->assertNotFound();
});
