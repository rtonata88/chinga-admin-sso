<?php

use App\Channels\Contracts\ProviderOutcome;
use App\Models\MobileMoneyTransaction;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\Channels\MobileMoneyService;
use Database\Seeders\ChannelDemoSeeder;
use Database\Seeders\KulipiKunaGameSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

/**
 * Kulipi Kuna USSD design U3/U4: the gateway's identity, KYC, terms, player tokens,
 * mobile-money deposits and withdrawals, SMS and the simulator, against the demo seed.
 */
beforeEach(function () {
    config(['channels.simulator' => true]);
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->seed(KulipiKunaGameSeeder::class);
    $this->seed(ChannelDemoSeeder::class);
    $this->tenant = Tenant::where('slug', ChannelDemoSeeder::TENANT_SLUG)->firstOrFail();
    $this->client = Client::where('name', ChannelDemoSeeder::CLIENT_NAME)->firstOrFail();
    $this->client->forceFill(['secret' => 'gateway-secret'])->save();
    $this->game = \App\Models\Game::where('slug', 'kulipi-kuna')->firstOrFail();
    // Player tokens are personal access tokens: Passport needs its client in the test database.
    app(ClientRepository::class)->createPersonalAccessGrantClient('Test PAT client');
});

function gatewayToken(Client $client, string $scope = 'channel:ussd'): string
{
    return test()->postJson('/oauth/token', [
        'grant_type' => 'client_credentials', 'client_id' => $client->id, 'client_secret' => 'gateway-secret', 'scope' => $scope,
    ])->assertOk()->json('access_token');
}

function gw(string $method, string $uri, array $body = []): \Illuminate\Testing\TestResponse
{
    $t = test();
    // One test makes requests as several callers (the gateway, a player): start each clean.
    $t->flushHeaders();
    $token = gatewayToken($t->client);
    app('auth')->forgetGuards();

    return $t->withToken($token)->json($method, $uri, $body + ['tenant_uuid' => $t->tenant->uuid]);
}

/** Resolves, accepts the terms, and returns the admitted player. */
function admit(string $msisdn = '+26771000001'): User
{
    $uuid = gw('POST', '/api/v1/channels/players/resolve', ['msisdn' => $msisdn])->assertOk()->json('user_uuid');
    gw('POST', '/api/v1/channels/players/accept-terms', ['user_uuid' => $uuid])->assertOk();

    return User::where('uuid', $uuid)->firstOrFail();
}

it('routes the service code to the tenant, its game, its currency and its strings', function () {
    $r = gw('GET', '/api/v1/channels/ussd/routing')->assertOk();
    expect($r->json('service_codes.0'))->toMatchArray(['code' => '*123*45#', 'game_slug' => 'kulipi-kuna', 'game_name' => 'Kulipi Kuna', 'tenant_uuid' => $this->tenant->uuid])
        ->and($r->json('tenants.0.currency.code'))->toBe('BWP')
        ->and($r->json('tenants.0.money_provider'))->toBe('Orange Money')
        ->and($r->json('tenants.0.messages.en'))->toHaveKey('kk.win')
        ->and($r->json('providers.0.inbound.secret'))->toBeString();
});

it('works its age out from the KYC date of birth and says why when it cannot admit', function () {
    $outcomes = collect(['+26771000001', '+26771000003', '+26771000004', '+26771000005', '+26771000006', '+26779999999'])
        ->mapWithKeys(fn ($m) => [$m => gw('POST', '/api/v1/channels/players/resolve', ['msisdn' => $m])->assertOk()->json('status')]);
    expect($outcomes->all())->toBe([
        '+26771000001' => 'needs_terms',
        '+26771000003' => 'kyc_no_dob',
        '+26771000004' => 'under_18',
        '+26771000005' => 'kyc_not_verified',
        '+26771000006' => 'kyc_unavailable',
        '+26779999999' => 'kyc_not_verified',
    ]);
    // Only the adult has an account, with a wallet in the tenant's currency.
    expect(User::where('tenant_id', $this->tenant->id)->pluck('phone')->all())->toBe(['+26771000001']);
    $adult = User::where('phone', '+26771000001')->first();
    expect($adult->getOrCreateWallet()->currency)->toBe('BWP')
        ->and($adult->date_of_birth->toDateString())->toBe('1990-04-12')
        ->and($adult->kyc_status)->toBe('verified');
    // A local spelling of the same number is the same player.
    expect(gw('POST', '/api/v1/channels/players/resolve', ['msisdn' => '071000001'])->json('user_uuid'))->toBe($adult->uuid);
});

it('admits a player whose KYC is fixed later, without anyone at the operator stepping in', function () {
    gw('POST', '/api/v1/channels/players/resolve', ['msisdn' => '+26771000003'])->assertJsonPath('status', 'kyc_no_dob');
    DB::table('channel_sim_subscribers')->where('msisdn', '+26771000003')->update(['date_of_birth' => '1993-05-05']);
    gw('POST', '/api/v1/channels/players/resolve', ['msisdn' => '+26771000003'])->assertJsonPath('status', 'needs_terms');
});

it('mints a short player token only after the terms, only for its own tenant and a game it offers', function () {
    $uuid = gw('POST', '/api/v1/channels/players/resolve', ['msisdn' => '+26771000001'])->json('user_uuid');
    gw('POST', '/api/v1/channels/players/token', ['user_uuid' => $uuid, 'game_uuid' => $this->game->uuid])->assertForbidden();
    gw('POST', '/api/v1/channels/players/accept-terms', ['user_uuid' => $uuid])->assertOk();
    gw('POST', '/api/v1/channels/players/resolve', ['msisdn' => '+26771000001'])->assertJsonPath('status', 'admitted');
    $token = gw('POST', '/api/v1/channels/players/token', ['user_uuid' => $uuid, 'game_uuid' => $this->game->uuid])->assertOk()->json('access_token');
    $this->withToken($token)->getJson('/api/v1/oauth/userinfo')->assertOk()->assertJsonPath('sub', $uuid);
    $other = \App\Models\Game::factory()->create(['slug' => 'not-offered']);
    gw('POST', '/api/v1/channels/players/token', ['user_uuid' => $uuid, 'game_uuid' => $other->uuid])->assertForbidden();

    // A client not bound to the tenant cannot act for it at all.
    $stranger = app(ClientRepository::class)->createClientCredentialsGrantClient('Other gateway');
    $stranger->forceFill(['secret' => 'gateway-secret', 'scopes' => ['channel:ussd']])->save();
    $this->withToken(gatewayToken($stranger))->postJson('/api/v1/channels/players/resolve', ['tenant_uuid' => $this->tenant->uuid, 'msisdn' => '+26771000001'])->assertForbidden();
    // The gateway's client can only ever hold channel:ussd: asking for wallet money moves gets none.
    $jwt = gatewayToken($this->client, 'wallet:write');
    $scopes = json_decode(base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true)['scopes'] ?? [];
    expect($scopes)->not->toContain('wallet:write');
});

it('credits a deposit once, only after the subscriber approves with their PIN', function () {
    $user = admit();
    $r = gw('POST', '/api/v1/channels/deposits', ['user_uuid' => $user->uuid, 'amount' => '50'])->assertOk();
    $ref = $r->json('reference');
    expect($r->json('status'))->toBe('pending')
        ->and((string) $user->getOrCreateWallet()->fresh()->balance)->toBe('0.00');

    $phone = gw('GET', '/api/v1/channels/simulator/phone?msisdn=%2B26771000001')->assertOk();
    expect($phone->json('prompt'))->toMatchArray(['reference' => $ref, 'amount' => '50.00', 'currency' => 'BWP']);
    gw('POST', "/api/v1/channels/simulator/prompts/{$ref}", ['approve' => true, 'pin' => '0000'])->assertStatus(422);
    gw('POST', "/api/v1/channels/simulator/prompts/{$ref}", ['approve' => true, 'pin' => '1234'])->assertOk()->assertJsonPath('status', 'succeeded');

    expect((string) $user->getOrCreateWallet()->fresh()->balance)->toBe('50.00');
    expect((string) DB::table('channel_sim_subscribers')->where('msisdn', '+26771000001')->value('money_balance'))->toBe('450.00');
    // A repeated success (a callback after the status check) changes nothing.
    app(MobileMoneyService::class)->apply(MobileMoneyTransaction::where('reference', $ref)->first(), new ProviderOutcome('succeeded'), ['repeat' => true]);
    expect((string) $user->getOrCreateWallet()->fresh()->balance)->toBe('50.00');
    expect(SmsMessage::where('msisdn', '+26771000001')->where('purpose', 'sms.deposit_ok')->value('text'))
        ->toContain('Deposit of P50.00 from Orange Money received')->toContain('Balance P50.00');
});

it('takes nothing when the subscriber declines, and refuses amounts outside the limits', function () {
    $user = admit();
    $ref = gw('POST', '/api/v1/channels/deposits', ['user_uuid' => $user->uuid, 'amount' => '50'])->json('reference');
    gw('POST', "/api/v1/channels/simulator/prompts/{$ref}", ['approve' => false])->assertJsonPath('status', 'failed');
    expect((string) $user->getOrCreateWallet()->fresh()->balance)->toBe('0.00');
    gw('POST', '/api/v1/channels/deposits', ['user_uuid' => $user->uuid, 'amount' => '2'])->assertStatus(422)->assertJsonPath('message', 'Enter an amount from P5.00 to P2,000.00.');
    gw('POST', '/api/v1/channels/deposits', ['user_uuid' => $user->uuid, 'amount' => '10.555'])->assertStatus(422);
});

it('pays winnings out to the registered number, and refunds a definite failure but never an unknown one', function () {
    $user = admit();
    $wallet = $user->getOrCreateWallet();
    // Winnings, as a game credit leaves them (only winnings are withdrawable).
    $wallet->forceFill(['balance' => '120.00', 'winnings_balance' => '120.00'])->save();

    gw('POST', '/api/v1/channels/withdrawals', ['user_uuid' => $user->uuid, 'amount' => '200'])->assertStatus(422);
    $ok = gw('POST', '/api/v1/channels/withdrawals', ['user_uuid' => $user->uuid, 'amount' => '40'])->assertOk();
    expect($ok->json('status'))->toBe('succeeded')->and($ok->json('balance'))->toBe('80.00');
    expect((string) DB::table('channel_sim_subscribers')->where('msisdn', '+26771000001')->value('money_balance'))->toBe('540.00');
    expect(WithdrawalRequest::where('user_id', $user->id)->value('status'))->toBe('paid');

    // A payout whose outcome is unknown keeps the hold: nobody is paid twice.
    $wr = app(\App\Services\WithdrawalService::class)->request($user->fresh(), ['amount' => '30', 'payment_method' => 'mobile_money', 'payment_details' => ['phone_number' => $user->phone, 'provider' => 'Orange Money']]);
    $txn = MobileMoneyTransaction::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'channel_provider_id' => \App\Models\ChannelProvider::where('kind', 'mobile_money')->value('id'),
        'direction' => 'withdrawal', 'amount' => '30.00', 'currency' => 'BWP', 'msisdn' => $user->phone, 'reference' => 'mm_wd_test', 'status' => 'initiated', 'withdrawal_request_id' => $wr->id]);
    app(MobileMoneyService::class)->apply($txn, new ProviderOutcome('unknown', null, 'timeout'));
    expect($txn->fresh()->status)->toBe('unknown')->and((string) $user->getOrCreateWallet()->fresh()->balance)->toBe('50.00');
    app(MobileMoneyService::class)->apply($txn, new ProviderOutcome('failed', null, 'declined'));
    expect((string) $user->getOrCreateWallet()->fresh()->balance)->toBe('80.00')
        ->and($wr->fresh()->status)->toBe('rejected');
});

it('sends the ladder SMS from the engine in the tenant money, with a verify link, and none to web players', function () {
    $user = admit();
    $engine = Client::where('name', KulipiKunaGameSeeder::CLIENT_NAME)->firstOrFail();
    $engine->forceFill(['secret' => 'gateway-secret'])->save();
    $token = $this->postJson('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $engine->id, 'client_secret' => 'gateway-secret', 'scope' => 'wallet:write'])->json('access_token');
    $commitment = str_repeat('ab', 32);
    $this->withToken($token)->postJson('/api/v1/notify/ladder', [
        'game_uuid' => $this->game->uuid, 'tenant_uuid' => $this->tenant->uuid, 'user_uuid' => $user->uuid,
        'event' => 'collected', 'ladder_id' => 42, 'commitment' => $commitment, 'server_seed' => str_repeat('cd', 32), 'level' => 2, 'payout' => '38.40',
    ])->assertOk()->assertJsonPath('status', 'sent');
    $text = SmsMessage::where('purpose', 'sms.collected')->value('text');
    expect($text)->toContain('Kulipi Kuna ladder 42: you collected P38.40 at level 2')->toContain('/v/42?c=abababababab');

    // Fairness v2: a voided pick is explained, with what was paid back.
    $this->withToken($token)->postJson('/api/v1/notify/ladder', [
        'game_uuid' => $this->game->uuid, 'tenant_uuid' => $this->tenant->uuid, 'user_uuid' => $user->uuid,
        'event' => 'void', 'ladder_id' => 44, 'commitment' => $commitment, 'server_seed' => str_repeat('cd', 32), 'level' => 3, 'payout' => '38.40',
    ])->assertOk()->assertJsonPath('status', 'sent');
    // A v2 link carries its own key and the version, so the page can refuse a downgraded answer.
    $this->withToken($token)->postJson('/api/v1/notify/ladder', [
        'game_uuid' => $this->game->uuid, 'tenant_uuid' => $this->tenant->uuid, 'user_uuid' => $user->uuid,
        'event' => 'prepared', 'ladder_id' => 45, 'commitment' => $commitment, 'verify_key' => str_repeat('0f', 16), 'fairness' => 'v2',
    ])->assertOk();
    expect(SmsMessage::where('purpose', 'sms.commitment')->latest('id')->value('text'))
        ->toContain('/v/45?c=abababababab&k='.str_repeat('0f', 16).'&v=2');
    expect(SmsMessage::where('purpose', 'sms.void')->value('text'))
        ->toContain('Kulipi Kuna ladder 44: your level 3 pick was cancelled because its independent fairness check did not complete. P38.40 paid back.');

    $web = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->withToken($token)->postJson('/api/v1/notify/ladder', [
        'game_uuid' => $this->game->uuid, 'tenant_uuid' => $this->tenant->uuid, 'user_uuid' => $web->uuid,
        'event' => 'prepared', 'ladder_id' => 43, 'commitment' => $commitment,
    ])->assertOk()->assertJsonPath('status', 'skipped');
});

it('hides the simulator unless it is switched on', function () {
    config(['channels.simulator' => false]);
    gw('GET', '/api/v1/channels/simulator/subscribers')->assertNotFound();
});

it('runs the simulator outside development only for the sandbox tenants listed', function () {
    // Admitted while still in testing, so the player exists before the environment changes.
    $user = admit();
    app()->detectEnvironment(fn () => 'production');

    // On, but no tenant listed: nothing simulated anywhere.
    expect(\App\Channels\ChannelRegistry::simulatorAllowed($this->tenant))->toBeFalse();
    gw('GET', '/api/v1/channels/simulator/subscribers')->assertNotFound();
    expect(fn () => app(\App\Channels\ChannelRegistry::class)->kyc($this->tenant)->lookup('+26771000001'))
        ->toThrow(RuntimeException::class, 'disabled for this tenant');
    $before = (string) $user->getOrCreateWallet()->fresh()->balance;
    try {
        gw('POST', '/api/v1/channels/deposits', ['user_uuid' => $user->uuid, 'amount' => '50']);
    } catch (Throwable) {
        // Refused is all that matters here; the wallet below is the check.
    }
    expect((string) $user->getOrCreateWallet()->fresh()->balance)->toBe($before);

    // Another tenant listed: still nothing for this one.
    config(['channels.simulator_tenants' => [(string) \Illuminate\Support\Str::uuid()]]);
    expect(\App\Channels\ChannelRegistry::simulatorAllowed($this->tenant))->toBeFalse();
    gw('GET', '/api/v1/channels/simulator/subscribers')->assertNotFound();

    // This tenant listed: the sandbox works.
    config(['channels.simulator_tenants' => [$this->tenant->uuid]]);
    gw('GET', '/api/v1/channels/simulator/subscribers')->assertOk();
    $ref = gw('POST', '/api/v1/channels/deposits', ['user_uuid' => $user->uuid, 'amount' => '50'])->assertOk()->json('reference');
    gw('POST', "/api/v1/channels/simulator/prompts/{$ref}", ['approve' => true, 'pin' => '1234'])->assertOk()->assertJsonPath('status', 'succeeded');
    expect((string) $user->getOrCreateWallet()->fresh()->balance)->toBe(bcadd($before, '50', 2));

    // Switched off, the list does not matter.
    config(['channels.simulator' => false]);
    expect(\App\Channels\ChannelRegistry::simulatorAllowed($this->tenant))->toBeFalse();
});

it('seeds an integration sandbox on its own tenant, with a client bound to it alone', function () {
    $this->seed(\Database\Seeders\ChannelSandboxSeeder::class);
    $sandbox = Tenant::where('slug', \Database\Seeders\ChannelSandboxSeeder::TENANT_SLUG)->firstOrFail();
    $client = Client::where('name', \Database\Seeders\ChannelSandboxSeeder::CLIENT_NAME)->firstOrFail();

    expect($sandbox->id)->not->toBe($this->tenant->id)
        ->and($sandbox->name)->toBe('Orange Botswana (sandbox)')
        ->and($sandbox->currency)->toBe('BWP');
    expect(DB::table('channel_client_tenants')->where('oauth_client_id', $client->id)->pluck('tenant_id')->all())->toBe([$sandbox->id]);
    expect($client->fresh()->scopes)->toBe(['channel:ussd']);
    $ussd = \App\Models\ChannelProvider::where('tenant_id', $sandbox->id)->where('kind', 'ussd')->firstOrFail();
    expect($ussd->name)->toBe('Orange USSD (sandbox)')
        ->and(strlen($ussd->config['inbound']['secret']))->toBe(40);
    expect(\App\Models\ChannelProvider::where('tenant_id', $sandbox->id)->pluck('driver', 'kind')->all())
        ->toMatchArray(['mobile_money' => 'simulator', 'kyc' => 'simulator', 'sms' => 'simulator']);
    expect(DB::table('tenant_games')->where('tenant_id', $sandbox->id)->where('game_id', $this->game->id)->value('enabled'))->toBeTruthy();

    // Seeding again creates nothing new and keeps the secret Orange already has.
    $secret = $ussd->config['inbound']['secret'];
    $this->seed(\Database\Seeders\ChannelSandboxSeeder::class);
    expect(\App\Models\ChannelProvider::where('tenant_id', $sandbox->id)->where('kind', 'ussd')->count())->toBe(1)
        ->and($ussd->fresh()->config['inbound']['secret'])->toBe($secret)
        ->and(Client::where('name', \Database\Seeders\ChannelSandboxSeeder::CLIENT_NAME)->count())->toBe(1);
});

it("adds a provider's test number only to a listed sandbox tenant", function () {
    $this->seed(\Database\Seeders\ChannelSandboxSeeder::class);
    $sandbox = Tenant::where('slug', 'orange-bw-sandbox')->firstOrFail();
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('channels:sandbox-subscriber', ['msisdn' => '71 234 567'])->assertFailed();
    $this->artisan('channels:sandbox-subscriber', ['msisdn' => '+26771234567', '--tenant' => 'orange-bw-demo'])->assertFailed();
    expect(DB::table('channel_sim_subscribers')->where('msisdn', '+26771234567')->exists())->toBeFalse();

    config(['channels.simulator_tenants' => [$sandbox->uuid]]);
    $this->artisan('channels:sandbox-subscriber', ['msisdn' => '71 234 567', '--money' => '250.00'])->assertSuccessful();
    $row = DB::table('channel_sim_subscribers')->where('tenant_id', $sandbox->id)->where('msisdn', '+26771234567')->first();
    expect($row)->not->toBeNull()
        ->and($row->kyc)->toBe('verified')
        ->and((string) $row->money_balance)->toBe('250.00');
    // The demo tenant (not listed) is still refused.
    $this->artisan('channels:sandbox-subscriber', ['msisdn' => '+26771234568', '--tenant' => 'orange-bw-demo'])->assertFailed();
    $this->artisan('channels:sandbox-subscriber', ['msisdn' => '+26771234567', '--money' => 'lots'])->assertFailed();
});
