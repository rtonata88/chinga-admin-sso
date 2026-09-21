<?php

use App\Models\Tenant;
use App\Models\TreasurySetting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Treasury: wallet balances are liabilities, deposits are not income,
 * and distributable = bank − owed − held − reserve − tax provision.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('platform_admin');

    $this->tenant = Tenant::factory()->create(['name' => 'Lucky Star Betting', 'slug' => 'lucky-star-betting']);
    $this->tenantAdmin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenantAdmin->assignRole('tenant_admin', $this->tenant->id);
    app()->instance('current_tenant', $this->tenant);

    $this->travelTo('2026-09-21 12:00:00');
});

function ledger(\App\Models\Wallet $wallet, string $type, string $amount, array $extra = []): WalletTransaction
{
    return WalletTransaction::create(array_merge([
        'wallet_id' => $wallet->id, 'type' => $type, 'amount' => $amount,
        'balance_before' => '0.00', 'balance_after' => '0.00', 'reference' => uniqid('t_', true),
    ], $extra));
}

test('the treasury view sums liabilities and computes what is distributable', function () {
    // Player A: N$1,000 staff top-up, played and won, N$1,200 left, a N$300 withdrawal on hold.
    $a = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $wa = $a->getOrCreateWallet('NAD');
    $wa->update(['balance' => '1200.00']);
    ledger($wa, 'deposit', '1000.00', ['performed_by' => $this->platformAdmin->id]);
    ledger($wa, 'bet', '500.00');
    ledger($wa, 'win', '1000.00');
    WithdrawalRequest::create([
        'tenant_id' => $this->tenant->id, 'user_id' => $a->id, 'wallet_id' => $wa->id,
        'amount' => '300.00', 'fee_amount' => '0.00', 'net_amount' => '300.00', 'currency' => 'NAD',
        'payment_method' => 'bank_transfer', 'payment_details' => [], 'status' => 'requested',
    ]);
    // Player B: N$500 voucher in May, never played: dormant.
    $b = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $wb = $b->getOrCreateWallet('NAD');
    $wb->update(['balance' => '500.00']);
    ledger($wb, 'deposit', '500.00');
    \DB::table('wallets')->where('id', $wb->id)->update(['updated_at' => '2026-05-01 10:00:00']);
    \DB::table('wallet_transactions')->where('wallet_id', $wb->id)->update(['created_at' => '2026-05-01 10:00:00']);
    // Player C: paid out N$200 last month, wallet empty.
    $c = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $wc = $c->getOrCreateWallet('NAD');
    ledger($wc, 'deposit', '200.00');
    WithdrawalRequest::create([
        'tenant_id' => $this->tenant->id, 'user_id' => $c->id, 'wallet_id' => $wc->id,
        'amount' => '200.00', 'fee_amount' => '0.00', 'net_amount' => '200.00', 'currency' => 'NAD',
        'payment_method' => 'bank_transfer', 'payment_details' => [], 'status' => 'paid',
    ]);

    TreasurySetting::current()->update(['bank_balance' => '2500.00', 'variance_reserve' => '400.00', 'tax_pct' => '10.00']);

    // owed 1700, held 300 → liability 2000; month GGR = 500 − 1000 = −500 → tax 0.
    // distributable = 2500 − 2000 − 400 − 0 = 100.
    $this->actingAs($this->platformAdmin)
        ->get('/platform/treasury')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('platform/treasury')
            ->where('summary.owed', '1700.00')
            ->where('summary.held', '300.00')
            ->where('summary.liability', '2000.00')
            ->where('summary.covered', true)
            ->where('summary.coverage_pct', '125.0')
            ->where('summary.tax_provision', '0.00')
            ->where('summary.distributable', '100.00')
            ->where('summary.dormant', '500.00')
            ->has('tenants', 1)
            ->where('tenants.0.tenant_name', 'Lucky Star Betting')
            ->where('tenants.0.wallets', 3)
            ->where('tenants.0.funded', 2)
            ->where('tenants.0.staff_deposits', '1000.00')
            ->where('tenants.0.player_deposits', '700.00')
            ->where('tenants.0.paid_out', '200.00')
            ->where('tenants.0.ledger_cash', '1500.00')
            ->where('tenants.0.lifetime_ggr', '-500.00')
        );
});

test('a positive month GGR is taxed and an unbacked position is flagged', function () {
    $a = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $wa = $a->getOrCreateWallet('NAD');
    $wa->update(['balance' => '1000.00']);
    ledger($wa, 'deposit', '2000.00');
    ledger($wa, 'bet', '3000.00');
    ledger($wa, 'win', '2000.00');
    TreasurySetting::current()->update(['bank_balance' => '800.00', 'variance_reserve' => '0.00', 'tax_pct' => '15.00']);

    // month GGR 1000 → tax 150; distributable = 800 − 1000 − 0 − 150 = −350.
    $this->actingAs($this->platformAdmin)
        ->get('/platform/treasury')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.covered', false)
            ->where('summary.surplus', '-200.00')
            ->where('summary.tax_provision', '150.00')
            ->where('summary.distributable', '-350.00')
            ->where('summary.month_ggr', '1000.00')
        );
});

test('platform admins can save the bank balance, reserve and tax rate', function () {
    $this->actingAs($this->platformAdmin)
        ->put('/platform/treasury', ['bank_balance' => '38650.00', 'bank_balance_as_of' => '2026-09-21', 'variance_reserve' => '30000', 'tax_pct' => '10'])
        ->assertRedirect('/platform/treasury');

    $s = TreasurySetting::current();
    expect((string) $s->bank_balance)->toBe('38650.00')
        ->and($s->bank_balance_as_of->toDateString())->toBe('2026-09-21')
        ->and((string) $s->variance_reserve)->toBe('30000.00')
        ->and((string) $s->tax_pct)->toBe('10.00')
        ->and($s->updated_by)->toBe($this->platformAdmin->id);

    $this->actingAs($this->platformAdmin)
        ->put('/platform/treasury', ['bank_balance' => '-1', 'variance_reserve' => '0', 'tax_pct' => '101'])
        ->assertSessionHasErrors(['bank_balance', 'tax_pct']);
});

test('the treasury is platform-admin only', function () {
    $this->actingAs($this->tenantAdmin)->get('/platform/treasury')->assertForbidden();
    $this->actingAs($this->tenantAdmin)->put('/platform/treasury', ['bank_balance' => '1', 'variance_reserve' => '0', 'tax_pct' => '0'])->assertForbidden();
    auth()->logout();
    $this->get('/platform/treasury')->assertRedirect('/login');
});
