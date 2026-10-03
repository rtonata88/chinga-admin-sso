<?php

use App\Contracts\ProvablyFairAdminClient;
use App\Models\Game;
use App\Models\Tenant;
use App\Models\User;
use App\Services\GameAdminClientFactory;
use App\Services\KulipiKunaAdminClient;
use Illuminate\Support\Facades\Http;

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
            'tenant_uuid' => $tenantUuid, 'total_riding' => '20160.00', 'cap' => '25000.00', 'used' => '0.8064', 'alert' => true,
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
