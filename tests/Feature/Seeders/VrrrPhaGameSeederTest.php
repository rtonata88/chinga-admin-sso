<?php

use App\Models\Game;
use App\Support\SettingsSchema;
use Database\Seeders\ChingaFantasyGameSeeder;
use Database\Seeders\VrrrPhaGameSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Laravel\Passport\Token;

function engineClient(): Client
{
    return Client::query()->where('name', 'Vrrr Pha Engine')->where('revoked', false)->firstOrFail();
}

test('seeds the Vrrr Pha game with the PRD settings, its schema and no backend by default', function () {
    $this->seed(VrrrPhaGameSeeder::class);

    $game = Game::where('slug', 'vrrr-pha')->firstOrFail();
    expect($game->status)->toBe('development')
        ->and($game->type)->toBe('instant')
        ->and($game->backend_url)->toBeNull()
        ->and($game->launch_url)->toBeNull()
        ->and($game->settings['house_edge'])->toBe(0.04)
        ->and($game->settings['betting_window_seconds'])->toBe(8)
        ->and($game->settings['auto_cashout_enabled'])->toBeTrue()
        ->and($game->settings['geo_allowed_countries'])->toBe(['NA'])
        ->and($game->settings['max_total_stake_per_round'])->toBe(25000);

    SettingsSchema::assertValid($game->settings_schema);
    expect((new SettingsSchema($game->settings_schema))->keys())->toHaveCount(12);
});

test('seeds a client_credentials engine client restricted to wallet:write and gaming:read, bound to the game', function () {
    $this->seed(VrrrPhaGameSeeder::class);

    $game = Game::where('slug', 'vrrr-pha')->firstOrFail();
    $client = engineClient();

    expect($client->grant_types)->toBe(['client_credentials'])
        ->and($client->scopes)->toBe(['wallet:write', 'gaming:read'])
        ->and($client->tenant_id)->toBeNull()
        ->and($client->confidential())->toBeTrue();

    expect(DB::table('oauth_client_games')->where('oauth_client_id', $client->id)->where('game_id', $game->id)->exists())->toBeTrue();
});

test('running the seeder twice creates nothing new', function () {
    $this->seed(VrrrPhaGameSeeder::class);
    $before = [Game::count(), Client::count(), DB::table('oauth_client_games')->count()];

    $this->seed(VrrrPhaGameSeeder::class);

    expect([Game::count(), Client::count(), DB::table('oauth_client_games')->count()])->toBe($before);
});

test('backend and launch URLs come from config and update on re-seed', function () {
    $this->seed(VrrrPhaGameSeeder::class);
    config(['services.vrrr_pha.backend_url' => 'http://localhost:3002', 'services.vrrr_pha.launch_url' => 'http://localhost:5174']);

    $this->seed(VrrrPhaGameSeeder::class);

    $game = Game::where('slug', 'vrrr-pha')->firstOrFail();
    expect($game->backend_url)->toBe('http://localhost:3002')
        ->and($game->launch_url)->toBe('http://localhost:5174');
});

test('the engine client can mint a token with its two scopes and nothing else', function () {
    $this->seed(VrrrPhaGameSeeder::class);
    $client = engineClient();
    $client->forceFill(['secret' => 'engine-secret'])->save();

    $granted = $this->postJson('/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->id,
        'client_secret' => 'engine-secret',
        'scope' => 'wallet:write gaming:read',
    ])->assertOk()->json();
    expect($granted['token_type'])->toBe('Bearer');

    $token = Token::query()->where('client_id', $client->id)->latest('created_at')->firstOrFail();
    expect($token->scopes)->toBe(['wallet:write', 'gaming:read']);

    // A wallet:write route accepts it (validation error, not a scope error)...
    $this->withToken($granted['access_token'])
        ->postJson('/api/v1/game/service/credit', [])
        ->assertStatus(422);

    // ...but a scope the client is not allowed to hold is dropped, so the same route refuses.
    $identity = $this->postJson('/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->id,
        'client_secret' => 'engine-secret',
        'scope' => 'openid admin',
    ])->assertOk()->json();

    $this->withToken($identity['access_token'])
        ->postJson('/api/v1/game/service/credit', [])
        ->assertForbidden();
});

test('the fantasy seeder binds its server client through oauth_client_games too', function () {
    $this->seed(ChingaFantasyGameSeeder::class);

    $game = Game::where('slug', 'chinga-fantasy')->firstOrFail();
    $client = Client::query()->where('name', 'Chinga Fantasy Game Server')->firstOrFail();

    expect(DB::table('oauth_client_games')->where('oauth_client_id', $client->id)->where('game_id', $game->id)->exists())->toBeTrue()
        ->and($client->scopes)->toBeNull();
});
