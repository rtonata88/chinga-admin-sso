<?php

use App\Models\Game;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Wallet;
use App\Support\Currencies;
use App\Support\KulipiKunaConfigRules;
use App\Support\KulipiKunaSettingsSchema;
use Illuminate\Support\Facades\Http;

/**
 * USSD design U-D15 and U-D16: every tenant has a currency profile, served with
 * each game config and tenant lookup, and a currency without cents only takes
 * Kulipi Kuna stakes that keep every payout whole.
 */
beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('platform_admin');
    $this->bw = Tenant::factory()->create(['name' => 'Orange Botswana', 'slug' => 'orange-bw', 'currency' => 'BWP', 'country_code' => 'BW', 'status' => 'active']);
    $this->game = Game::factory()->create([
        'name' => 'Kulipi Kuna', 'slug' => 'kulipi-kuna',
        'settings' => ['house_edge' => 0.04, 'hard_level_cap' => 10, 'max_win_per_ladder' => 16000, 'max_total_riding_per_round' => 25000,
            'min_bet_amount' => 5, 'max_bet_amount' => 50, 'level_decision_seconds' => 6, 'reveal_seconds' => 2],
        'settings_schema' => KulipiKunaSettingsSchema::definition(),
    ]);
    $this->game->tenants()->attach($this->bw->id, ['enabled' => true]);
});

it('formats money without floats and refuses to round', function () {
    expect(Currencies::format('1184.6', Currencies::profile('BWP')))->toBe('P1,184.60')
        ->and(Currencies::format('16000', Currencies::profile('NAD')))->toBe('N$16,000.00')
        ->and(Currencies::format('-12.40', Currencies::profile('ZAR')))->toBe('-R12.40')
        ->and(Currencies::format('2500', Currencies::profile('UGX')))->toBe('USh 2,500')
        ->and(Currencies::format('0.00', Currencies::profile('UGX')))->toBe('USh 0');
    expect(fn () => Currencies::format('12.40', Currencies::profile('UGX')))->toThrow(InvalidArgumentException::class);
});

it('serves the tenant currency profile with the game config', function () {
    $this->getJson("/api/v1/games/{$this->game->uuid}/config?tenant_uuid={$this->bw->uuid}")
        ->assertOk()
        ->assertJsonPath('currency.code', 'BWP')
        ->assertJsonPath('currency.symbol', 'P')
        ->assertJsonPath('currency.decimals', 2);
    $this->getJson("/api/v1/games/{$this->game->uuid}/config")->assertOk()->assertJsonPath('currency', null);
});

it('applies a tenant symbol override and serves the profile on tenant resolve', function () {
    $this->bw->update(['settings' => ['currency_symbol' => 'BWP ']]);
    $this->postJson('/api/v1/tenant/resolve', ['slug' => 'orange-bw'])
        ->assertOk()
        ->assertJsonPath('tenant.currency', 'BWP')
        ->assertJsonPath('tenant.currency_profile.symbol', 'BWP');
});

it('refuses an unsupported currency and a currency change once wallets exist', function () {
    $this->actingAs($this->admin)->putJson("/api/v1/platform/tenants/{$this->bw->uuid}", ['currency' => 'XYZ'])
        ->assertStatus(422)->assertJsonValidationErrors('currency');
    $this->actingAs($this->admin)->putJson("/api/v1/platform/tenants/{$this->bw->uuid}", ['currency' => 'ZAR'])->assertOk();
    $this->actingAs($this->admin)->putJson("/api/v1/platform/tenants/{$this->bw->uuid}", ['currency' => 'BWP'])->assertOk();

    $player = User::factory()->create(['tenant_id' => $this->bw->id]);
    $player->getOrCreateWallet();
    expect(Wallet::where('user_id', $player->id)->value('currency'))->toBe('BWP');
    $this->actingAs($this->admin)->putJson("/api/v1/platform/tenants/{$this->bw->uuid}", ['currency' => 'ZAR'])
        ->assertStatus(422)->assertJsonValidationErrors('currency');
});

it('needs no stake step with cents, and the U-D16 step without', function () {
    expect(KulipiKunaConfigRules::stakeStep(40, 2))->toBe(1)
        ->and(KulipiKunaConfigRules::stakeStep(40, 0))->toBe(25)   // level 1 pays 1.92
        ->and(KulipiKunaConfigRules::stakeStep(35, 0))->toBe(100)  // 1.93
        ->and(KulipiKunaConfigRules::stakeStep(50, 0))->toBe(10)   // 1.90
        ->and(KulipiKunaConfigRules::stakeStep(100, 0))->toBe(5);  // 1.80
    // Every multiple of the step pays a whole amount at every level.
    foreach ([20, 25, 30, 35, 40, 45, 50, 55, 60, 65, 70, 75, 80, 85, 90, 95, 100] as $edge) {
        $step = KulipiKunaConfigRules::stakeStep($edge, 0);
        expect(($step * intdiv(1000 - $edge, 5)) % 100)->toBe(0);
        if ($step > 1) {
            expect((($step - 1) * intdiv(1000 - $edge, 5)) % 100)->not->toBe(0);
        }
    }
});

it('refuses Kulipi stakes off the step for a tenant whose currency has no cents', function () {
    $ug = Tenant::factory()->create(['name' => 'Kampala Bets', 'currency' => 'UGX']);
    $this->game->tenants()->attach($ug->id, ['enabled' => true]);
    $this->actingAs($this->admin)
        ->put("/platform/games/{$this->game->uuid}/settings/tenant/{$ug->uuid}", ['enabled' => true, 'custom_settings' => [
            'min_bet_amount' => 1000, 'max_bet_amount' => 5010, 'max_win_per_ladder' => 10000000,
        ]])
        ->assertSessionHasErrors(['custom_settings.max_bet_amount']);
    expect(session('errors')->first('custom_settings.max_bet_amount'))->toContain('multiple of 25')->toContain('USh');
    $this->actingAs($this->admin)
        ->put("/platform/games/{$this->game->uuid}/settings/tenant/{$ug->uuid}", ['enabled' => true, 'custom_settings' => [
            'min_bet_amount' => 1000, 'max_bet_amount' => 5000, 'max_win_per_ladder' => 10000000,
        ]])
        ->assertSessionHasNoErrors();
    // The same stakes are fine for a tenant with cents.
    $this->actingAs($this->admin)
        ->put("/platform/games/{$this->game->uuid}/settings/tenant/{$this->bw->uuid}", ['enabled' => true, 'custom_settings' => ['min_bet_amount' => 7, 'max_bet_amount' => 49]])
        ->assertSessionHasNoErrors();
});

it("checks a global save against each tenant's own currency", function () {
    $ug = Tenant::factory()->create(['name' => 'Kampala Bets', 'currency' => 'UGX']);
    $this->game->tenants()->attach($ug->id, ['enabled' => true, 'custom_settings' => json_encode(['max_win_per_ladder' => 10000000])]);
    $this->actingAs($this->admin)
        ->put("/platform/games/{$this->game->uuid}/settings/global", array_merge($this->game->settings, ['min_bet_amount' => 5, 'max_bet_amount' => 50]))
        ->assertSessionHasErrors(['min_bet_amount']);
    expect(session('errors')->first('min_bet_amount'))->toContain('Kampala Bets');
});

it('serves the name players see on each tenant, set and cleared from the settings page, audited', function () {
    $this->getJson("/api/v1/games/{$this->game->uuid}/config?tenant_uuid={$this->bw->uuid}")->assertJsonPath('game_name', 'Kulipi Kuna');

    $this->actingAs($this->admin)
        ->put("/platform/games/{$this->game->uuid}/settings/tenant/{$this->bw->uuid}", ['enabled' => true, 'display_name' => '  Tsaya Seatla  ', 'custom_settings' => []])
        ->assertSessionHasNoErrors();
    $this->getJson("/api/v1/games/{$this->game->uuid}/config?tenant_uuid={$this->bw->uuid}")->assertJsonPath('game_name', 'Tsaya Seatla');
    $audit = \Illuminate\Support\Facades\DB::table('game_settings_audits')->latest('id')->first();
    expect(json_decode($audit->after, true)['display_name'])->toBe('Tsaya Seatla');

    // A save without the field (the tenants page toggle) keeps the name.
    $this->actingAs($this->admin)
        ->put("/platform/games/{$this->game->uuid}/settings/tenant/{$this->bw->uuid}", ['enabled' => true])
        ->assertSessionHasNoErrors();
    $this->getJson("/api/v1/games/{$this->game->uuid}/config?tenant_uuid={$this->bw->uuid}")->assertJsonPath('game_name', 'Tsaya Seatla');

    $this->actingAs($this->admin)
        ->put("/platform/games/{$this->game->uuid}/settings/tenant/{$this->bw->uuid}", ['enabled' => true, 'display_name' => ''])
        ->assertSessionHasNoErrors();
    $this->getJson("/api/v1/games/{$this->game->uuid}/config?tenant_uuid={$this->bw->uuid}")->assertJsonPath('game_name', 'Kulipi Kuna');

    $this->actingAs($this->admin)
        ->put("/platform/games/{$this->game->uuid}/settings/tenant/{$this->bw->uuid}", ['enabled' => true, 'display_name' => str_repeat('x', 61)])
        ->assertSessionHasErrors(['display_name']);
});

it('totals money per currency and never across currencies', function () {
    $totals = Currencies::totalsByCurrency([
        ['currency' => 'NAD', 'staked' => '10.10', 'paid' => 5],
        ['currency' => 'BWP', 'staked' => '0.20', 'paid' => '0.00'],
        ['currency' => 'NAD', 'staked' => 0.1, 'paid' => '2.45'],
    ], ['staked', 'paid']);

    expect($totals)->toBe([
        ['currency' => 'BWP', 'staked' => '0.20', 'paid' => '0.00'],
        ['currency' => 'NAD', 'staked' => '10.20', 'paid' => '7.45'],
    ]);
    expect(fn () => Currencies::totalsByCurrency([['staked' => '1.00']], ['staked']))->toThrow(InvalidArgumentException::class);
});

it("shares the signed-in tenant user's currency with every page", function () {
    $admin = User::factory()->create(['tenant_id' => $this->bw->id]);
    $admin->assignRole('tenant_admin', $this->bw->id);
    Http::fake();

    $this->actingAs($admin)->get('/tenant-overview')
        ->assertInertia(fn ($page) => $page
            ->where('tenant.currency.code', 'BWP')
            ->where('tenant.currency.symbol', 'P')
            ->where('currencies.NAD.symbol', 'N$'));
});
