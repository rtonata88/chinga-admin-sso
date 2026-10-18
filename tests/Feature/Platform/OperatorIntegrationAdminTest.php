<?php

use App\Models\OperatorCall;
use App\Models\OperatorIntegration;
use App\Models\OperatorMovement;
use App\Models\SecurityAuditLog;
use App\Models\Tenant;
use App\Models\User;

/**
 * The platform console's Integration panel: create, rotate, edit. A secret is returned
 * once and never by a read; the wallet mode never changes under money.
 */
beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('platform_admin');

    $this->tenant = Tenant::factory()->create(['slug' => 'orange-bw', 'currency' => 'BWP', 'status' => 'active']);
    $this->base = "/api/v1/platform/tenants/{$this->tenant->uuid}/integration";
});

function createIntegration(array $body = []): \Illuminate\Testing\TestResponse
{
    return test()->actingAs(test()->platformAdmin)->postJson(test()->base, $body);
}

it('creates an integration, returns the secret once and never in a read', function () {
    $this->actingAs($this->platformAdmin)->getJson($this->base)->assertOk()->assertJsonPath('data', null);

    $res = createIntegration()->assertCreated()
        ->assertJsonPath('data.wallet_mode', 'hosted')
        ->assertJsonPath('data.status', 'active');
    $secret = $res->json('secret');
    expect($secret)->toBeString()->toHaveLength(48);

    $integration = OperatorIntegration::where('tenant_id', $this->tenant->id)->firstOrFail();
    expect($integration->secret)->toBe($secret)
        ->and($res->json('data.operator_key'))->toBe($integration->operator_key);

    $read = $this->getJson($this->base)->assertOk()->assertJsonPath('data.operator_key', $integration->operator_key);
    expect($read->json())->not->toHaveKey('secret')
        ->and($read->json('data'))->not->toHaveKeys(['secret', 'previous_secret'])
        ->and($read->getContent())->not->toContain($secret);

    expect(SecurityAuditLog::where('event_type', 'platform.operator_integration.create')->count())->toBe(1);

    // A second create is refused rather than silently issuing another secret.
    createIntegration()->assertStatus(409);
});

it('rotates the secret and keeps the old one verifying for 24 hours', function () {
    $old = createIntegration()->json('secret');

    $res = $this->postJson("{$this->base}/rotate-secret")->assertOk();
    $new = $res->json('secret');
    expect($new)->toBeString()->not->toBe($old)
        ->and($res->json('data.previous_secret_valid_until'))->not->toBeNull();

    $integration = OperatorIntegration::where('tenant_id', $this->tenant->id)->firstOrFail();
    expect($integration->verifyingSecrets())->toBe([$new, $old]);

    $read = $this->getJson($this->base)->assertOk();
    expect($read->getContent())->not->toContain($old)->not->toContain($new);

    $this->travel(25)->hours();
    expect($integration->fresh()->verifyingSecrets())->toBe([$new]);
    $this->getJson($this->base)->assertOk()->assertJsonPath('data.previous_secret_valid_until', null);

    expect(SecurityAuditLog::where('event_type', 'platform.operator_integration.rotate_secret')->count())->toBe(1);
});

it('updates origins and IPs, and rejects bad ones', function () {
    createIntegration();

    $this->putJson($this->base, [
        'embed_origins' => ['https://www.operator.com', 'https://m.operator.com:8443'],
        'allowed_ips' => ['196.1.2.3', '10.0.0.0/8', '2001:db8::/32'],
    ])->assertOk()
        ->assertJsonPath('data.embed_origins', ['https://www.operator.com', 'https://m.operator.com:8443'])
        ->assertJsonPath('data.allowed_ips', ['196.1.2.3', '10.0.0.0/8', '2001:db8::/32']);

    $this->putJson($this->base, ['embed_origins' => ['http://www.operator.com']])->assertUnprocessable()->assertJsonValidationErrors('embed_origins.0');
    $this->putJson($this->base, ['embed_origins' => ['https://www.operator.com/play']])->assertUnprocessable()->assertJsonValidationErrors('embed_origins.0');
    $this->putJson($this->base, ['allowed_ips' => ['not-an-ip']])->assertUnprocessable()->assertJsonValidationErrors('allowed_ips.0');
    $this->putJson($this->base, ['allowed_ips' => ['10.0.0.0/33']])->assertUnprocessable()->assertJsonValidationErrors('allowed_ips.0');
    $this->putJson($this->base, ['wallet_base_url' => 'http://wallet.operator.test'])->assertUnprocessable()->assertJsonValidationErrors('wallet_base_url');
    $this->putJson($this->base, ['wallet_mode' => 'seamless'])->assertUnprocessable()->assertJsonValidationErrors('wallet_base_url');

    $this->putJson($this->base, ['allowed_ips' => [], 'status' => 'suspended'])->assertOk()
        ->assertJsonPath('data.allowed_ips', [])
        ->assertJsonPath('data.status', 'suspended')
        ->assertJsonPath('data.embed_origins', ['https://www.operator.com', 'https://m.operator.com:8443']);

    expect(SecurityAuditLog::where('event_type', 'platform.operator_integration.update')->count())->toBe(2);
});

it('refuses a wallet-mode change while an operator player wallet holds money in the current mode', function () {
    createIntegration();
    $player = User::factory()->create(['tenant_id' => $this->tenant->id, 'external_player_id' => 'OBW-1']);
    $wallet = $player->getOrCreateWallet('BWP');
    $wallet->update(['balance' => '25.00', 'mode' => 'hosted']);

    $this->putJson($this->base, ['wallet_mode' => 'seamless', 'wallet_base_url' => 'https://wallet.operator.test'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('wallet_mode');
    expect(OperatorIntegration::where('tenant_id', $this->tenant->id)->value('wallet_mode'))->toBe('hosted');

    $wallet->update(['balance' => '0.00']);
    $this->putJson($this->base, ['wallet_mode' => 'seamless', 'wallet_base_url' => 'https://wallet.operator.test'])
        ->assertOk()->assertJsonPath('data.wallet_mode', 'seamless');
});

it('summarises calls and lists calls and movements needing attention', function () {
    createIntegration();
    $integration = OperatorIntegration::where('tenant_id', $this->tenant->id)->firstOrFail();
    $player = User::factory()->create(['tenant_id' => $this->tenant->id, 'external_player_id' => 'OBW-1']);
    OperatorCall::create(['operator_integration_id' => $integration->id, 'direction' => 'in', 'endpoint' => 'launch', 'http_status' => 200, 'outcome' => 'ok', 'duration_ms' => 40]);
    OperatorCall::create(['operator_integration_id' => $integration->id, 'direction' => 'in', 'endpoint' => 'launch', 'http_status' => 401, 'outcome' => 'bad_signature']);
    OperatorCall::create(['operator_integration_id' => $integration->id, 'direction' => 'out', 'endpoint' => 'debit', 'reference' => 'kk_bet_1', 'http_status' => 200, 'outcome' => 'ok', 'duration_ms' => 120]);
    foreach (['ok', 'retrying', 'abandoned'] as $i => $status) {
        OperatorMovement::create([
            'operator_integration_id' => $integration->id, 'user_id' => $player->id, 'kind' => 'credit',
            'reference' => "kk_win_{$i}", 'amount' => '12.50', 'currency' => 'BWP', 'status' => $status,
        ]);
    }

    $this->getJson($this->base)->assertOk()
        ->assertJsonPath('data.summary.operator_players', 1)
        ->assertJsonPath('data.summary.calls_in_24h', 2)
        ->assertJsonPath('data.summary.calls_out_24h', 1)
        ->assertJsonPath('data.summary.refused_24h', 1)
        ->assertJsonPath('data.summary.movements_attention', 2);

    $calls = $this->getJson("{$this->base}/calls?limit=2")->assertOk()->json('data');
    expect($calls)->toHaveCount(2)->and($calls[0])->not->toHaveKey('body_sha256');

    $movements = $this->getJson("{$this->base}/movements?status=retrying,abandoned")->assertOk()->json('data');
    expect(collect($movements)->pluck('status')->sort()->values()->all())->toBe(['abandoned', 'retrying'])
        ->and($movements[0]['external_player_id'])->toBe('OBW-1')
        ->and($movements[0]['amount'])->toBe('12.50');
});

it('is for platform admins only', function () {
    $tenantAdmin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $tenantAdmin->assignRole('tenant_admin', $this->tenant->id);

    $this->actingAs($tenantAdmin);
    $this->getJson($this->base)->assertForbidden();
    $this->postJson($this->base)->assertForbidden();
    $this->putJson($this->base, ['status' => 'suspended'])->assertForbidden();
    $this->postJson("{$this->base}/rotate-secret")->assertForbidden();
    $this->getJson("{$this->base}/calls")->assertForbidden();
    $this->getJson("{$this->base}/movements")->assertForbidden();
    expect(OperatorIntegration::count())->toBe(0);
});
