<?php

use App\Models\Game;
use App\Models\Tenant;
use App\Models\TenantRevenueRecord;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    config([
        'app.url' => 'http://sso.test',
        'services.sso_internal.client_id' => 'internal-id',
        'services.sso_internal.client_secret' => 'internal-secret',
    ]);

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('platform_admin');

    $this->tenant = Tenant::factory()->create(['slug' => 'alpha', 'business_model' => 'reseller', 'revenue_share_pct' => 70, 'tax_pct' => 0]);
    $this->other = Tenant::factory()->create(['slug' => 'other', 'business_model' => 'reseller', 'revenue_share_pct' => 70, 'tax_pct' => 0]);
    $this->tenantAdmin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenantAdmin->assignRole('tenant_admin', $this->tenant->id);

    $this->fantasy = Game::factory()->fantasy()->create(['backend_url' => 'http://fantasy.test']);
    $this->crash = Game::factory()->create(['name' => 'Vrrr Pha', 'slug' => 'vrrr-pha', 'backend_url' => 'http://crash.test']);
    foreach ([$this->fantasy, $this->crash] as $game) {
        $game->tenants()->attach($this->tenant->id, ['enabled' => true]);
    }
    $this->crash->tenants()->attach($this->other->id, ['enabled' => true]);
});

function fakeStats(array $tenant, array $other, bool $crashDown = false): void
{
    // Fantasy keys tenants by SLUG (its known quirk); the crash engine by real UUID.
    $fantasyRows = [
        ['tenant_uuid' => $tenant['slug'], 'total_wagered' => 1000, 'total_paid_out' => 900, 'bets_placed' => 10, 'active_players' => 3],
        ['tenant_uuid' => $other['slug'], 'total_wagered' => 200, 'total_paid_out' => 100, 'bets_placed' => 2, 'active_players' => 1],
    ];
    $crashRows = [
        ['tenant_uuid' => $tenant['uuid'], 'total_wagered' => 500, 'total_paid_out' => 400, 'bets_placed' => 5, 'active_players' => 2],
    ];
    $summary = fn (array $rows) => function ($request) use ($rows) {
        $key = $request->data()['tenant_uuid'] ?? null;
        foreach ($rows as $row) {
            if ($row['tenant_uuid'] === $key) {
                return Http::response($row);
            }
        }

        return Http::response(['total_wagered' => 0, 'total_paid_out' => 0, 'bets_placed' => 0]);
    };

    Http::fake([
        'sso.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        'fantasy.test/api/admin/stats/by-tenant*' => Http::response(['tenants' => $fantasyRows]),
        'fantasy.test/api/admin/stats/summary*' => $summary($fantasyRows),
        'crash.test/api/admin/stats/by-tenant*' => $crashDown ? Http::response('boom', 500) : Http::response(['tenants' => $crashRows]),
        'crash.test/api/admin/stats/summary*' => $crashDown ? Http::response('boom', 500) : $summary($crashRows),
    ]);
}

test('platform admin revenue summary aggregates every game and lists one per_game row each', function () {
    fakeStats($this->tenant->toArray(), $this->other->toArray());

    $data = $this->actingAs($this->platformAdmin)
        ->getJson('/api/v1/admin/revenue/summary')
        ->assertOk()
        ->json('data');

    expect((float) $data['totals']['total_bets'])->toBe(1700.0)
        ->and((float) $data['totals']['total_wins'])->toBe(1400.0)
        ->and((float) $data['totals']['gross_gaming_revenue'])->toBe(300.0);

    $perGame = collect($data['per_game'])->keyBy('game.uuid');
    expect($perGame)->toHaveCount(2)
        ->and((float) $perGame[$this->fantasy->uuid]['total_bets'])->toBe(1200.0)
        ->and((float) $perGame[$this->crash->uuid]['total_bets'])->toBe(500.0)
        ->and($perGame[$this->crash->uuid]['game']['name'])->toBe('Vrrr Pha');
});

test('tenant admin revenue summary queries each game with the right tenant key', function () {
    fakeStats($this->tenant->toArray(), $this->other->toArray());

    $data = $this->actingAs($this->tenantAdmin)
        ->getJson('/api/v1/admin/revenue/summary')
        ->assertOk()
        ->json('data');

    expect((float) $data['totals']['total_bets'])->toBe(1500.0)
        ->and(collect($data['per_game'])->pluck('total_bets')->map(fn ($v) => (float) $v)->sort()->values()->all())->toBe([500.0, 1000.0]);

    // The crash engine stores real UUIDs and must never be asked by slug.
    Http::assertNotSent(fn ($r) => str_starts_with($r->url(), 'http://crash.test')
        && ($r->data()['tenant_uuid'] ?? null) === $this->tenant->slug);
    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'http://crash.test/api/admin/stats/summary')
        && ($r->data()['tenant_uuid'] ?? null) === $this->tenant->uuid);
});

test('a backend that is down contributes zeros and an error flag instead of failing the summary', function () {
    fakeStats($this->tenant->toArray(), $this->other->toArray(), crashDown: true);

    $data = $this->actingAs($this->platformAdmin)
        ->getJson('/api/v1/admin/revenue/summary')
        ->assertOk()
        ->json('data');

    expect((float) $data['totals']['total_bets'])->toBe(1200.0);
    $perGame = collect($data['per_game'])->keyBy('game.uuid');
    expect($perGame[$this->crash->uuid]['error'])->not->toBeNull()
        ->and((float) $perGame[$this->crash->uuid]['total_bets'])->toBe(0.0)
        ->and($perGame[$this->fantasy->uuid]['error'] ?? null)->toBeNull();
});

test('tenant overview sums games per tenant and adds a by_game breakdown', function () {
    fakeStats($this->tenant->toArray(), $this->other->toArray());

    $this->actingAs($this->platformAdmin)
        ->get('/tenant-overview')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/dashboard')
            ->has('tenants', 2)
            ->where('kpis.total_wagered', fn ($v) => (float) $v === 1700.0)
            ->has('by_game', 2)
            ->where('by_game.0.game_name', 'Chinga Fantasy')
            ->where('by_game.0.total_wagered', fn ($v) => (float) $v === 1200.0)
            ->where('by_game.1.game_name', 'Vrrr Pha')
            ->where('by_game.1.total_wagered', fn ($v) => (float) $v === 500.0)
        );

    $this->actingAs($this->tenantAdmin)
        ->get('/tenant-overview')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('tenants', 1)
            ->where('tenants.0.total_wagered', fn ($v) => (float) $v === 1500.0)
            ->where('kpis.total_wagered', fn ($v) => (float) $v === 1500.0)
            ->has('by_game', 2)
            ->where('by_game.0.total_wagered', fn ($v) => (float) $v === 1000.0)
        );
});

test('platform revenue summary groups stored records by game', function () {
    foreach ([[$this->fantasy, 300], [$this->crash, 120]] as [$game, $bets]) {
        TenantRevenueRecord::create([
            'tenant_id' => $this->tenant->id,
            'game_id' => $game->id,
            'period_type' => 'daily',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->startOfMonth()->toDateString(),
            'total_bets' => $bets,
            'total_wins' => $bets / 2,
            'gross_gaming_revenue' => $bets / 2,
            'business_model' => 'reseller',
            'revenue_share_pct' => 70,
            'chinga_share' => $bets * 0.15,
            'tenant_share' => $bets * 0.35,
        ]);
    }

    $byGame = collect($this->actingAs($this->platformAdmin)
        ->getJson('/api/v1/platform/revenue/summary')
        ->assertOk()
        ->json('by_game'))->keyBy('game.uuid');

    expect($byGame)->toHaveCount(2)
        ->and((float) $byGame[$this->fantasy->uuid]['total_bets'])->toBe(300.0)
        ->and((float) $byGame[$this->crash->uuid]['total_bets'])->toBe(120.0)
        ->and($byGame[$this->crash->uuid]['game']['name'])->toBe('Vrrr Pha');
});
