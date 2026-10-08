<?php

use App\Models\Game;
use App\Models\Tenant;
use App\Models\User;
use App\Support\DorexConfigRules;
use App\Support\DorexSettingsSchema;
use App\Support\SettingsSchema;

/**
 * X4: the SSO refuses a Dorex config the engine would refuse. tests/Fixtures/dorex-config-cases.json
 * is a verbatim copy of platform/packages/dorex-math/fixtures/config-cases.json, which
 * packages/dorex-math/src/config-cases.test.ts runs against validateConfig.
 */
beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('platform_admin');
    $this->tenant = Tenant::factory()->create(['name' => 'Lucky Star Betting']);
    $this->game = Game::factory()->create([
        'name' => 'Dorex', 'slug' => 'dorex',
        'settings' => (new SettingsSchema(DorexSettingsSchema::definition()))->defaults(),
        'settings_schema' => DorexSettingsSchema::definition(),
    ]);
    $this->game->tenants()->attach($this->tenant->id, ['enabled' => true]);
});

it('agrees with the maths package on every shared case', function (array $case) {
    $violations = DorexConfigRules::violations($case['settings']);
    if ($case['valid']) {
        expect($violations)->toBe([]);
    } else {
        expect($violations)->toHaveKey($case['messageContains']);
    }
})->with(function () {
    $file = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/dorex-config-cases.json'), true);

    return collect($file['cases'])->mapWithKeys(fn ($c) => [$c['name'] => [$c]])->all();
});

it('computes each stake\'s ceiling as the engine does', function () {
    // The engine's pinned values (apps/dorex-engine, @chinga/dorex-math settle.test.ts).
    expect(DorexConfigRules::effectiveCeiling(10, 16000, 1200, 400, 5_000_000))->toBe(5_000_000)
        ->and(DorexConfigRules::effectiveCeiling(33, 16000, 1200, 400, 5_000_000))->toBe(4_656_400)
        ->and(DorexConfigRules::effectiveCeiling(50, 16000, 1200, 400, 5_000_000))->toBe(3_073_200);
});

it('refuses a global save the engine would refuse, and saves nothing', function () {
    $settings = array_merge($this->game->settings, ['max_win_per_position' => 104]);
    $this->actingAs($this->admin)
        ->put("/platform/games/{$this->game->uuid}/settings/global", $settings)
        ->assertSessionHasErrors(['max_win_per_position']);
    expect((int) $this->game->fresh()->settings['max_win_per_position'])->toBe(16000);
    expect(\Illuminate\Support\Facades\DB::table('game_settings_audits')->count())->toBe(0);
});

it('saves a valid change and audits it with the RTP', function () {
    $settings = array_merge($this->game->settings, ['house_edge' => 0.035]);
    $this->actingAs($this->admin)
        ->put("/platform/games/{$this->game->uuid}/settings/global", $settings)
        ->assertSessionHasNoErrors();
    $audit = \Illuminate\Support\Facades\DB::table('game_settings_audits')->first();
    expect((float) $audit->rtp_after)->toBe(0.965);
});

it('refuses a tenant override that breaks the rules once merged with the global settings', function () {
    $this->actingAs($this->admin)
        ->put("/platform/games/{$this->game->uuid}/settings/tenant/{$this->tenant->uuid}", ['enabled' => true, 'custom_settings' => ['min_bet_amount' => 60]])
        ->assertSessionHasErrors(['custom_settings.min_bet_amount']);
});
