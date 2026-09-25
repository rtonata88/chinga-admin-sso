<?php

use App\Models\Game;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/** The provably-fair consoles serve Chinga Fantasy v2 under /fantasy, labelled and linked as Fantasy. */
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
    $this->fantasy = Game::factory()->fantasy()->create(['backend_url' => 'http://fantasy.test']);
    $this->fantasy->tenants()->attach($this->tenant->id, ['enabled' => true]);
});

test('exposure and RTP consoles read the v2 engine and carry the Fantasy name and links', function () {
    Http::fake([
        'sso.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        'fantasy.test/api/admin/exposure*' => Http::response(['data' => [
            ['round_id' => 3, 'sequence' => 3, 'tenant_uuid' => $this->tenant->uuid, 'state' => 'BETTING', 'staked' => '120.00', 'open_stake' => '120.00',
                'open_bets' => 4, 'max_exposure' => '4200.00', 'stake_cap' => '25000.00', 'stake_cap_used' => '0.0048', 'alert' => false],
        ]]),
        'fantasy.test/api/admin/rtp*' => Http::response([
            'period' => [], 'bets_placed' => 2400, 'rounds' => 900, 'total_wagered' => '24000.00', 'total_paid_out' => '21480.00',
            'realised_rtp' => '0.8950', 'theoretical_rtp' => '0.9000', 'house_edge' => '0.1000', 'winner_histogram' => [['winners' => 23, 'rounds' => 90]],
        ]),
        'fantasy.test/api/admin/stats/by-day*' => Http::response(['days' => []]),
    ]);

    $this->actingAs($this->platformAdmin)->get('/fantasy/exposure')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('vrrr-pha/exposure')->where('game.name', 'Chinga Fantasy')->where('game.base', '/fantasy')->has('rows', 1));
    $this->actingAs($this->platformAdmin)->get('/fantasy/rtp')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('vrrr-pha/rtp')->where('game.name', 'Chinga Fantasy')->where('rtp.realised_rtp', '0.8950'));
    $this->actingAs($this->platformAdmin)->get('/vrrr-pha/exposure')->assertStatus(200);
});

test('the RTP drift command warns on a large deviation over a big sample and stays quiet on a small one', function () {
    // One fake with a response sequence: stubs merge and the first match wins, so a second fake() cannot override the first.
    Http::fake([
        'sso.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        'fantasy.test/api/admin/rtp*' => Http::sequence()
            ->push(['bets_placed' => 5000, 'realised_rtp' => '0.8400', 'theoretical_rtp' => '0.9000'])
            ->push(['bets_placed' => 120, 'realised_rtp' => '0.7000', 'theoretical_rtp' => '0.9000']),
    ]);
    $this->artisan('games:rtp-drift', ['--days' => 7])
        ->expectsOutputToContain('DRIFT Chinga Fantasy: realised 84.00% vs theoretical 90.00% (-6.00 pts, 5000 bets)')
        ->assertExitCode(1)
        ->run();
    $this->artisan('games:rtp-drift')
        ->expectsOutputToContain('sample too small to judge')
        ->assertExitCode(0)
        ->run();
});
