<?php

use App\Contracts\ProvablyFairAdminClient;
use App\Models\Game;
use App\Models\Tenant;
use App\Models\User;
use App\Services\GameAdminClientFactory;
use App\Services\KulipiKunaAdminClient;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Kulipi Kuna consoles (K4 design A1): riding by level, realised RTP with
 * the depth table, ladder verify, and the nightly drift check. Tenants by
 * uuid only; the engine being down never takes a page down.
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
    $this->game = Game::factory()->create(['name' => 'Kulipi Kuna', 'slug' => 'kulipi-kuna', 'backend_url' => 'http://kulipi.test']);
    $this->game->tenants()->attach($this->tenant->id, ['enabled' => true]);
});

function kulipiEngine(string $tenantUuid, array $overrides = []): void
{
    Http::fake(array_merge([
        'sso.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        'kulipi.test/api/admin/riding*' => Http::response([
            'tenant_uuid' => $tenantUuid, 'total_riding' => '20160.00', 'cap' => '25000.00', 'used' => '0.8064', 'alert' => true, 'held_credits' => 2,
            'levels' => [
                ['level' => 1, 'ladders' => 40, 'riding' => '7680.00'],
                ['level' => 2, 'ladders' => 20, 'riding' => '7680.00'],
                ['level' => 3, 'ladders' => 6, 'riding' => '4800.00'],
            ],
        ]),
        'kulipi.test/api/admin/rtp*' => Http::response([
            'period' => ['from' => '2026-09-01T00:00:00.000Z', 'to' => '2026-10-01T00:00:00.000Z', 'tenant_uuid' => $tenantUuid],
            'ladders' => 5000, 'bets_placed' => 5000, 'rounds' => 900,
            'total_wagered' => '50000.00', 'total_paid_out' => '47900.00',
            'realised_rtp' => '0.9580', 'theoretical_rtp' => '0.9600', 'house_edge' => '0.0400',
            'live_ladders' => 3, 'live_stake' => '30.00',
            'depth_histogram' => [['level' => 1, 'ladders' => 3000], ['level' => 2, 'ladders' => 1500], ['level' => 3, 'ladders' => 500]],
        ]),
        'kulipi.test/api/admin/stats/by-day*' => Http::response(['period' => [], 'days' => []]),
        'kulipi.test/api/admin/ladders/41/verify' => Http::response([
            'ladder_id' => 41, 'user_uuid' => '5fbaab9c-013f-427d-81ea-4be1ed8713de', 'steps' => 3, 'valid' => true, 'mismatches' => [],
        ]),
        'kulipi.test/api/admin/ladders/42/verify' => Http::response([
            'ladder_id' => 42, 'user_uuid' => '5fbaab9c-013f-427d-81ea-4be1ed8713de', 'steps' => 1, 'valid' => false,
            'mismatches' => [['roundId' => 7, 'level' => 1, 'field' => 'objectHand', 'expected' => 'left', 'published' => 'right']],
        ]),
        'kulipi.test/api/admin/ladders/43/verify' => Http::response([
            'ladder_id' => 43, 'user_uuid' => '5fbaab9c-013f-427d-81ea-4be1ed8713de', 'steps' => 0, 'valid' => false,
            'mismatches' => [['roundId' => null, 'level' => null, 'field' => 'sequence', 'expected' => '1', 'published' => '0']],
        ]),
        'kulipi.test/api/admin/ladders/404/verify' => Http::response(['message' => 'ladder not found'], 404),
    ], $overrides));
}

it('resolves Kulipi Kuna to its provably-fair admin client', function () {
    $client = app(GameAdminClientFactory::class)->forGame($this->game);
    expect($client)->toBeInstanceOf(KulipiKunaAdminClient::class);
    expect($client)->toBeInstanceOf(ProvablyFairAdminClient::class);
});

it('reads riding, RTP and ladder verify from the engine by tenant uuid', function () {
    kulipiEngine($this->tenant->uuid);
    $client = new KulipiKunaAdminClient($this->game);

    expect($client->riding($this->tenant->uuid)['alert'])->toBeTrue();
    expect($client->exposure($this->tenant->uuid)['total_riding'])->toBe('20160.00');
    expect($client->rtp($this->tenant->uuid)['bets_placed'])->toBe(5000);
    expect($client->verifyLadder(41)['valid'])->toBeTrue();

    Http::assertSent(fn ($r) => str_contains($r->url(), '/api/admin/riding') && str_contains($r->url(), 'tenant_uuid='.$this->tenant->uuid));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'lucky-star-betting'));
});

it('refuses round verify: Kulipi verifies ladders', function () {
    expect(fn () => (new KulipiKunaAdminClient($this->game))->verifyRound(7))
        ->toThrow(RuntimeException::class, 'ladder');
});

it('includes Kulipi Kuna in the nightly RTP drift check', function () {
    Http::fake([
        'sso.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        'kulipi.test/api/admin/rtp*' => Http::sequence()
            ->push(['bets_placed' => 5000, 'realised_rtp' => '0.9000', 'theoretical_rtp' => '0.9600'])
            ->push(['bets_placed' => 5000, 'realised_rtp' => '0.9590', 'theoretical_rtp' => '0.9600']),
    ]);
    $this->artisan('games:rtp-drift', ['--days' => 7])
        ->expectsOutputToContain('DRIFT Kulipi Kuna: realised 90.00% vs theoretical 96.00% (-6.00 pts, 5000 bets)')
        ->assertExitCode(1)
        ->run();
    $this->artisan('games:rtp-drift')
        ->expectsOutputToContain('Kulipi Kuna: realised 95.90% vs theoretical 96.00%')
        ->assertExitCode(0)
        ->run();
});

it('renders riding by level for a tenant, with the 80% alert', function () {
    kulipiEngine($this->tenant->uuid);
    $this->actingAs($this->platformAdmin)
        ->get('/kulipi-kuna/riding?tenant_uuid='.$this->tenant->uuid)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('kulipi-kuna/riding')
            ->where('game.name', 'Kulipi Kuna')
            ->where('game.base', '/kulipi-kuna')
            ->where('game.terms.many', 'ladders')
            ->where('riding.alert', true)
            ->where('riding.used', '0.8064')
            ->has('riding.levels', 3)
            ->where('filters.tenant_uuid', $this->tenant->uuid)
            ->where('error', null));
});

it('shows how many credits are held for reconciliation', function () {
    kulipiEngine($this->tenant->uuid);
    $this->actingAs($this->platformAdmin)
        ->get('/kulipi-kuna/riding?tenant_uuid='.$this->tenant->uuid)
        ->assertInertia(fn (Assert $page) => $page->where('riding.held_credits', 2));
});

it('asks the engine for all tenants when none is picked, and never forwards a slug', function () {
    kulipiEngine($this->tenant->uuid, [
        'kulipi.test/api/admin/riding*' => Http::response([
            'tenant_uuid' => null, 'total_riding' => '20160.00', 'cap' => null, 'used' => null, 'alert' => false, 'levels' => [],
        ]),
    ]);
    $this->actingAs($this->platformAdmin)
        ->get('/kulipi-kuna/riding?tenant_uuid=lucky-star-betting')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.tenant_uuid', null)
            ->where('riding.cap', null)
            ->where('riding.alert', false));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'lucky-star-betting'));
});

it('keeps the riding page up when the engine is down', function () {
    Http::fake([
        'sso.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        'kulipi.test/*' => Http::response('down', 502),
    ]);
    $this->actingAs($this->platformAdmin)
        ->get('/kulipi-kuna/riding')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('riding', null)
            ->where('error', 'Could not load riding. Is the Kulipi Kuna engine reachable?'));
});

it('verifies a ladder by id, and says plainly when it does not exist', function () {
    kulipiEngine($this->tenant->uuid);
    $this->actingAs($this->platformAdmin)
        ->get('/kulipi-kuna/ladder')
        ->assertInertia(fn (Assert $page) => $page->component('kulipi-kuna/ladder')->where('ladderId', null)->where('result', null));
    $this->actingAs($this->platformAdmin)
        ->get('/kulipi-kuna/ladder?id=41')
        ->assertInertia(fn (Assert $page) => $page->where('ladderId', 41)->where('result.valid', true)->where('pending', false)->where('error', null));
    $this->actingAs($this->platformAdmin)
        ->get('/kulipi-kuna/ladder?id=42')
        ->assertInertia(fn (Assert $page) => $page->where('result.valid', false)->where('pending', false)->has('result.mismatches', 1));
    $this->actingAs($this->platformAdmin)
        ->get('/kulipi-kuna/ladder?id=404')
        ->assertInertia(fn (Assert $page) => $page->where('result', null)->where('error', 'Ladder #404 was not found.'));
    $this->actingAs($this->platformAdmin)
        ->get('/kulipi-kuna/ladder?id=abc')
        ->assertInertia(fn (Assert $page) => $page->where('ladderId', null));
});

it('marks a ladder with nothing revealed yet as pending, not as a mismatch', function () {
    kulipiEngine($this->tenant->uuid);
    $this->actingAs($this->platformAdmin)
        ->get('/kulipi-kuna/ladder?id=43')
        ->assertInertia(fn (Assert $page) => $page
            ->where('result.steps', 0)
            ->where('pending', true)
            ->where('error', null));
});

it('keeps the tenant filter when a tenant has no round yet, so the page can say why there is no cap', function () {
    kulipiEngine($this->tenant->uuid, [
        'kulipi.test/api/admin/riding*' => Http::response([
            'tenant_uuid' => $this->tenant->uuid, 'total_riding' => '0.00', 'cap' => null, 'used' => null, 'alert' => false, 'levels' => [],
        ]),
    ]);
    $this->actingAs($this->platformAdmin)
        ->get('/kulipi-kuna/riding?tenant_uuid='.$this->tenant->uuid)
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.tenant_uuid', $this->tenant->uuid)
            ->where('riding.cap', null));
});

it('tells the shared rounds pages its rounds are ladder rounds, without crash fields', function () {
    kulipiEngine($this->tenant->uuid, [
        'kulipi.test/api/admin/rounds/7/bets*' => Http::response(['data' => [], 'meta' => ['limit' => 500, 'offset' => 0, 'total' => 0]]),
        'kulipi.test/api/admin/rounds/7' => Http::response(['id' => 7, 'sequence' => 7, 'tenant_uuid' => $this->tenant->uuid, 'state' => 'SETTLED']),
        'kulipi.test/api/admin/rounds*' => Http::response(['data' => [], 'meta' => ['limit' => 25, 'offset' => 0, 'total' => 0]]),
    ]);
    $this->actingAs($this->platformAdmin)
        ->get('/kulipi-kuna/rounds')
        ->assertInertia(fn (Assert $page) => $page->component('vrrr-pha/rounds')->where('game.kind', 'ladder'));
    $this->actingAs($this->platformAdmin)
        ->get('/kulipi-kuna/rounds/7')
        ->assertInertia(fn (Assert $page) => $page->component('vrrr-pha/round-detail')->where('game.kind', 'ladder'));
});

it('serves RTP through the shared page with the ladder nouns and the depth histogram', function () {
    kulipiEngine($this->tenant->uuid);
    $this->actingAs($this->platformAdmin)
        ->get('/kulipi-kuna/rtp?tenant_uuid='.$this->tenant->uuid)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('vrrr-pha/rtp')
            ->where('game.terms.one', 'ladder')
            ->where('rtp.bets_placed', 5000)
            ->has('rtp.depth_histogram', 3));
});

it('never runs round verify on a Kulipi round page', function () {
    kulipiEngine($this->tenant->uuid, [
        'kulipi.test/api/admin/rounds/7/bets*' => Http::response(['data' => [], 'meta' => ['limit' => 500, 'offset' => 0, 'total' => 0]]),
        'kulipi.test/api/admin/rounds/7/verify' => Http::response(['message' => 'must not be called'], 500),
        'kulipi.test/api/admin/rounds/7' => Http::response(['id' => 7, 'sequence' => 7, 'tenant_uuid' => $this->tenant->uuid, 'state' => 'SETTLED']),
    ]);
    $this->actingAs($this->platformAdmin)
        ->get('/kulipi-kuna/rounds/7')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('vrrr-pha/round-detail')->where('verify', null));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/verify'));
});

it('keeps the consoles for platform admins only', function () {
    $tenantAdmin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $tenantAdmin->assignRole('tenant_admin', $this->tenant->id);
    foreach (['/kulipi-kuna/riding', '/kulipi-kuna/rtp', '/kulipi-kuna/ladder', '/kulipi-kuna/rounds'] as $url) {
        $this->actingAs($tenantAdmin)->get($url)->assertForbidden();
    }
});
