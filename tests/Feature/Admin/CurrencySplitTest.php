<?php

use App\Models\Game;
use App\Models\Tenant;
use App\Models\User;
use App\Support\RevenueSplit;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Money is split by currency and never added across currencies (no FX):
 * a tenant's users see their figures in their tenant's currency, and a
 * platform total is one figure per currency.
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

    $this->nad = Tenant::factory()->create(['slug' => 'lucky-star-betting', 'currency' => 'NAD', 'business_model' => 'reseller', 'revenue_share_pct' => 70, 'tax_pct' => 0]);
    $this->bwp = Tenant::factory()->create(['slug' => 'orange-bw-demo', 'currency' => 'BWP', 'business_model' => 'reseller', 'revenue_share_pct' => 50, 'tax_pct' => 0]);
    $this->bwpAdmin = User::factory()->create(['tenant_id' => $this->bwp->id]);
    $this->bwpAdmin->assignRole('tenant_admin', $this->bwp->id);

    $this->fantasy = Game::factory()->fantasy()->create(['backend_url' => 'http://fantasy.test']);
    $this->crash = Game::factory()->create(['name' => 'Vrrr Pha', 'slug' => 'vrrr-pha', 'backend_url' => 'http://crash.test']);
    foreach ([$this->fantasy, $this->crash] as $game) {
        $game->tenants()->attach([$this->nad->id => ['enabled' => true], $this->bwp->id => ['enabled' => true]]);
    }
});

/** NAD tenant: 1000/900 on Fantasy, 500/400 on Vrrr Pha. BWP tenant: 300/100 on Fantasy only. */
function fakeTwoCurrencies(Tenant $nad, Tenant $bwp): void
{
    $fantasy = [
        ['tenant_uuid' => $nad->uuid, 'total_wagered' => '1000.00', 'total_paid_out' => '900.00', 'bets_placed' => 10, 'active_players' => 3],
        ['tenant_uuid' => $bwp->uuid, 'total_wagered' => '300.00', 'total_paid_out' => '100.00', 'bets_placed' => 4, 'active_players' => 2],
    ];
    $crash = [
        ['tenant_uuid' => $nad->uuid, 'total_wagered' => '500.00', 'total_paid_out' => '400.00', 'bets_placed' => 5, 'active_players' => 2],
    ];
    $summary = fn (array $rows) => function ($request) use ($rows) {
        $key = $request->data()['tenant_uuid'] ?? null;
        if ($key === null) {
            // The cross-tenant summary adds every tenant together, currencies and all — the figure never to show.
            return Http::response(['bets_placed' => array_sum(array_column($rows, 'bets_placed')), 'active_players' => array_sum(array_column($rows, 'active_players')),
                'total_wagered' => number_format(array_sum(array_column($rows, 'total_wagered')), 2, '.', ''), 'total_paid_out' => number_format(array_sum(array_column($rows, 'total_paid_out')), 2, '.', '')]);
        }
        foreach ($rows as $row) {
            if ($row['tenant_uuid'] === $key) {
                return Http::response($row);
            }
        }

        return Http::response(['total_wagered' => '0.00', 'total_paid_out' => '0.00', 'bets_placed' => 0, 'active_players' => 0]);
    };

    Http::fake([
        'sso.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        'fantasy.test/api/admin/stats/by-tenant*' => Http::response(['tenants' => $fantasy]),
        'fantasy.test/api/admin/stats/summary*' => $summary($fantasy),
        'fantasy.test/api/admin/stats/by-day*' => Http::response(['days' => [['day' => '2026-10-16', 'total_wagered' => '1300.00']]]),
        'fantasy.test/api/admin/bets/recent*' => Http::response(['data' => [
            ['id' => 1, 'user_uuid' => null, 'tenant_uuid' => $bwp->uuid, 'round_number' => 3, 'team_names' => ['A', 'B'], 'bet_amount' => '20.00',
                'combined_odds' => '4.50', 'potential_payout' => '90.00', 'outcome' => 'pending', 'placed_at' => '2026-10-16T10:00:00Z'],
        ]]),
        'crash.test/api/admin/stats/by-tenant*' => Http::response(['tenants' => $crash]),
        'crash.test/api/admin/stats/summary*' => $summary($crash),
        'crash.test/api/admin/stats/by-day*' => Http::response(['days' => []]),
        'crash.test/api/admin/bets/recent*' => Http::response(['data' => []]),
    ]);
}

test('a tenant admin on a BWP tenant gets every tenant-overview figure tagged BWP', function () {
    fakeTwoCurrencies($this->nad, $this->bwp);

    $this->actingAs($this->bwpAdmin)
        ->get('/tenant-overview')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/dashboard')
            ->where('scope', 'tenant')
            ->where('kpis.currency', 'BWP')
            ->where('kpis.total_wagered', '300.00')
            ->where('kpis.total_wins', '100.00')
            ->where('kpis.tenant_profit', '100.00')
            ->has('tenants', 1)
            ->where('tenants.0.currency', 'BWP')
            ->has('by_game', 1)
            ->where('by_game.0.currency', 'BWP')
            ->where('by_game.0.total_wagered', '300.00')
        );
});

test('a BWP tenant admin with no play yet sees zeros in BWP, not a dash or NAD', function () {
    Http::fake([
        'sso.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        '*/api/admin/stats/by-tenant*' => Http::response(['tenants' => []]),
        '*/api/admin/stats/summary*' => Http::response(['total_wagered' => '0.00', 'total_paid_out' => '0.00', 'bets_placed' => 0]),
    ]);

    $this->actingAs($this->bwpAdmin)
        ->get('/tenant-overview')
        ->assertInertia(fn (Assert $page) => $page->where('kpis.currency', 'BWP')->where('kpis.total_wagered', '0.00'));

    $totals = $this->actingAs($this->bwpAdmin)->getJson('/api/v1/admin/revenue/summary')->assertOk()->json('data.totals');
    expect($totals)->toBe([[
        'currency' => 'BWP', 'total_bets' => '0.00', 'total_wins' => '0.00', 'gross_gaming_revenue' => '0.00', 'chinga_share' => '0.00', 'tenant_share' => '0.00',
    ]]);
});

test('a BWP tenant admin revenue summary and live activity are in BWP', function () {
    fakeTwoCurrencies($this->nad, $this->bwp);

    $data = $this->actingAs($this->bwpAdmin)->getJson('/api/v1/admin/revenue/summary')->assertOk()->json('data');
    expect($data['totals'])->toHaveCount(1)
        ->and($data['totals'][0]['currency'])->toBe('BWP')
        ->and($data['totals'][0]['total_bets'])->toBe('300.00')
        ->and($data['totals'][0]['tenant_share'])->toBe('100.00')
        ->and(collect($data['per_game'])->pluck('currency')->unique()->all())->toBe(['BWP']);

    app()->instance('current_tenant', $this->bwp);
    $this->actingAs($this->bwpAdmin)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('wager_stats.money_today', 1)
            ->where('wager_stats.money_today.0.currency', 'BWP')
            ->where('wager_stats.money_today.0.total_wagered', '300.00')
            ->where('per_game.0.currency', 'BWP')
            ->where('recent_bets.0.currency', 'BWP')
        );
});

test('platform-scope tenant overview totals are split per currency, never added together', function () {
    fakeTwoCurrencies($this->nad, $this->bwp);

    $this->actingAs($this->platformAdmin)
        ->get('/tenant-overview')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope', 'platform')
            ->has('kpis.totals', 2)
            ->where('kpis.totals.0', ['currency' => 'BWP', 'total_wagered' => '300.00', 'total_wins' => '100.00', 'platform_profit' => '100.00'])
            // NAD: GGR 100 + 100 = 200, platform keeps 30% → 60.00.
            ->where('kpis.totals.1', ['currency' => 'NAD', 'total_wagered' => '1500.00', 'total_wins' => '1300.00', 'platform_profit' => '60.00'])
            ->has('tenants', 2)
            ->where('tenants', fn ($rows) => collect($rows)->pluck('currency', 'tenant_uuid')->all() === [$this->nad->uuid => 'NAD', $this->bwp->uuid => 'BWP'])
            // Fantasy was played in both currencies: one row each, never one 1300 row.
            ->has('by_game', 3)
            ->where('by_game', fn ($rows) => collect($rows)->map(fn ($r) => $r['game_name'].' '.$r['currency'].' '.$r['total_wagered'])->sort()->values()->all()
                === ['Chinga Fantasy BWP 300.00', 'Chinga Fantasy NAD 1000.00', 'Vrrr Pha NAD 500.00'])
        );
});

test('platform-scope revenue summary and live activity split per currency', function () {
    fakeTwoCurrencies($this->nad, $this->bwp);

    $data = $this->actingAs($this->platformAdmin)->getJson('/api/v1/admin/revenue/summary')->assertOk()->json('data');
    expect(collect($data['totals'])->pluck('total_bets', 'currency')->all())->toBe(['BWP' => '300.00', 'NAD' => '1500.00'])
        ->and(collect($data['per_game'])->map(fn ($r) => $r['game']['name'].' '.$r['currency'])->sort()->values()->all())
        ->toBe(['Chinga Fantasy BWP', 'Chinga Fantasy NAD', 'Vrrr Pha NAD']);

    $this->actingAs($this->platformAdmin)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('wager_stats.today.bets_placed', 19)
            ->missing('wager_stats.today.total_wagered')
            ->where('wager_stats.money_today', fn ($t) => collect($t)->pluck('total_wagered', 'currency')->all() === ['BWP' => '300.00', 'NAD' => '1500.00'])
            // A by-day handle is a cross-tenant sum, so it is not drawn across currencies.
            ->where('wager_spark', [])
            ->has('per_game', 3)
            ->where('recent_bets.0.currency', 'BWP')
        );
});

test('platform voucher, wallet and transaction totals are one figure per currency', function () {
    $nadPlayer = User::factory()->create(['tenant_id' => $this->nad->id]);
    $bwpPlayer = User::factory()->create(['tenant_id' => $this->bwp->id]);
    $nadPlayer->getOrCreateWallet('NAD')->update(['balance' => '120.50', 'status' => 'active']);
    $bwpPlayer->getOrCreateWallet('BWP')->update(['balance' => '80.25', 'status' => 'active']);

    $balances = $this->actingAs($this->platformAdmin)->getJson('/api/v1/admin/wallets')->assertOk()->json('stats.balances');
    expect($balances)->toBe([['currency' => 'BWP', 'total_balance' => '80.25'], ['currency' => 'NAD', 'total_balance' => '120.50']]);

    $stats = $this->actingAs($this->platformAdmin)->getJson('/api/v1/admin/wallet-transactions')->assertOk()->json('stats');
    expect($stats['totals'])->toBe([]);
});

test('the commercial split is exact and rounds tax and the tenant share half-up to the cent', function () {
    $tenant = (new Tenant)->forceFill(['business_model' => 'reseller', 'tax_pct' => '10.00', 'revenue_share_pct' => '33.33']);

    // GGR 100.05; tax 10.005 → 10.01; NGR 90.04; share 30.0103… → 30.01; platform 60.03.
    expect(RevenueSplit::compute($tenant, '1000.10', '900.05'))->toBe([
        'ggr' => '100.05', 'tax' => '10.01', 'ngr' => '90.04', 'tenant_profit' => '30.01', 'platform_profit' => '60.03',
    ]);
    // Losing period: no tax, no tenant share; the platform absorbs it.
    expect(RevenueSplit::compute($tenant, '10', '25.5'))->toMatchArray(['ggr' => '-15.50', 'tax' => '0.00', 'tenant_profit' => '0.00', 'platform_profit' => '-15.50']);
});
