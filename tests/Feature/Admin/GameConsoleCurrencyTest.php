<?php

use App\Models\Game;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantCurrencyMap;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The game consoles show each tenant's money in that tenant's own currency
 * and never add currencies together (USSD design U-D15): every engine row is
 * tagged with its tenant's currency, a cross-tenant total is one figure per
 * currency, and a figure the engine sums across currencies is withheld.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->seed(\Database\Seeders\RbacSeeder::class);
    config([
        'app.url' => 'http://sso.test',
        'services.sso_internal.client_id' => 'internal-id',
        'services.sso_internal.client_secret' => 'internal-secret',
    ]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('platform_admin');

    $this->nad = Tenant::factory()->create(['name' => 'Lucky Star Betting', 'slug' => 'lucky-star-betting', 'currency' => 'NAD']);
    $this->bwp = Tenant::factory()->create(['name' => 'Orange BW Demo', 'slug' => 'orange-bw-demo', 'currency' => 'BWP']);
});

function crashRoundRow(int $id, string $tenant, string $wagered, string $paid): array
{
    return [
        'id' => $id, 'sequence' => $id, 'tenant_uuid' => $tenant, 'state' => 'SETTLED', 'commitment' => str_repeat('ab', 32),
        'crash_point' => '2.00', 'house_edge' => 0.04, 'max_multiplier' => 320, 'growth_rate_k' => 0.06,
        'opened_at' => '2026-10-16T10:00:00.000Z', 'pulling_at' => null, 'crashed_at' => null, 'settled_at' => null,
        'bet_count' => 1, 'total_wagered' => $wagered, 'total_paid_out' => $paid, 'max_exposure' => '3200.00',
    ];
}

function fakeCrashEngine(array $routes): void
{
    Http::fake(['sso.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600])] + $routes);
}

test('the map resolves a tenant by uuid or slug and totals exactly per currency', function () {
    $map = TenantCurrencyMap::load();

    expect($map->of($this->bwp->uuid))->toBe('BWP')
        ->and($map->of(strtoupper($this->bwp->uuid)))->toBe('BWP')
        ->and($map->of('orange-bw-demo'))->toBe('BWP')
        ->and($map->of($this->nad->uuid))->toBe('NAD')
        ->and($map->of((string) \Illuminate\Support\Str::uuid()))->toBeNull()
        ->and($map->single([$this->nad->uuid, 'lucky-star-betting']))->toBe('NAD')
        ->and($map->single([$this->nad->uuid, $this->bwp->uuid]))->toBeNull();

    expect($map->totals([
        ['tenant_uuid' => $this->nad->uuid, 'total_wagered' => '0.10'],
        ['tenant_uuid' => $this->nad->uuid, 'total_wagered' => '0.20'],
        ['tenant_uuid' => $this->bwp->uuid, 'total_wagered' => '5.00'],
        ['tenant_uuid' => 'gone', 'total_wagered' => '1.00'],
    ], ['total_wagered']))->toBe([
        ['currency' => '?', 'total_wagered' => '1.00'],
        ['currency' => 'BWP', 'total_wagered' => '5.00'],
        ['currency' => 'NAD', 'total_wagered' => '0.30'],
    ]);
});

test('rounds and exposure rows carry their own tenant\'s currency', function () {
    Game::factory()->create(['name' => 'Vrrr Pha', 'slug' => 'vrrr-pha', 'backend_url' => 'http://crash.test']);
    fakeCrashEngine([
        'crash.test/api/admin/rounds*' => Http::response(['data' => [
            crashRoundRow(1, $this->nad->uuid, '100.00', '50.00'),
            crashRoundRow(2, $this->bwp->uuid, '30.00', '0.00'),
        ], 'meta' => ['limit' => 25, 'offset' => 0, 'total' => 2]]),
        'crash.test/api/admin/exposure*' => Http::response(['data' => [
            ['round_id' => 2, 'sequence' => 2, 'tenant_uuid' => $this->bwp->uuid, 'state' => 'PULLING', 'staked' => '30.00',
                'open_stake' => '30.00', 'open_bets' => 1, 'max_exposure' => '960.00', 'stake_cap' => '5000.00',
                'stake_cap_used' => '0.0060', 'alert' => false],
        ]]),
    ]);

    $this->actingAs($this->admin)->get('/vrrr-pha/rounds')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rounds.0.currency', 'NAD')
            ->where('rounds.1.currency', 'BWP')
            ->where('currencies.BWP.symbol', 'P')
            ->where('currencies.NAD.symbol', 'N$'));

    $this->actingAs($this->admin)->get('/vrrr-pha/exposure')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.currency', 'BWP'));
});

test('a round and its bets are in the round\'s tenant\'s currency', function () {
    Game::factory()->create(['name' => 'Vrrr Pha', 'slug' => 'vrrr-pha', 'backend_url' => 'http://crash.test']);
    fakeCrashEngine([
        'crash.test/api/admin/rounds/2/verify' => Http::response(['matches' => true]),
        'crash.test/api/admin/rounds/2/bets*' => Http::response(['data' => [
            ['id' => 9, 'round_id' => 2, 'tenant_uuid' => $this->bwp->uuid, 'stake' => '30.00', 'payout' => '0.00', 'outcome' => 'busted'],
        ]]),
        'crash.test/api/admin/rounds/2' => Http::response(crashRoundRow(2, $this->bwp->uuid, '30.00', '0.00')),
    ]);

    $this->actingAs($this->admin)->get('/vrrr-pha/rounds/2')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('round.currency', 'BWP')
            ->where('bets.0.currency', 'BWP'));
});

test('unfiltered RTP totals wagered and paid out per currency, and gives the day rows no currency', function () {
    Game::factory()->create(['name' => 'Vrrr Pha', 'slug' => 'vrrr-pha', 'backend_url' => 'http://crash.test']);
    fakeCrashEngine([
        'crash.test/api/admin/rtp*' => Http::response([
            'period' => [], 'bets_placed' => 3, 'rounds' => 2, 'total_wagered' => '130.00', 'total_paid_out' => '50.00',
            'realised_rtp' => '0.3846', 'theoretical_rtp' => '0.9600', 'house_edge' => '0.0400',
        ]),
        'crash.test/api/admin/stats/by-day*' => Http::response(['days' => [
            ['day' => '2026-10-16', 'total_wagered' => '130.00', 'total_paid_out' => '50.00', 'ggr' => '80.00'],
        ]]),
        'crash.test/api/admin/stats/by-tenant*' => Http::response(['tenants' => [
            ['tenant_uuid' => $this->nad->uuid, 'total_wagered' => '100.00', 'total_paid_out' => '50.00', 'ggr' => '50.00'],
            ['tenant_uuid' => $this->bwp->uuid, 'total_wagered' => '30.00', 'total_paid_out' => '0.00', 'ggr' => '30.00'],
        ]]),
    ]);

    $this->actingAs($this->admin)->get('/vrrr-pha/rtp')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('currency', null)
            ->where('totals', [
                ['currency' => 'BWP', 'total_wagered' => '30.00', 'total_paid_out' => '0.00', 'ggr' => '30.00'],
                ['currency' => 'NAD', 'total_wagered' => '100.00', 'total_paid_out' => '50.00', 'ggr' => '50.00'],
            ])
            ->where('rtp.realised_rtp', '0.3846'));
});

test('RTP filtered to a BWP tenant is in pula, from the engine\'s own figures', function () {
    Game::factory()->create(['name' => 'Vrrr Pha', 'slug' => 'vrrr-pha', 'backend_url' => 'http://crash.test']);
    fakeCrashEngine([
        'crash.test/api/admin/rtp*' => Http::response([
            'period' => [], 'bets_placed' => 1, 'rounds' => 1, 'total_wagered' => '30.00', 'total_paid_out' => '12.50',
            'realised_rtp' => '0.4167', 'theoretical_rtp' => '0.9600', 'house_edge' => '0.0400',
        ]),
        'crash.test/api/admin/stats/by-day*' => Http::response(['days' => []]),
        'crash.test/api/admin/stats/by-tenant*' => Http::response(['message' => 'must not be called'], 500),
    ]);

    $this->actingAs($this->admin)->get('/vrrr-pha/rtp?tenant_uuid='.$this->bwp->uuid)->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('currency', 'BWP')
            ->where('totals', [['currency' => 'BWP', 'total_wagered' => '30.00', 'total_paid_out' => '12.50', 'ggr' => '17.50']]));

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'stats/by-tenant'));
});

test('riding summed across currencies has no currency; one tenant\'s riding is in its own', function () {
    Game::factory()->create(['name' => 'Kulipi Kuna', 'slug' => 'kulipi-kuna', 'backend_url' => 'http://kulipi.test']);
    fakeCrashEngine([
        'kulipi.test/api/admin/riding*' => Http::response([
            'tenant_uuid' => null, 'total_riding' => '500.00', 'cap' => null, 'used' => null, 'alert' => false,
            'levels' => [['level' => 1, 'ladders' => 2, 'riding' => '500.00']],
        ]),
    ]);

    $this->actingAs($this->admin)->get('/kulipi-kuna/riding')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('currency', null));

    $this->actingAs($this->admin)->get('/kulipi-kuna/riding?tenant_uuid='.$this->bwp->uuid)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('currency', 'BWP'));

    // With every active tenant on one currency, the summed figure has that currency.
    $this->bwp->update(['status' => 'suspended']);
    $this->actingAs($this->admin)->get('/kulipi-kuna/riding')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('currency', 'NAD'));
});

test('a Dorex round is in its tenant\'s currency', function () {
    Game::factory()->create(['name' => 'Dorex', 'slug' => 'dorex', 'backend_url' => 'http://dorex.test']);
    fakeCrashEngine([
        'dorex.test/api/admin/rounds/7/bets*' => Http::response(['data' => []]),
        'dorex.test/api/admin/rounds/7' => Http::response([
            'id' => 7, 'sequence' => 7, 'tenant_uuid' => $this->bwp->uuid, 'state' => 'LIVE', 'max_win_per_position' => '16000.00',
            'total_wagered' => '10.00', 'total_paid_out' => '0.00',
        ]),
    ]);

    $this->actingAs($this->admin)->get('/dorex/rounds/7')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dorex/round-detail')->where('round.currency', 'BWP'));
});

test('Fantasy rounds that name the tenant by slug still get its currency', function () {
    Game::factory()->create(['name' => 'Chinga Fantasy', 'slug' => 'chinga-fantasy', 'backend_url' => 'http://fantasy.test']);
    fakeCrashEngine([
        'fantasy.test/api/admin/rounds*' => Http::response(['data' => [
            ['id' => 1, 'round_number' => 1, 'tenant_uuid' => 'orange-bw-demo', 'start_time' => '2026-10-16T10:00:00Z', 'end_time' => null,
                'winning_team_ids' => null, 'bet_count' => 1, 'total_wagered' => '20.00', 'total_paid_out' => '0.00'],
            ['id' => 2, 'round_number' => 2, 'tenant_uuid' => $this->nad->uuid, 'start_time' => '2026-10-16T10:01:00Z', 'end_time' => null,
                'winning_team_ids' => null, 'bet_count' => 1, 'total_wagered' => '20.00', 'total_paid_out' => '0.00'],
        ]]),
    ]);

    $this->actingAs($this->admin)->get('/fantasy/rounds')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fantasy/rounds')
            ->where('rounds.0.currency', 'BWP')
            ->where('rounds.0.tenant_uuid', 'orange-bw-demo')
            ->where('rounds.1.currency', 'NAD'));
});
