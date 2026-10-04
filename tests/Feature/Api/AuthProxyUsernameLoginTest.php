<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Client;

/**
 * Game logins accept a username as well as an email. These tests run the real Passport password
 * grant: the proxy's internal /oauth/token call is routed back into the application, carrying
 * the same headers, so the user lookup (User::findForPassport) is the one under test.
 */
beforeEach(function () {
    $this->luckyStar = Tenant::factory()->create(['slug' => 'lucky-star-betting', 'name' => 'Lucky Star Betting', 'status' => 'active']);
    $this->other = Tenant::factory()->create(['slug' => 'other-operator', 'name' => 'Other Operator', 'status' => 'active']);
    $this->client = Client::factory()->asPasswordClient()->asPublic()->create(['name' => 'Game client']);

    $this->player = User::factory()->create([
        'email' => 'player@example.test',
        'username' => 'luckyplayer',
        'password' => 'secret-pass-123',
        'status' => 'active',
        'tenant_id' => $this->luckyStar->id,
    ]);

    $test = $this;
    Http::fake([
        '*/oauth/token' => function ($request) use ($test) {
            $response = $test->withHeaders(array_filter(['X-Tenant-ID' => $request->header('X-Tenant-ID')[0] ?? null]))
                ->post('/oauth/token', $request->data(), ['Accept' => 'application/json']);

            return Http::response($response->getContent(), $response->getStatusCode(), ['Content-Type' => 'application/json']);
        },
    ]);
});

function gameLogin($test, string $username, string $password = 'secret-pass-123', ?string $tenant = null)
{
    return $test->withHeaders(array_filter(['X-Tenant-ID' => $tenant]))
        ->postJson('/api/v1/auth/login', [
            'client_id' => (string) $test->client->getKey(),
            'username' => $username,
            'password' => $password,
        ]);
}

test('a player logs in with their username and gets a token', function () {
    gameLogin($this, 'luckyplayer', tenant: 'lucky-star-betting')
        ->assertOk()
        ->assertJsonStructure(['access_token', 'token_type', 'expires_in']);
});

test('a player logs in with their username when no tenant is named and the username is unique', function () {
    gameLogin($this, 'luckyplayer')->assertOk()->assertJsonStructure(['access_token']);
});

test('email still works', function () {
    gameLogin($this, 'player@example.test', tenant: 'lucky-star-betting')
        ->assertOk()
        ->assertJsonStructure(['access_token']);
    gameLogin($this, 'player@example.test')->assertOk();
});

test('a wrong password with a username is refused', function () {
    gameLogin($this, 'luckyplayer', 'not-the-password', 'lucky-star-betting')
        ->assertStatus(400)
        ->assertJsonMissingPath('access_token');
});

test('a username that belongs to another tenant still gets tenant_mismatch', function () {
    gameLogin($this, 'luckyplayer', tenant: 'other-operator')
        ->assertStatus(403)
        ->assertJsonPath('code', 'tenant_mismatch')
        ->assertJsonPath('tenant.slug', 'lucky-star-betting');
});

test('with no tenant context, a username under two tenants is refused', function () {
    User::factory()->create([
        'email' => 'someone-else@example.test',
        'username' => 'luckyplayer',
        'password' => 'secret-pass-123',
        'status' => 'active',
        'tenant_id' => $this->other->id,
    ]);

    gameLogin($this, 'luckyplayer')
        ->assertStatus(400)
        ->assertJsonMissingPath('access_token');
});

test('with a tenant named, the same username under two tenants resolves to that tenant\'s player', function () {
    User::factory()->create([
        'email' => 'someone-else@example.test',
        'username' => 'luckyplayer',
        'password' => 'other-pass-456',
        'status' => 'active',
        'tenant_id' => $this->other->id,
    ]);

    gameLogin($this, 'luckyplayer', 'other-pass-456', 'other-operator')->assertOk()->assertJsonStructure(['access_token']);
    gameLogin($this, 'luckyplayer', 'secret-pass-123', 'lucky-star-betting')->assertOk()->assertJsonStructure(['access_token']);
});

test('a soft-deleted user\'s username is refused', function () {
    $this->player->delete();

    gameLogin($this, 'luckyplayer', tenant: 'lucky-star-betting')
        ->assertStatus(400)
        ->assertJsonMissingPath('access_token');
});
