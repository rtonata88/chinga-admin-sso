<?php

use App\Models\Game;
use App\Models\Tenant;
use App\Models\TenantInvoice;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Issue freezes the preview into an invoice; payments drive its status;
 * tenant admins read, platform admins act.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->seed(\Database\Seeders\RbacSeeder::class);
    config(['app.url' => 'http://sso.test', 'services.sso_internal.client_id' => 'id', 'services.sso_internal.client_secret' => 'secret']);
    $this->travelTo('2026-10-02 12:00:00');

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('platform_admin');
    $this->tenant = Tenant::factory()->create(['name' => 'Lucky Star Betting', 'slug' => 'lucky-star-betting', 'business_model' => 'reseller', 'revenue_share_pct' => 70, 'tax_pct' => 10]);
    $this->tenantAdmin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenantAdmin->assignRole('tenant_admin', $this->tenant->id);
    $this->other = Tenant::factory()->create(['slug' => 'other', 'business_model' => 'reseller']);
    $this->otherAdmin = User::factory()->create(['tenant_id' => $this->other->id]);
    $this->otherAdmin->assignRole('tenant_admin', $this->other->id);

    Game::factory()->fantasy()->create(['backend_url' => 'http://fantasy.test']);
    // GGR 1000 → tax 100 → NGR 900 → tenant 630 → due 270. Tests swap $this->engine to change the answer:
    // Http::fake stubs are matched in registration order, so a later fake cannot override this one.
    $this->engine = fn () => Http::response(['total_wagered' => '5000.00', 'total_paid_out' => '4000.00', 'bets_placed' => 400, 'active_players' => 30]);
    Http::fake([
        'sso.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        'fantasy.test/api/admin/stats/summary*' => fn () => (test()->engine)(),
    ]);
});

function issueInvoice($test): TenantInvoice
{
    $test->actingAs($test->platformAdmin)
        ->post('/tenant-overview/'.$test->tenant->uuid.'/invoice/issue', ['from' => '2026-09-01', 'to' => '2026-09-30'])
        ->assertRedirect();

    return TenantInvoice::firstOrFail();
}

test('issuing freezes the figures, numbers the invoice and redirects the preview to it from then on', function () {
    $invoice = issueInvoice($this);

    expect($invoice->number)->toStartWith('INV-')->toEndWith('-202609')
        ->and((float) $invoice->ggr)->toBe(1000.0)
        ->and((float) $invoice->tax)->toBe(100.0)
        ->and((float) $invoice->tenant_share)->toBe(630.0)
        ->and((float) $invoice->amount_due)->toBe(270.0)
        ->and($invoice->status)->toBe('issued')
        ->and($invoice->due_at->toDateString())->toBe('2026-10-16')
        ->and($invoice->games[0]['name'])->toBe('Chinga Fantasy');

    // The engine's figures change afterwards; the issued invoice does not.
    $this->engine = fn () => Http::response(['total_wagered' => '9.00', 'total_paid_out' => '0.00', 'bets_placed' => 1, 'active_players' => 1]);
    $this->actingAs($this->platformAdmin)->get('/tenant-overview/'.$this->tenant->uuid.'/invoice?from=2026-09-01&to=2026-09-30')
        ->assertRedirect('/invoices/'.$invoice->number);
    $html = $this->actingAs($this->platformAdmin)->get('/invoices/'.$invoice->number)->assertOk()->getContent();
    expect($html)->toContain('N$270.00')->toContain('Issued')->toContain('Record payment');

    // Issuing the same period twice is refused.
    $this->actingAs($this->platformAdmin)->from('/invoices')
        ->post('/tenant-overview/'.$this->tenant->uuid.'/invoice/issue', ['from' => '2026-09-01', 'to' => '2026-09-30'])
        ->assertRedirect('/invoices')->assertSessionHasErrors('number');
    expect(TenantInvoice::count())->toBe(1);
});

test('a tenant admin can read their invoices but cannot issue, pay or void', function () {
    $invoice = issueInvoice($this);

    $this->actingAs($this->tenantAdmin)->post('/tenant-overview/'.$this->tenant->uuid.'/invoice/issue', ['from' => '2026-08-01', 'to' => '2026-08-31'])->assertForbidden();
    $this->actingAs($this->tenantAdmin)->post("/platform/invoices/{$invoice->id}/payments", ['amount' => 10, 'paid_at' => '2026-10-02', 'method' => 'cash'])->assertForbidden();
    $this->actingAs($this->tenantAdmin)->post("/platform/invoices/{$invoice->id}/void")->assertForbidden();

    $this->actingAs($this->tenantAdmin)->get('/invoices')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('invoices/index')->where('can_manage', false)->has('rows', 1)->where('rows.0.number', $invoice->number));
    $html = $this->actingAs($this->tenantAdmin)->get('/invoices/'.$invoice->number)->assertOk()->getContent();
    expect($html)->toContain('N$270.00')->not->toContain('Record payment');

    // Another tenant's admin sees nothing of it.
    $this->actingAs($this->otherAdmin)->get('/invoices/'.$invoice->number)->assertForbidden();
    $this->actingAs($this->otherAdmin)->get('/invoices')->assertOk()->assertInertia(fn (Assert $page) => $page->has('rows', 0));
});

test('payments move the invoice from issued to part paid to paid, and overpayment is refused', function () {
    $invoice = issueInvoice($this);

    $this->actingAs($this->platformAdmin)->from('/invoices')
        ->post("/platform/invoices/{$invoice->id}/payments", ['amount' => '100.00', 'paid_at' => '2026-10-02', 'method' => 'bank_transfer', 'reference' => 'EFT-1'])
        ->assertRedirect('/invoices')->assertSessionHas('success');
    $invoice->refresh();
    expect($invoice->status)->toBe('part_paid')->and((float) $invoice->amount_paid)->toBe(100.0)->and($invoice->outstanding())->toBe(170.0);

    $this->actingAs($this->platformAdmin)->from('/invoices')
        ->post("/platform/invoices/{$invoice->id}/payments", ['amount' => '170.01', 'paid_at' => '2026-10-02', 'method' => 'cash'])
        ->assertSessionHasErrors('amount');

    $this->actingAs($this->platformAdmin)->from('/invoices')
        ->post("/platform/invoices/{$invoice->id}/payments", ['amount' => '170.00', 'paid_at' => '2026-10-02', 'method' => 'cash'])
        ->assertSessionHas('success');
    $invoice->refresh();
    expect($invoice->status)->toBe('paid')->and($invoice->paid_at)->not->toBeNull()->and($invoice->payments()->count())->toBe(2);

    $this->actingAs($this->platformAdmin)->get('/invoices')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('can_manage', true)->where('totals.paid', fn ($v) => (float) $v === 270.0)->where('totals.outstanding', fn ($v) => (float) $v === 0.0)->where('rows.0.status', 'paid'));
    $html = $this->actingAs($this->platformAdmin)->get('/invoices/'.$invoice->number)->assertOk()->getContent();
    expect($html)->toContain('Paid · 2 Oct 2026')->toContain('EFT-1')->not->toContain('Record payment');

    // A paid invoice cannot be voided; removing a payment reopens it.
    $this->actingAs($this->platformAdmin)->from('/invoices')->post("/platform/invoices/{$invoice->id}/void")->assertSessionHasErrors('void');
    $payment = $invoice->payments()->latest('id')->first();
    $this->actingAs($this->platformAdmin)->delete("/platform/invoices/{$invoice->id}/payments/{$payment->id}")->assertRedirect();
    expect($invoice->fresh()->status)->toBe('part_paid');
});

test('an unpaid invoice can be voided and the period issued again', function () {
    $invoice = issueInvoice($this);

    $this->actingAs($this->platformAdmin)->from('/invoices')->post("/platform/invoices/{$invoice->id}/void", ['reason' => 'wrong share'])->assertRedirect('/invoices');
    $invoice->refresh();
    expect($invoice->status)->toBe('void')->and($invoice->void_reason)->toBe('wrong share');

    $this->actingAs($this->platformAdmin)->from('/invoices')
        ->post("/platform/invoices/{$invoice->id}/payments", ['amount' => '10.00', 'paid_at' => '2026-10-02', 'method' => 'cash'])
        ->assertSessionHasErrors('amount');

    // The preview for that period is live again, and a fresh number is needed: the old one is taken.
    $this->actingAs($this->platformAdmin)->get('/tenant-overview/'.$this->tenant->uuid.'/invoice?from=2026-09-01&to=2026-09-30')->assertOk();
    $this->actingAs($this->platformAdmin)->from('/invoices')
        ->post('/tenant-overview/'.$this->tenant->uuid.'/invoice/issue', ['from' => '2026-09-01', 'to' => '2026-09-30'])
        ->assertSessionHasErrors('number');
});

test('an invoice cannot be issued while a backend is unreachable', function () {
    $this->engine = fn () => Http::response('down', 503);
    $this->actingAs($this->platformAdmin)->from('/invoices')
        ->post('/tenant-overview/'.$this->tenant->uuid.'/invoice/issue', ['from' => '2026-09-01', 'to' => '2026-09-30'])
        ->assertSessionHasErrors('figures');
    expect(TenantInvoice::count())->toBe(0);
});
