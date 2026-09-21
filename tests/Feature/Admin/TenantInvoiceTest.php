<?php

use App\Models\Game;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * The reseller invoice sums activity across every game backend, so a
 * tenant whose play is on Vrrr Pha is billed for it (it used to ask
 * Chinga Fantasy only and print N$0.00).
 */
beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    config([
        'app.url' => 'http://sso.test',
        'services.sso_internal.client_id' => 'internal-id',
        'services.sso_internal.client_secret' => 'internal-secret',
    ]);

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('platform_admin');

    $this->tenant = Tenant::factory()->create([
        'name' => 'Lucky Star Betting', 'slug' => 'lucky-star-betting',
        'business_model' => 'reseller', 'revenue_share_pct' => 70, 'tax_pct' => 10,
    ]);

    $this->fantasy = Game::factory()->fantasy()->create(['backend_url' => 'http://fantasy.test']);
    $this->crash = Game::factory()->create(['name' => 'Vrrr Pha', 'slug' => 'vrrr-pha', 'backend_url' => 'http://crash.test']);
});

function fakeInvoiceEngines(bool $crashDown = false): void
{
    Http::fake([
        'sso.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        // Fantasy: nothing for this tenant this month, by slug or by uuid.
        'fantasy.test/api/admin/stats/summary*' => Http::response(['total_wagered' => 0, 'total_paid_out' => 0, 'bets_placed' => 0, 'active_players' => 0]),
        'crash.test/api/admin/stats/summary*' => $crashDown
            ? Http::response('down', 503)
            : Http::response(['total_wagered' => '1000.00', 'total_paid_out' => '900.00', 'bets_placed' => 40, 'active_players' => 3]),
    ]);
}

test('the invoice bills Vrrr Pha activity with the tenant split applied', function () {
    fakeInvoiceEngines();

    $html = $this->actingAs($this->platformAdmin)
        ->get('/tenant-overview/'.$this->tenant->uuid.'/invoice?from=2026-09-01&to=2026-09-21')
        ->assertOk()
        ->getContent();

    // GGR 100 → tax 10 → NGR 90 → tenant 63 → platform 27.
    expect($html)->toContain('Vrrr Pha')
        ->toContain('N$1,000.00')
        ->toContain('N$900.00')
        ->toContain('N$100.00')
        ->toContain('N$27.00')
        ->not->toContain('Incomplete');

    // Vrrr Pha is asked by uuid, never by slug (PRD §4.3).
    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'http://crash.test/') && $r->data()['tenant_uuid'] === $this->tenant->uuid);
    Http::assertNotSent(fn ($r) => str_starts_with($r->url(), 'http://crash.test/') && $r->data()['tenant_uuid'] === 'lucky-star-betting');
});

test('a backend that is down is listed as unavailable and the invoice is marked incomplete', function () {
    fakeInvoiceEngines(crashDown: true);

    $html = $this->actingAs($this->platformAdmin)
        ->get('/tenant-overview/'.$this->tenant->uuid.'/invoice?from=2026-09-01&to=2026-09-21')
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Incomplete')
        ->toContain('unavailable')
        ->toContain('N$0.00');
});

test('a tenant admin of another tenant cannot open the invoice', function () {
    fakeInvoiceEngines();
    $other = Tenant::factory()->create(['slug' => 'other']);
    $admin = User::factory()->create(['tenant_id' => $other->id]);
    $admin->assignRole('tenant_admin', $other->id);

    $this->actingAs($admin)
        ->get('/tenant-overview/'.$this->tenant->uuid.'/invoice')
        ->assertForbidden();
});
