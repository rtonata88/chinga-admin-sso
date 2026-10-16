<?php

use App\Models\Game;
use App\Models\Tenant;
use App\Models\TenantInvoice;
use App\Models\TenantRevenueRecord;
use App\Models\User;
use App\Models\Venue;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Platform consoles never add different currencies together: a cross-tenant
 * total is one figure per currency (no exchange rate), and every per-tenant
 * figure carries its tenant's currency.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->travelTo('2026-10-16 12:00:00');

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('platform_admin');

    $this->nad = Tenant::factory()->create(['name' => 'Lucky Star Betting', 'slug' => 'lucky-star-betting', 'currency' => 'NAD', 'business_model' => 'reseller']);
    $this->nad2 = Tenant::factory()->create(['name' => 'Chinga Direct', 'slug' => 'chinga-direct', 'currency' => 'NAD', 'business_model' => 'direct']);
    $this->bwp = Tenant::factory()->create(['name' => 'Orange BW Demo', 'slug' => 'orange-bw-demo', 'currency' => 'BWP', 'business_model' => 'reseller']);
    $this->game = Game::factory()->fantasy()->create();
});

function revenue(Tenant $tenant, Game $game, string $bets, string $wins, string $chinga, string $start = '2026-10-01'): TenantRevenueRecord
{
    $ggr = bcsub($bets, $wins, 2);

    return TenantRevenueRecord::create([
        'tenant_id' => $tenant->id, 'game_id' => $game->id, 'period_type' => 'daily',
        'period_start' => $start, 'period_end' => '2026-10-02',
        'total_bets' => $bets, 'total_wins' => $wins, 'gross_gaming_revenue' => $ggr,
        'tax_pct' => '0.00', 'tax_amount' => '0.00', 'net_gaming_revenue' => $ggr,
        'business_model' => $tenant->business_model, 'revenue_share_pct' => '0.00',
        'chinga_share' => $chinga, 'tenant_share' => bcsub($ggr, $chinga, 2), 'status' => 'calculated',
    ]);
}

test('the platform dashboard shows this month\'s revenue per currency, never one mixed figure', function () {
    revenue($this->nad, $this->game, '1000.00', '600.00', '120.00');
    revenue($this->nad2, $this->game, '500.10', '100.00', '400.10');
    revenue($this->bwp, $this->game, '300.00', '250.00', '15.00');

    $data = $this->actingAs($this->platformAdmin)->getJson('/api/v1/platform/dashboard')->assertOk()->json('data');

    expect($data)->not->toHaveKey('revenue_this_month')->not->toHaveKey('chinga_share_this_month')
        ->and($data['revenue_this_month_by_currency'])->toBe([
            ['currency' => 'BWP', 'gross_gaming_revenue' => '50.00', 'chinga_share' => '15.00'],
            ['currency' => 'NAD', 'gross_gaming_revenue' => '800.10', 'chinga_share' => '520.10'],
        ]);
});

test('with no revenue the dashboard has no per-currency figures (the page shows a dash)', function () {
    $data = $this->actingAs($this->platformAdmin)->getJson('/api/v1/platform/dashboard')->assertOk()->json('data');

    expect($data['revenue_this_month_by_currency'])->toBe([]);
});

test('the revenue summary totals per currency and splits each game per currency', function () {
    $crash = Game::factory()->create(['name' => 'Vrrr Pha', 'slug' => 'vrrr-pha']);
    revenue($this->nad, $this->game, '1000.00', '600.00', '120.00');
    revenue($this->nad2, $this->game, '200.00', '100.00', '100.00');
    revenue($this->bwp, $this->game, '300.00', '250.00', '15.00');
    revenue($this->bwp, $crash, '80.00', '20.00', '6.00');

    $res = $this->actingAs($this->platformAdmin)
        ->getJson('/api/v1/platform/revenue/summary?from=2026-10-01&to=2026-10-16')
        ->assertOk()->json();

    expect($res['totals'])->toHaveCount(2)
        ->and($res['totals'][0])->toMatchArray(['currency' => 'BWP', 'total_bets' => '380.00', 'gross_gaming_revenue' => '110.00', 'chinga_share' => '21.00'])
        ->and($res['totals'][1])->toMatchArray(['currency' => 'NAD', 'total_bets' => '1200.00', 'gross_gaming_revenue' => '500.00', 'chinga_share' => '220.00']);

    $perTenant = collect($res['per_tenant'])->keyBy(fn ($r) => $r['tenant']['name']);
    expect($perTenant['Orange BW Demo']['currency'])->toBe('BWP')
        ->and($perTenant['Orange BW Demo']['total_bets'])->toBe('380.00')
        ->and($perTenant['Lucky Star Betting']['currency'])->toBe('NAD');

    // Fantasy was played in NAD and BWP: two rows, never one added together.
    $fantasy = collect($res['by_game'])->where('game_id', $this->game->id)->values();
    expect($fantasy)->toHaveCount(2)
        ->and($fantasy->pluck('currency')->all())->toBe(['BWP', 'NAD'])
        ->and($fantasy[0]['total_bets'])->toBe('300.00')
        ->and($fantasy[1]['total_bets'])->toBe('1200.00');
});

test('revenue records carry their tenant\'s currency', function () {
    revenue($this->bwp, $this->game, '300.00', '250.00', '15.00');

    $this->actingAs($this->platformAdmin)->getJson('/api/v1/platform/revenue')
        ->assertOk()
        ->assertJsonPath('data.0.currency', 'BWP')
        ->assertJsonPath('data.0.tenant.currency', 'BWP');
});

test('invoice totals are per currency and the printable invoice is in its own currency', function () {
    $invoice = fn (Tenant $t, string $n, string $due, string $paid, string $dueAt) => TenantInvoice::create([
        'number' => $n, 'tenant_id' => $t->id, 'period_from' => '2026-09-01', 'period_to' => '2026-09-30',
        'currency' => $t->currency, 'status' => bccomp($paid, '0', 2) > 0 ? 'part_paid' : 'issued',
        'ggr' => $due, 'ngr' => $due, 'amount_due' => $due, 'amount_paid' => $paid,
        'games' => [], 'unavailable' => [], 'issued_at' => '2026-10-01 09:00:00', 'due_at' => $dueAt,
    ]);
    $invoice($this->nad, 'INV-NAD-1', '270.00', '100.00', '2026-10-30');
    $invoice($this->nad2, 'INV-NAD-2', '30.50', '0.00', '2026-10-10');
    $bw = $invoice($this->bwp, 'INV-BWP-1', '1184.60', '0.00', '2026-10-10');

    $this->actingAs($this->platformAdmin)->get('/invoices')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('invoices/index')
        ->where('totals.count', 3)
        ->where('totals.by_currency', [
            ['currency' => 'BWP', 'outstanding' => '1184.60', 'overdue' => '1184.60', 'paid' => '0.00'],
            ['currency' => 'NAD', 'outstanding' => '200.50', 'overdue' => '30.50', 'paid' => '100.00'],
        ])
        ->missing('totals.outstanding')
    );

    $html = $this->actingAs($this->platformAdmin)->get('/invoices/'.$bw->number)->assertOk()->getContent();
    expect($html)->toContain('P1,184.60')->not->toContain('N$');
});

test('the treasury keeps a separate position per currency', function () {
    $nadPlayer = User::factory()->create(['tenant_id' => $this->nad->id]);
    $nadPlayer->getOrCreateWallet()->update(['balance' => '1000.00']);
    $bwPlayer = User::factory()->create(['tenant_id' => $this->bwp->id]);
    $bwWallet = $bwPlayer->getOrCreateWallet();
    $bwWallet->update(['balance' => '250.00']);
    expect($bwWallet->currency)->toBe('BWP');

    \App\Models\TreasurySetting::current('NAD')->update(['bank_balance' => '1500.00']);
    \App\Models\TreasurySetting::current('BWP')->update(['bank_balance' => '100.00']);

    $this->actingAs($this->platformAdmin)->get('/platform/treasury')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('platform/treasury')
        ->has('positions', 2)
        ->where('positions.0.currency', 'BWP')
        ->where('positions.0.summary.owed', '250.00')
        ->where('positions.0.summary.surplus', '-150.00')
        ->where('positions.0.summary.covered', false)
        ->where('positions.1.currency', 'NAD')
        ->where('positions.1.summary.owed', '1000.00')
        ->where('positions.1.summary.surplus', '500.00')
        ->where('positions.1.summary.covered', true)
        ->missing('summary')
    );

    // Saving one currency's inputs leaves the other's alone.
    $this->actingAs($this->platformAdmin)
        ->put('/platform/treasury', ['currency' => 'BWP', 'bank_balance' => '900.00', 'variance_reserve' => '0', 'tax_pct' => '0'])
        ->assertRedirect('/platform/treasury');
    expect((string) \App\Models\TreasurySetting::current('BWP')->bank_balance)->toBe('900.00')
        ->and((string) \App\Models\TreasurySetting::current('NAD')->bank_balance)->toBe('1500.00');
});

test('a venue is always in its tenant\'s currency', function () {
    $payload = [
        'name' => 'Gaborone Main', 'slug' => 'gaborone-main', 'address_line_1' => 'Plot 1',
        'city' => 'Gaborone', 'country_code' => 'BW',
    ];

    $this->actingAs($this->platformAdmin)
        ->postJson("/api/v1/platform/tenants/{$this->bwp->uuid}/venues", $payload + ['currency' => 'NAD'])
        ->assertUnprocessable()->assertJsonValidationErrors('currency');

    $uuid = $this->actingAs($this->platformAdmin)
        ->postJson("/api/v1/platform/tenants/{$this->bwp->uuid}/venues", $payload)
        ->assertCreated()->json('data.uuid');

    expect(Venue::withoutGlobalScopes()->where('uuid', $uuid)->value('currency'))->toBe('BWP');
    $this->actingAs($this->platformAdmin)->getJson("/api/v1/platform/tenants/{$this->bwp->uuid}/venues/{$uuid}")
        ->assertOk()->assertJsonPath('data.stats.currency', 'BWP');
});
