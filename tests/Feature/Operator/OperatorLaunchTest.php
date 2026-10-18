<?php

use App\Models\Game;
use App\Models\GameSession;
use App\Models\OperatorIntegration;
use App\Models\OperatorLaunch;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Wallet;
use App\Support\OperatorSignature;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\ClientRepository;

/**
 * Operator integration, phase 1: an operator's server launches our games for its own
 * players (found or created on its tenant), and in hosted mode moves money in and out.
 */
beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    app(ClientRepository::class)->createPersonalAccessGrantClient('Test PAT client');
    $this->tenant = Tenant::factory()->create(['name' => 'Orange Botswana', 'slug' => 'orange-bw', 'currency' => 'BWP', 'country_code' => 'BW', 'status' => 'active']);
    $this->game = Game::factory()->create(['name' => 'Kulipi Kuna', 'slug' => 'kulipi-kuna', 'launch_url' => 'https://kulipi.playchinga.com']);
    $this->game->tenants()->attach($this->tenant->id, ['enabled' => true]);
    $this->secret = 'test-secret-'.Str::random(20);
    $this->integration = OperatorIntegration::create([
        'tenant_id' => $this->tenant->id, 'operator_key' => (string) Str::uuid(), 'secret' => $this->secret, 'wallet_mode' => 'hosted',
    ]);
});

/** A call from the operator's server, signed over the exact bytes sent. */
function signed(string $method, string $uri, array $body = [], ?string $secret = null, ?int $ts = null, ?string $key = null): TestResponse
{
    $t = test();
    $t->flushHeaders();
    $raw = $method === 'GET' ? '' : json_encode($body);
    $headers = OperatorSignature::headers($key ?? $t->integration->operator_key, $secret ?? $t->secret, $raw, $ts);
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return $t->call($method, $uri, [], [], [], $server, $raw);
}

function launch(array $overrides = []): TestResponse
{
    return signed('POST', '/api/v1/operator/launch', $overrides + ['player_id' => 'OBW-000481', 'game' => 'kulipi-kuna', 'currency' => 'BWP', 'display_name' => 'Kago M']);
}

function codeOf(TestResponse $r): string
{
    parse_str((string) parse_url($r->json('launch_url'), PHP_URL_QUERY), $q);

    return $q['code'];
}

it('refuses unsigned, wrongly signed, stale and unknown-operator calls', function () {
    $this->postJson('/api/v1/operator/launch', ['player_id' => 'x'])->assertStatus(401)->assertJsonPath('code', 'unauthorized');
    signed('POST', '/api/v1/operator/launch', ['player_id' => 'x'], secret: 'wrong')->assertStatus(401);
    signed('POST', '/api/v1/operator/launch', ['player_id' => 'x'], ts: time() - 301)->assertStatus(401);
    signed('POST', '/api/v1/operator/launch', ['player_id' => 'x'], key: (string) Str::uuid())->assertStatus(401);
    $this->assertDatabaseCount('users', User::count()); // nothing created
    expect(\App\Models\OperatorCall::where('outcome', 'bad_signature')->count())->toBe(2);
});

it('accepts the previous secret for 24 hours after a rotation, then refuses it', function () {
    $this->integration->update(['previous_secret' => $this->secret, 'secret' => 'brand-new-secret', 'secret_rotated_at' => now()]);
    launch()->assertOk();
    signed('POST', '/api/v1/operator/launch', ['player_id' => 'OBW-1', 'game' => 'kulipi-kuna', 'currency' => 'BWP'], secret: 'brand-new-secret')->assertOk();
    $this->travel(25)->hours();
    launch()->assertStatus(401);
});

it('creates the player on the first launch and reuses it after', function () {
    $first = launch()->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('player.created', true);
    expect($first->json('launch_url'))->toStartWith('https://kulipi.playchinga.com/launch?code=')->toContain('tenant=orange-bw');

    $user = User::where('external_player_id', 'OBW-000481')->firstOrFail();
    expect($user->tenant_id)->toBe($this->tenant->id)
        ->and($user->user_type)->toBe('operator')
        ->and($user->name)->toBe('Kago M')
        ->and($user->email)->toEndWith('@ext.orange-bw.invalid')
        ->and($user->wallet->currency)->toBe('BWP');

    launch(['display_name' => 'Kago Molefe'])->assertOk()->assertJsonPath('player.created', false);
    expect(User::where('external_player_id', 'OBW-000481')->count())->toBe(1)
        ->and($user->fresh()->name)->toBe('Kago Molefe');
});

it('keeps the same player id on two operators apart', function () {
    $other = Tenant::factory()->create(['slug' => 'lucky', 'currency' => 'NAD', 'status' => 'active']);
    $this->game->tenants()->attach($other->id, ['enabled' => true]);
    $otherIntegration = OperatorIntegration::create(['tenant_id' => $other->id, 'operator_key' => (string) Str::uuid(), 'secret' => 'other-secret']);

    launch()->assertOk();
    signed('POST', '/api/v1/operator/launch', ['player_id' => 'OBW-000481', 'game' => 'kulipi-kuna', 'currency' => 'NAD'], secret: 'other-secret', key: $otherIntegration->operator_key)->assertOk();

    expect(User::withoutGlobalScopes()->where('external_player_id', 'OBW-000481')->pluck('tenant_id')->sort()->values()->all())->toBe(collect([$this->tenant->id, $other->id])->sort()->values()->all());
});

it('refuses another currency, a game the operator does not offer and a blocked player', function () {
    launch(['currency' => 'NAD'])->assertStatus(400)->assertJsonPath('code', 'currency_mismatch');
    Game::factory()->create(['slug' => 'dorex', 'launch_url' => 'https://dorex.playchinga.com']);
    launch(['game' => 'dorex'])->assertStatus(404)->assertJsonPath('code', 'game_unavailable');
    launch(['player_id' => 'has space'])->assertStatus(400)->assertJsonPath('code', 'invalid_request');

    launch()->assertOk();
    signed('POST', '/api/v1/operator/players/OBW-000481/block', ['reason' => 'self-excluded'])->assertOk()->assertJsonPath('blocked', true);
    launch()->assertStatus(403)->assertJsonPath('code', 'player_blocked');
    signed('POST', '/api/v1/operator/players/OBW-000481/unblock')->assertOk();
    launch()->assertOk();
});

it('exchanges a launch code once, within 60 seconds, for a token on the right tenant', function () {
    $code = codeOf(launch()->assertOk());

    $token = $this->postJson('/api/v1/launch/exchange', ['code' => $code])->assertOk()
        ->assertJsonPath('tenant.slug', 'orange-bw')->assertJsonPath('game.slug', 'kulipi-kuna')
        ->json('access_token');
    $this->withToken($token)->getJson('/api/v1/oauth/userinfo')->assertOk()
        ->assertJsonPath('tenant_id', $this->tenant->uuid);

    $this->flushHeaders();
    $this->postJson('/api/v1/launch/exchange', ['code' => $code])->assertStatus(410)->assertJsonPath('code', 'launch_invalid');

    $late = codeOf(launch()->assertOk());
    $this->travel(61)->seconds();
    $this->postJson('/api/v1/launch/exchange', ['code' => $late])->assertStatus(410);
    $this->postJson('/api/v1/launch/exchange', ['code' => 'not-a-code'])->assertStatus(410);
    expect(OperatorLaunch::whereNotNull('used_at')->count())->toBe(1);
});

it('moves hosted money in and out exactly once per reference', function () {
    launch()->assertOk();
    $in = ['player_id' => 'OBW-000481', 'direction' => 'in', 'amount' => '100.00', 'currency' => 'BWP', 'reference' => 'tr-1'];

    signed('POST', '/api/v1/operator/transfers', $in)->assertOk()->assertJsonPath('balance', '100.00');
    signed('POST', '/api/v1/operator/transfers', $in)->assertOk()->assertJsonPath('balance', '100.00'); // a retry moves nothing
    signed('POST', '/api/v1/operator/transfers', ['amount' => '50.00'] + $in)->assertStatus(409)->assertJsonPath('code', 'reference_conflict');

    // The whole balance can go back: it is the operator's money, not winnings-only.
    signed('POST', '/api/v1/operator/transfers', ['direction' => 'out', 'amount' => '100.01', 'reference' => 'tr-2'] + $in)->assertStatus(402)->assertJsonPath('code', 'insufficient_funds');
    signed('POST', '/api/v1/operator/transfers', ['direction' => 'out', 'amount' => '100.00', 'reference' => 'tr-3'] + $in)->assertOk()->assertJsonPath('balance', '0.00');
    signed('GET', '/api/v1/operator/players/OBW-000481/balance')->assertOk()->assertJsonPath('balance', '0.00')->assertJsonPath('currency', 'BWP');

    signed('POST', '/api/v1/operator/transfers', ['currency' => 'NAD', 'reference' => 'tr-4'] + $in)->assertStatus(400)->assertJsonPath('code', 'currency_mismatch');
    signed('POST', '/api/v1/operator/transfers', ['amount' => '1.5e3', 'reference' => 'tr-5'] + $in)->assertStatus(400);
    signed('POST', '/api/v1/operator/transfers', ['player_id' => 'nobody', 'reference' => 'tr-6'] + $in)->assertStatus(404)->assertJsonPath('code', 'player_unknown');
});

it('refuses transfers and our balance for a seamless operator', function () {
    $this->integration->update(['wallet_mode' => 'seamless', 'wallet_base_url' => 'https://wallet.operator.test']);
    launch()->assertOk();
    signed('POST', '/api/v1/operator/transfers', ['player_id' => 'OBW-000481', 'direction' => 'in', 'amount' => '1.00', 'currency' => 'BWP', 'reference' => 'r'])
        ->assertStatus(409)->assertJsonPath('code', 'not_supported');
    signed('GET', '/api/v1/operator/players/OBW-000481/balance')->assertStatus(409);
});

it('ends open game sessions when the operator blocks the player', function () {
    launch()->assertOk();
    $user = User::where('external_player_id', 'OBW-000481')->firstOrFail();
    $wallet = Wallet::where('user_id', $user->id)->firstOrFail();
    $session = GameSession::create(['tenant_id' => $this->tenant->id, 'game_id' => $this->game->id, 'source_type' => Wallet::class, 'source_id' => $wallet->id, 'balance_start' => '0.00']);

    signed('POST', '/api/v1/operator/players/OBW-000481/block')->assertOk()->assertJsonPath('sessions_ended', 1);
    expect($session->fresh()->ended_at)->not->toBeNull()->and($session->fresh()->end_reason)->toBe('forced');
});
