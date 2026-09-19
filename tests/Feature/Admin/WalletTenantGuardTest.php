<?php

use App\Models\Tenant;
use App\Models\User;

/**
 * Money moves on a wallet only by an admin of the tenant that owns it. Platform admins
 * see every operator's wallets but may not create or remove balance on an operator's books.
 */
beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->tenant = Tenant::factory()->create(['name' => 'Lucky Star Betting']);
    $this->otherTenant = Tenant::factory()->create(['name' => 'Other Operator']);
    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('platform_admin');
    $this->tenantAdmin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenantAdmin->assignRole('tenant_admin', $this->tenant->id);
    $this->otherAdmin = User::factory()->create(['tenant_id' => $this->otherTenant->id]);
    $this->otherAdmin->assignRole('tenant_admin', $this->otherTenant->id);
    $player = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->wallet = $player->getOrCreateWallet('NAD');
});

test('a platform admin cannot add or remove balance on an operator wallet', function () {
    $this->actingAs($this->platformAdmin)
        ->postJson("/api/v1/admin/wallets/{$this->wallet->uuid}/deposit", ['amount' => '100.00', 'reference' => 'test'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'cross_tenant_wallet')
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'Lucky Star Betting'));
    $this->actingAs($this->platformAdmin)
        ->postJson("/api/v1/admin/wallets/{$this->wallet->uuid}/withdraw", ['amount' => '1.00'])
        ->assertStatus(403);
    expect((string) $this->wallet->fresh()->balance)->toBe('0.00')
        ->and($this->wallet->transactions()->count())->toBe(0);
});

test('the owning tenant\'s admin can top up, and it lands on that tenant\'s ledger', function () {
    $this->actingAs($this->tenantAdmin)
        ->withHeaders(['X-Tenant-ID' => $this->tenant->slug])
        ->postJson("/api/v1/admin/wallets/{$this->wallet->uuid}/deposit", ['amount' => '100.00', 'reference' => 'counter top-up'])
        ->assertSuccessful();
    $fresh = $this->wallet->fresh();
    expect((string) $fresh->balance)->toBe('100.00')
        ->and($fresh->tenant_id)->toBe($this->tenant->id)
        ->and($this->wallet->transactions()->latest('id')->first()->performed_by)->toBe($this->tenantAdmin->id);
});

test('another operator\'s admin cannot even see the wallet', function () {
    $this->actingAs($this->otherAdmin)
        ->withHeaders(['X-Tenant-ID' => $this->otherTenant->slug])
        ->postJson("/api/v1/admin/wallets/{$this->wallet->uuid}/deposit", ['amount' => '100.00'])
        ->assertStatus(404);
    expect((string) $this->wallet->fresh()->balance)->toBe('0.00');
});
