<?php

use App\Models\Game;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * /dashboard "Live activity" reads every game backend, not Fantasy alone:
 * KPIs are summed, recent bets are merged newest-first in one shape, and
 * a game that is down is named rather than dropped silently.
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
    $this->player = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Maria Shikongo']);

    $this->fantasy = Game::factory()->fantasy()->create(['backend_url' => 'http://fantasy.test']);
    $this->crash = Game::factory()->create(['name' => 'Vrrr Pha', 'slug' => 'vrrr-pha', 'backend_url' => 'http://crash.test']);
    foreach ([$this->fantasy, $this->crash] as $game) {
        $game->tenants()->attach($this->tenant->id, ['enabled' => true]);
    }
});

function fakeFeeds(array $tenant, bool $crashDown = false): void
{
    $summary = fn (int $bets, string $wagered, string $paid, int $players) => fn ($request) => Http::response([
        'bets_placed' => $bets, 'active_players' => $players, 'total_wagered' => $wagered, 'total_paid_out' => $paid,
        'ggr' => number_format((float) $wagered - (float) $paid, 2, '.', ''),
    ]);
    Http::fake([
        'sso.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        'fantasy.test/api/admin/stats/summary*' => $summary(10, '1000.00', '900.00', 3),
        'fantasy.test/api/admin/stats/by-day*' => Http::response(['days' => [['day' => '2026-09-20', 'total_wagered' => '500.00'], ['day' => '2026-09-21', 'total_wagered' => '1000.00']]]),
        'fantasy.test/api/admin/bets/recent*' => Http::response(['data' => [
            ['id' => 1, 'user_uuid' => test()->player->uuid, 'tenant_uuid' => $tenant['slug'], 'round_number' => 12, 'team_names' => ['Kaizer Chiefs', 'Pirates', 'Sundowns'],
                'bet_amount' => '20.00', 'combined_odds' => '4.50', 'potential_payout' => '90.00', 'outcome' => 'pending', 'placed_at' => '2026-09-21T10:00:00Z'],
        ]]),
        'crash.test/api/admin/stats/summary*' => $crashDown ? Http::response('down', 503) : $summary(40, '2000.00', '1500.00', 2),
        'crash.test/api/admin/stats/by-day*' => Http::response(['days' => [['day' => '2026-09-21', 'total_wagered' => '2000.00']]]),
        'crash.test/api/admin/bets/recent*' => Http::response(['data' => [
            ['id' => 700, 'user_uuid' => test()->player->uuid, 'tenant_uuid' => $tenant['uuid'], 'sequence' => 9001, 'stake' => '50.00', 'auto_cashout_target' => null,
                'cashout_multiplier' => '2.45', 'payout' => '122.50', 'outcome' => 'cashed', 'crash_point' => '3.10', 'placed_at' => '2026-09-21T10:05:00Z'],
            ['id' => 699, 'user_uuid' => test()->player->uuid, 'tenant_uuid' => $tenant['uuid'], 'sequence' => 9000, 'stake' => '50.00', 'auto_cashout_target' => '5.00',
                'cashout_multiplier' => null, 'payout' => '0.00', 'outcome' => 'busted', 'crash_point' => '1.20', 'placed_at' => '2026-09-21T09:00:00Z'],
        ]]),
    ]);
}

test('live activity sums every game and merges recent bets newest-first', function () {
    fakeFeeds($this->tenant->toArray());

    $this->actingAs($this->platformAdmin)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('wager_stats.today.bets_placed', 50)
            ->where('wager_stats.today.active_players', 5)
            ->where('wager_stats.today.total_wagered', '3000.00')
            ->where('wager_stats.today.total_paid_out', '2400.00')
            ->where('wager_stats.today.ggr', '600.00')
            ->where('wager_spark', fn ($v) => collect($v)->map(fn ($x) => (float) $x)->all() === [500.0, 3000.0])
            ->has('per_game', 2)
            ->where('per_game.1.game_name', 'Vrrr Pha')
            ->where('per_game.1.ggr', fn ($x) => (float) $x === 500.0)
            ->has('recent_bets', 3)
            ->where('recent_bets.0.game_name', 'Vrrr Pha')
            ->where('recent_bets.0.detail', 'Cashed out at 2.45×')
            ->where('recent_bets.0.outcome', 'win')
            ->where('recent_bets.0.bet_amount', fn ($x) => (float) $x === 50.0)
            ->where('recent_bets.0.potential_payout', fn ($x) => (float) $x === 122.5)
            ->where('recent_bets.0.round_number', 9001)
            ->where('recent_bets.0.player.name', 'Maria Shikongo')
            ->where('recent_bets.0.tenant_name', 'Lucky Star Betting')
            ->where('recent_bets.1.game_name', 'Chinga Fantasy')
            ->where('recent_bets.1.detail', 'Kaizer Chiefs, Pirates +1')
            ->where('recent_bets.1.tenant_name', 'Lucky Star Betting')
            ->where('recent_bets.2.detail', 'Crashed at 1.20×')
            ->where('recent_bets.2.outcome', 'lost')
            ->where('feed_errors', [])
        );
});

test('a tenant admin gets their tenant, keyed by slug for Fantasy and uuid for Vrrr Pha', function () {
    fakeFeeds($this->tenant->toArray());
    app()->instance('current_tenant', $this->tenant);

    $this->actingAs($this->tenantAdmin)->get('/dashboard')->assertOk();

    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'http://fantasy.test/api/admin/stats/summary') && $r->data()['tenant_uuid'] === 'lucky-star-betting');
    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'http://crash.test/api/admin/stats/summary') && $r->data()['tenant_uuid'] === $this->tenant->uuid);
    Http::assertNotSent(fn ($r) => str_starts_with($r->url(), 'http://crash.test/') && $r->data()['tenant_uuid'] === 'lucky-star-betting');
});

test('a game that is down is named and the rest still shows', function () {
    fakeFeeds($this->tenant->toArray(), crashDown: true);

    $this->actingAs($this->platformAdmin)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('wager_stats.today.bets_placed', 10)
            ->has('recent_bets', 1)
            ->where('feed_errors', ['Vrrr Pha'])
        );
});
