<?php

use App\Models\CompanyProfile;
use App\Models\Game;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/** The platform's company details: one row, platform admins only, printed on invoices. */
beforeEach(function () {
    $this->withoutVite();
    $this->seed(\Database\Seeders\RbacSeeder::class);
    config(['app.url' => 'http://sso.test', 'services.sso_internal.client_id' => 'id', 'services.sso_internal.client_secret' => 'secret']);

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('platform_admin');
    $this->tenant = Tenant::factory()->create(['name' => 'Lucky Star Betting', 'slug' => 'lucky-star-betting', 'business_model' => 'reseller', 'revenue_share_pct' => 70, 'tax_pct' => 0]);
    $this->tenantAdmin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenantAdmin->assignRole('tenant_admin', $this->tenant->id);
});

test('a platform admin saves the company details and sees them back', function () {
    $this->actingAs($this->platformAdmin)->get('/platform/company')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('platform/company')->where('profile.payment_terms_days', 14));

    $this->actingAs($this->platformAdmin)->put('/platform/company', [
        'legal_name' => 'Chinga Games (Pty) Ltd', 'trading_name' => 'Chinga Games', 'registration_number' => '2024/0001',
        'vat_number' => 'NA-VAT-99', 'address_line1' => '12 Independence Ave', 'city' => 'Windhoek', 'country' => 'Namibia',
        'email' => 'accounts@playchinga.com', 'phone' => '+264 61 000 000',
        'bank_name' => 'Bank Windhoek', 'bank_account_name' => 'Chinga Games (Pty) Ltd', 'bank_account_number' => '8001234567', 'bank_branch_code' => '481972',
        'payment_terms_days' => 30,
    ])->assertRedirect('/platform/company')->assertSessionHas('success');

    $c = CompanyProfile::current();
    expect($c->legal_name)->toBe('Chinga Games (Pty) Ltd')
        ->and($c->payment_terms_days)->toBe(30)
        ->and($c->updated_by)->toBe($this->platformAdmin->id)
        ->and(CompanyProfile::count())->toBe(1);
});

test('a tenant admin cannot open or change the company details', function () {
    $this->actingAs($this->tenantAdmin)->get('/platform/company')->assertForbidden();
    $this->actingAs($this->tenantAdmin)->put('/platform/company', ['payment_terms_days' => 7])->assertForbidden();
});

test('the invoice prints the company identity, bank details and payment terms', function () {
    CompanyProfile::current()->update([
        'legal_name' => 'Chinga Games (Pty) Ltd', 'registration_number' => '2024/0001', 'vat_number' => 'NA-VAT-99',
        'address_line1' => '12 Independence Ave', 'city' => 'Windhoek', 'country' => 'Namibia', 'email' => 'accounts@playchinga.com',
        'bank_name' => 'Bank Windhoek', 'bank_account_name' => 'Chinga Games (Pty) Ltd', 'bank_account_number' => '8001234567', 'bank_branch_code' => '481972',
        'payment_terms_days' => 30,
    ]);
    Game::factory()->fantasy()->create(['backend_url' => 'http://fantasy.test']);
    Http::fake([
        'sso.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        'fantasy.test/api/admin/stats/summary*' => Http::response(['total_wagered' => '1000.00', 'total_paid_out' => '900.00', 'bets_placed' => 40, 'active_players' => 3]),
    ]);

    $html = $this->actingAs($this->platformAdmin)->get('/tenant-overview/'.$this->tenant->uuid.'/invoice?from=2026-09-01&to=2026-09-30')->assertOk()->getContent();

    expect($html)->toContain('Chinga Games (Pty) Ltd')
        ->toContain('Reg. no. 2024/0001')
        ->toContain('VAT no. NA-VAT-99')
        ->toContain('12 Independence Ave')
        ->toContain('Bank Windhoek')
        ->toContain('8001234567')
        ->toContain('30 days of the issue date')
        ->toContain('accounts@playchinga.com')
        ->not->toContain('platform@playchinga.com');
});
