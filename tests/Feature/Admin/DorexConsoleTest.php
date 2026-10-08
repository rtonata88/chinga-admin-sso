<?php

use App\Contracts\ProvablyFairAdminClient;
use App\Models\Game;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DorexAdminClient;
use App\Services\GameAdminClientFactory;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Dorex consoles (X4): rounds, exposure and RTP on the shared pages, a round page of its own
 * with the redrawn path and the audit, and the nightly drift check at 3.5 standard errors.
 * Tenants by uuid only; the engine being down never takes a page down.
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
    $this->game = Game::factory()->create(['name' => 'Dorex', 'slug' => 'dorex', 'backend_url' => 'http://dorex.test']);
    $this->game->tenants()->attach($this->tenant->id, ['enabled' => true]);
});

function dorexRound(string $tenantUuid, string $state, array $extra = []): array
{
    return array_merge([
        'id' => 7, 'sequence' => 7, 'tenant_uuid' => $tenantUuid, 'state' => $state, 'instrument' => 'DRX',
        'commitment' => str_repeat('c', 64), 'house_edge' => '0.0400', 'q' => '0.001200', 'vol' => '0.0400',
        'max_ticks' => 450, 'tick_ms' => 100, 'max_price' => '500.00', 'max_win_per_position' => '16000.00',
        'start_price' => '0.9600', 'crash_point' => null, 'opened_at' => '2026-10-08T08:00:00Z', 'started_at' => null,
        'ended_at' => null, 'settled_at' => null, 'bet_count' => 1, 'total_wagered' => '10.00', 'total_paid_out' => '0.00',
    ], $extra);
}

function dorexEngine(string $tenantUuid, array $overrides = []): void
{
    // Overrides first: Http::fake answers with the first pattern that matches.
    Http::fake($overrides + [
        'sso.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        'dorex.test/api/admin/rtp*' => Http::response([
            'period' => ['from' => '2026-09-01T00:00:00.000Z', 'to' => '2026-10-01T00:00:00.000Z', 'tenant_uuid' => $tenantUuid],
            'bets_placed' => 4000, 'rounds' => 900, 'total_wagered' => '60000.00', 'total_paid_out' => '57300.00',
            'open_stake' => '30.00', 'margin_called' => 700,
            'realised_rtp' => '0.9550', 'standard_error' => '0.004000', 'theoretical_rtp' => '0.9600', 'house_edge' => '0.0400',
        ]),
        'dorex.test/api/admin/stats/by-day*' => Http::response(['period' => [], 'days' => []]),
        'dorex.test/api/admin/exposure*' => Http::response(['data' => [[
            'round_id' => 7, 'sequence' => 7, 'tenant_uuid' => $tenantUuid, 'state' => 'OPEN', 'staked' => '20500.00',
            'open_stake' => '20500.00', 'open_positions' => 410, 'open_bets' => 410, 'max_exposure' => '205000.00',
            'max_win_per_position' => '16000.00', 'stake_cap' => '25000.00', 'stake_cap_used' => '0.8200', 'alert' => true,
        ]]]),
        'dorex.test/api/admin/rounds/7/bets*' => Http::response(['data' => [[
            'id' => 3, 'user_uuid' => '5fbaab9c-013f-427d-81ea-4be1ed8713de', 'stake' => '10.00', 'effective_ceiling' => '500.0000',
            'outcome' => 'closed', 'close_tick' => 214, 'close_price' => '0.0000', 'close_reason' => 'margin_call', 'payout' => '0.00',
            'credit_status' => 'pending', 'latency_ms' => null, 'placed_at' => '2026-10-08T08:00:01Z',
        ]], 'meta' => ['limit' => 500, 'offset' => 0, 'total' => 1]]),
        'dorex.test/api/admin/rounds/7/verify' => Http::response([
            'round_id' => 7, 'sequence' => 7, 'state' => 'SETTLED', 'commitment' => str_repeat('c', 64),
            'path_hash' => str_repeat('a', 64), 'positions_checked' => 1, 'matches' => true, 'mismatches' => [],
        ]),
        'dorex.test/api/admin/rounds/7' => Http::response(dorexRound($tenantUuid, 'SETTLED', [
            'server_seed' => str_repeat('b', 64), 'client_seed' => "{$tenantUuid}:7", 'path_hash' => str_repeat('a', 64),
            'path_end' => 'margin_call', 'end_tick' => 214, 'margin_call_tick' => 214, 'peak_price' => '1.2345',
            'prices' => ['0.9600', '0.9996', '0.0000'], 'settled_at' => '2026-10-08T08:00:40Z',
        ])),
        'dorex.test/api/admin/rounds/8/bets*' => Http::response(['data' => [], 'meta' => ['limit' => 500, 'offset' => 0, 'total' => 0]]),
        'dorex.test/api/admin/rounds/8/verify' => Http::response(['message' => 'must not be called'], 500),
        'dorex.test/api/admin/rounds/8' => Http::response(dorexRound($tenantUuid, 'LIVE', ['id' => 8, 'sequence' => 8])),
        'dorex.test/api/admin/rounds*' => Http::response(['data' => [
            ['id' => 7, 'sequence' => 7, 'tenant_uuid' => $tenantUuid, 'state' => 'SETTLED', 'crash_point' => null, 'path_end' => 'margin_call', 'peak_price' => '1.2345',
                'commitment' => str_repeat('c', 64), 'opened_at' => '2026-10-08T08:00:00Z', 'bet_count' => 1, 'total_wagered' => '10.00', 'total_paid_out' => '0.00'],
        ], 'meta' => ['limit' => 25, 'offset' => 0, 'total' => 1]]),
    ]);
}

it('resolves Dorex to its provably-fair admin client', function () {
    $client = app(GameAdminClientFactory::class)->forGame($this->game);
    expect($client)->toBeInstanceOf(DorexAdminClient::class);
    expect($client)->toBeInstanceOf(ProvablyFairAdminClient::class);
});

it('serves rounds through the shared page as path rounds, with how each ended', function () {
    dorexEngine($this->tenant->uuid);
    $this->actingAs($this->platformAdmin)
        ->get('/dorex/rounds?tenant_uuid='.$this->tenant->uuid)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('vrrr-pha/rounds')
            ->where('game.kind', 'path')
            ->where('game.terms.many', 'stakes')
            ->where('rounds.0.path_end', 'margin_call'));
    Http::assertSent(fn ($r) => str_contains($r->url(), 'tenant_uuid='.$this->tenant->uuid));
});

it('shows an ended round with its redrawn path, the audit and every stake', function () {
    dorexEngine($this->tenant->uuid);
    $this->actingAs($this->platformAdmin)
        ->get('/dorex/rounds/7')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dorex/round-detail')
            ->where('round.path_end', 'margin_call')
            ->has('round.prices', 3)
            ->where('verify.matches', true)
            ->where('verify.positions_checked', 1)
            ->where('positions.0.close_reason', 'margin_call'));
});

it('never runs the audit on a round still in play, and shows no seed', function () {
    dorexEngine($this->tenant->uuid);
    $this->actingAs($this->platformAdmin)
        ->get('/dorex/rounds/8')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dorex/round-detail')->where('verify', null)->missing('round.server_seed'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/rounds/8/verify'));
});

it('says plainly when a round does not exist', function () {
    dorexEngine($this->tenant->uuid, ['dorex.test/api/admin/rounds/99*' => Http::response(['message' => 'round not found'], 404)]);
    $this->actingAs($this->platformAdmin)->get('/dorex/rounds/99')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('error', 'Round #99 was not found.'));
});

it('keeps the page up when the engine is down', function () {
    Http::fake(['sso.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]), 'dorex.test/*' => Http::response([], 503)]);
    $this->actingAs($this->platformAdmin)->get('/dorex/exposure')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('error', 'Could not load exposure. Is the Dorex engine reachable?'));
});

it('serves exposure and RTP through the shared pages, with the standard error', function () {
    dorexEngine($this->tenant->uuid);
    $this->actingAs($this->platformAdmin)->get('/dorex/exposure')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('vrrr-pha/exposure')->where('rows.0.alert', true)->where('rows.0.open_bets', 410));
    $this->actingAs($this->platformAdmin)->get('/dorex/rtp')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('vrrr-pha/rtp')->where('rtp.standard_error', '0.004000'));
});

it('judges Dorex drift at 3.5 standard errors in the nightly check', function () {
    Http::fake([
        'sso.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        'dorex.test/api/admin/rtp*' => Http::sequence()
            // −0.50 pts with σ 0.40 pts: inside ±1.40, not drift, though only 300 stakes.
            ->push(['bets_placed' => 300, 'realised_rtp' => '0.9550', 'standard_error' => '0.004000', 'theoretical_rtp' => '0.9600'])
            // −2.00 pts with σ 0.20 pts: outside ±0.70, drift, though under the old 3-point line.
            ->push(['bets_placed' => 300, 'realised_rtp' => '0.9400', 'standard_error' => '0.002000', 'theoretical_rtp' => '0.9600']),
    ]);
    $this->artisan('games:rtp-drift')
        ->expectsOutputToContain('Dorex: realised 95.50% vs theoretical 96.00% (-0.50 pts, 300 bets) — band ±1.40 pts (3.5σ)')
        ->assertExitCode(0)
        ->run();
    $this->artisan('games:rtp-drift')
        ->expectsOutputToContain('DRIFT Dorex: realised 94.00% vs theoretical 96.00% (-2.00 pts, 300 bets) — band ±0.70 pts (3.5σ)')
        ->assertExitCode(1)
        ->run();
});

it('keeps the consoles for platform admins only', function () {
    $tenantAdmin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $tenantAdmin->assignRole('tenant_admin', $this->tenant->id);
    foreach (['/dorex/rounds', '/dorex/rounds/7', '/dorex/exposure', '/dorex/rtp'] as $url) {
        $this->actingAs($tenantAdmin)->get($url)->assertForbidden();
    }
});
