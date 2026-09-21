<?php

use App\Models\Game;
use App\Models\Tenant;
use App\Models\User;
use App\Services\GameAdminClientFactory;
use App\Services\VrrrPhaAdminClient;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The Vrrr Pha consoles (M5): the SSO renders what the engine's admin
 * API returns, addresses tenants by uuid only, withholds the seed audit
 * for rounds still in play, and survives the engine being down.
 */
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

    $this->tenant = Tenant::factory()->create(['name' => 'Lucky Star Betting', 'slug' => 'lucky-star-betting']);
    $this->tenantAdmin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenantAdmin->assignRole('tenant_admin', $this->tenant->id);

    $this->game = Game::factory()->create(['name' => 'Vrrr Pha', 'slug' => 'vrrr-pha', 'backend_url' => 'http://crash.test']);
    $this->game->tenants()->attach($this->tenant->id, ['enabled' => true]);
});

function settledRound(string $tenantUuid): array
{
    return [
        'id' => 7, 'sequence' => 7, 'tenant_uuid' => $tenantUuid, 'state' => 'SETTLED',
        'commitment' => str_repeat('ab', 32), 'crash_point' => '2.45',
        'server_seed' => str_repeat('cd', 32), 'client_seed' => str_repeat('ef', 16), 'nonce' => 7,
        'house_edge' => 0.04, 'max_multiplier' => 320, 'growth_rate_k' => 0.06,
        'opened_at' => '2026-09-20T10:00:00.000Z', 'pulling_at' => '2026-09-20T10:00:10.000Z',
        'crashed_at' => '2026-09-20T10:00:25.000Z', 'settled_at' => '2026-09-20T10:00:26.000Z',
        'bet_count' => 2, 'total_wagered' => '30.00', 'total_paid_out' => '20.00', 'max_exposure' => '4800.00',
    ];
}

function fakeEngine(string $tenantUuid, array $overrides = []): void
{
    $liveRound = ['id' => 8, 'sequence' => 8, 'tenant_uuid' => $tenantUuid, 'state' => 'PULLING',
        'commitment' => str_repeat('11', 32), 'house_edge' => 0.04, 'max_multiplier' => 320, 'growth_rate_k' => 0.06,
        'opened_at' => '2026-09-20T10:01:00.000Z', 'pulling_at' => '2026-09-20T10:01:10.000Z',
        'crashed_at' => null, 'settled_at' => null, 'bet_count' => 1, 'total_wagered' => '10.00',
        'total_paid_out' => '0.00', 'max_exposure' => '3200.00'];

    Http::fake(array_merge([
        'sso.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        'crash.test/api/admin/rounds/7/verify' => Http::response([
            'round_id' => 7, 'sequence' => 7, 'state' => 'SETTLED', 'commitment' => str_repeat('ab', 32),
            'crash_point' => '2.45', 'recomputed' => ['commitment' => str_repeat('ab', 32), 'crash_point' => '2.45'],
            'matches' => true,
        ]),
        'crash.test/api/admin/rounds/7/bets*' => Http::response(['data' => [
            ['id' => 41, 'round_id' => 7, 'sequence' => 7, 'tenant_uuid' => $tenantUuid, 'user_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'stake' => '20.00', 'amount_from_deposit' => '20.00', 'auto_cashout_target' => null, 'cashout_multiplier' => '1.00',
                'payout' => '20.00', 'outcome' => 'cashed', 'credit_status' => 'credited', 'placed_by' => 'player',
                'placed_at' => '2026-09-20T10:00:02.000Z', 'cancelled_at' => null, 'crash_point' => '2.45', 'round_state' => 'SETTLED',
                'cashout_trigger' => 'manual', 'latency_ms' => 120],
            ['id' => 42, 'round_id' => 7, 'sequence' => 7, 'tenant_uuid' => $tenantUuid, 'user_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'stake' => '10.00', 'amount_from_deposit' => '10.00', 'auto_cashout_target' => '3.00', 'cashout_multiplier' => null,
                'payout' => '0.00', 'outcome' => 'busted', 'credit_status' => null, 'placed_by' => 'player',
                'placed_at' => '2026-09-20T10:00:03.000Z', 'cancelled_at' => null, 'crash_point' => '2.45', 'round_state' => 'SETTLED',
                'cashout_trigger' => null, 'latency_ms' => null],
        ], 'meta' => ['limit' => 500, 'offset' => 0, 'total' => 2]]),
        'crash.test/api/admin/rounds/7' => Http::response(settledRound($tenantUuid)),
        'crash.test/api/admin/rounds/8/verify' => Http::response(['message' => 'must not be called'], 500),
        'crash.test/api/admin/rounds/8/bets*' => Http::response(['data' => [], 'meta' => ['limit' => 500, 'offset' => 0, 'total' => 0]]),
        'crash.test/api/admin/rounds/8' => Http::response($liveRound),
        'crash.test/api/admin/rounds*' => Http::response([
            'data' => [
                array_merge($liveRound, ['crash_point' => null]),
                array_diff_key(settledRound($tenantUuid), array_flip(['server_seed', 'client_seed', 'nonce'])),
            ],
            'meta' => ['limit' => 25, 'offset' => 0, 'total' => 2],
        ]),
        'crash.test/api/admin/exposure*' => Http::response(['data' => [
            ['round_id' => 8, 'sequence' => 8, 'tenant_uuid' => $tenantUuid, 'state' => 'PULLING', 'staked' => '4200.00',
                'open_stake' => '4200.00', 'open_bets' => 3, 'max_exposure' => '16000.00', 'stake_cap' => '5000.00',
                'stake_cap_used' => '0.8400', 'alert' => true],
        ]]),
        'crash.test/api/admin/rtp*' => Http::response([
            'period' => ['from' => '2026-08-23T00:00:00.000Z', 'to' => '2026-09-22T00:00:00.000Z', 'tenant_uuid' => $tenantUuid],
            'bets_placed' => 1200, 'rounds' => 900, 'total_wagered' => '12000.00', 'total_paid_out' => '11400.00',
            'realised_rtp' => '0.9500', 'theoretical_rtp' => '0.9600', 'house_edge' => '0.0400',
        ]),
        'crash.test/api/admin/stats/by-day*' => Http::response(['period' => [], 'days' => [
            ['day' => '2026-09-20', 'bets_placed' => 600, 'active_players' => 12, 'total_wagered' => '6000.00',
                'total_paid_out' => '5700.00', 'deposit_wagered' => '4000.00', 'deposit_paid_out' => '3800.00',
                'cashed' => 300, 'busted' => 300, 'pending' => 0, 'rounds' => 450, 'ggr' => '300.00', 'real_ggr' => '200.00'],
        ]]),
    ], $overrides));
}

test('the factory resolves the Vrrr Pha client for the vrrr-pha slug', function () {
    expect(app(GameAdminClientFactory::class)->forGame($this->game))->toBeInstanceOf(VrrrPhaAdminClient::class);
});

test('the rounds console lists the engine rounds and filters tenants by uuid only', function () {
    fakeEngine($this->tenant->uuid);

    $this->actingAs($this->platformAdmin)
        ->get('/vrrr-pha/rounds?tenant_uuid='.$this->tenant->uuid.'&page=2')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('vrrr-pha/rounds')
            ->has('rounds', 2)
            ->where('rounds.0.state', 'PULLING')
            ->where('rounds.0.crash_point', null)
            ->where('rounds.1.crash_point', '2.45')
            ->where('filters.tenant_uuid', $this->tenant->uuid)
            ->where('filters.page', 2)
            ->where('filters.total', 2)
            ->where('tenantNames.'.$this->tenant->uuid, 'Lucky Star Betting')
            ->where('error', null)
        );

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'http://crash.test/api/admin/rounds')
        && $request->data()['tenant_uuid'] === $this->tenant->uuid
        && (int) $request->data()['offset'] === 25
        && $request->hasHeader('Authorization', 'Bearer tok'));
});

test('a slug in the tenant filter is dropped, never forwarded to the engine', function () {
    fakeEngine($this->tenant->uuid);

    $this->actingAs($this->platformAdmin)
        ->get('/vrrr-pha/rounds?tenant_uuid=lucky-star-betting')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.tenant_uuid', null));

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'http://crash.test/api/admin/rounds')
        && ! array_key_exists('tenant_uuid', $request->data()));
});

test('a settled round shows its bets and the seed audit', function () {
    fakeEngine($this->tenant->uuid);

    $this->actingAs($this->platformAdmin)
        ->get('/vrrr-pha/rounds/7')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('vrrr-pha/round-detail')
            ->where('round.sequence', 7)
            ->where('round.server_seed', str_repeat('cd', 32))
            ->has('bets', 2)
            ->where('bets.1.outcome', 'busted')
            ->where('verify.matches', true)
            ->where('verify.recomputed.crash_point', '2.45')
            ->where('error', null)
        );
});

test('a round still in play gets no seed audit call', function () {
    fakeEngine($this->tenant->uuid);

    $this->actingAs($this->platformAdmin)
        ->get('/vrrr-pha/rounds/8')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('vrrr-pha/round-detail')
            ->where('round.state', 'PULLING')
            ->missing('round.server_seed')
            ->where('verify', null)
        );

    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/rounds/8/verify'));
});

test('a failed audit is reported without taking the round page down', function () {
    fakeEngine($this->tenant->uuid, [
        'crash.test/api/admin/rounds/7/verify' => Http::response('boom', 500),
    ]);

    $this->actingAs($this->platformAdmin)
        ->get('/vrrr-pha/rounds/7')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('round.sequence', 7)
            ->has('bets', 2)
            ->where('verify.error', 'The seed audit could not be run.')
        );
});

test('the exposure console shows open rounds with their cap alerts', function () {
    fakeEngine($this->tenant->uuid);

    $this->actingAs($this->platformAdmin)
        ->get('/vrrr-pha/exposure?tenant_uuid='.$this->tenant->uuid)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('vrrr-pha/exposure')
            ->has('rows', 1)
            ->where('rows.0.alert', true)
            ->where('rows.0.stake_cap_used', '0.8400')
            ->where('filters.tenant_uuid', $this->tenant->uuid)
            ->has('fetchedAt')
        );

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'http://crash.test/api/admin/exposure')
        && $request->data()['tenant_uuid'] === $this->tenant->uuid);
});

test('the RTP console sends a half-open window and shows realised against theoretical', function () {
    fakeEngine($this->tenant->uuid);

    $this->actingAs($this->platformAdmin)
        ->get('/vrrr-pha/rtp?from=2026-09-01&to=2026-09-20')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('vrrr-pha/rtp')
            ->where('rtp.realised_rtp', '0.9500')
            ->where('rtp.theoretical_rtp', '0.9600')
            ->has('days', 1)
            ->where('filters.from', '2026-09-01')
            ->where('filters.to', '2026-09-20')
        );

    // Inclusive calendar dates become [from 00:00, day-after-to 00:00) for the engine.
    Http::assertSent(function ($request) {
        if (! str_starts_with($request->url(), 'http://crash.test/api/admin/rtp')) {
            return false;
        }
        $q = $request->data();

        return str_starts_with($q['from'], '2026-09-01T00:00:00') && str_starts_with($q['to'], '2026-09-21T00:00:00');
    });
});

test('the RTP console defaults to the last 30 days and ignores malformed dates', function () {
    fakeEngine($this->tenant->uuid);
    $this->travelTo('2026-09-21 12:00:00');

    $this->actingAs($this->platformAdmin)
        ->get('/vrrr-pha/rtp?from=yesterday&to=21/09/2026')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', '2026-08-23')
            ->where('filters.to', '2026-09-21')
        );
});

test('an unreachable engine renders the console with an error instead of a 500', function () {
    Http::fake([
        'sso.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        'crash.test/*' => Http::response('down', 503),
    ]);

    $this->actingAs($this->platformAdmin)
        ->get('/vrrr-pha/rounds')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rounds', 0)
            ->where('error', 'Could not load rounds. Is the Vrrr Pha engine reachable?')
        );

    $this->actingAs($this->platformAdmin)
        ->get('/vrrr-pha/exposure')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('error', 'Could not load exposure. Is the Vrrr Pha engine reachable?'));

    $this->actingAs($this->platformAdmin)
        ->get('/vrrr-pha/rtp')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('rtp', null)->where('error', 'Could not load RTP. Is the Vrrr Pha engine reachable?'));
});

test('the consoles are platform-admin only', function () {
    fakeEngine($this->tenant->uuid);

    $paths = ['/vrrr-pha/rounds', '/vrrr-pha/rounds/7', '/vrrr-pha/exposure', '/vrrr-pha/rtp'];
    foreach ($paths as $path) {
        $this->get($path)->assertRedirect('/login');
    }
    $this->actingAs($this->tenantAdmin);
    foreach ($paths as $path) {
        $this->get($path)->assertForbidden();
    }

    Http::assertNothingSent();
});
