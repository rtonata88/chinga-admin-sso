<?php

use App\Models\Game;
use App\Models\Tenant;
use App\Models\User;
use App\Support\KulipiKunaConfigRules;
use App\Support\KulipiKunaSettingsSchema;

/**
 * K4 design A3: the SSO refuses a Kulipi Kuna config the engine would
 * refuse. tests/Fixtures/kulipi-config-cases.json is a verbatim copy of
 * platform/packages/kulipi-math/fixtures/config-cases.json, which
 * packages/kulipi-math/src/config-cases.test.ts runs against validateConfig.
 */
beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('platform_admin');
    $this->tenant = Tenant::factory()->create(['name' => 'Lucky Star Betting']);
    $this->game = Game::factory()->create([
        'name' => 'Kulipi Kuna', 'slug' => 'kulipi-kuna',
        'settings' => ['house_edge' => 0.04, 'hard_level_cap' => 10, 'max_win_per_ladder' => 16000, 'max_total_riding_per_round' => 25000,
            'min_bet_amount' => 5, 'max_bet_amount' => 50, 'level_decision_seconds' => 6, 'reveal_seconds' => 2],
        'settings_schema' => KulipiKunaSettingsSchema::definition(),
    ]);
    $this->game->tenants()->attach($this->tenant->id, ['enabled' => true]);
});

it('agrees with the maths package on every shared case', function (array $case) {
    $violations = KulipiKunaConfigRules::violations($case['settings']);
    if ($case['valid']) {
        expect($violations)->toBe([]);
    } else {
        expect($violations)->toHaveKey($case['messageContains']);
    }
})->with(function () {
    $file = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/kulipi-config-cases.json'), true);

    return collect($file['cases'])->mapWithKeys(fn ($c) => [$c['name'] => [$c]])->all();
});

function kulipiSettings(array $over = []): array
{
    return array_merge(['house_edge' => 0.04, 'hard_level_cap' => 10, 'max_win_per_ladder' => 16000, 'max_total_riding_per_round' => 25000,
        'min_bet_amount' => 5, 'max_bet_amount' => 50, 'level_decision_seconds' => 6, 'reveal_seconds' => 2], $over);
}

it('refuses a global save the engine would refuse, and saves nothing', function () {
    $this->actingAs($this->admin)
        ->put("/platform/games/{$this->game->uuid}/settings/global", kulipiSettings(['max_win_per_ladder' => 95]))
        ->assertSessionHasErrors(['max_win_per_ladder']);
    expect((int) $this->game->fresh()->settings['max_win_per_ladder'])->toBe(16000);
    expect(\Illuminate\Support\Facades\DB::table('game_settings_audits')->count())->toBe(0);
});

it('refuses a tenant override that breaks the rules once merged with the global settings', function () {
    $this->actingAs($this->admin)
        ->put("/platform/games/{$this->game->uuid}/settings/tenant/{$this->tenant->uuid}", ['enabled' => true, 'custom_settings' => ['min_bet_amount' => 60]])
        ->assertSessionHasErrors(['custom_settings.min_bet_amount']);
});

it("refuses a global save that a tenant's existing overrides would break, naming the tenant", function () {
    $this->game->tenants()->updateExistingPivot($this->tenant->id, ['custom_settings' => ['max_bet_amount' => 60]]);
    $response = $this->actingAs($this->admin)
        ->put("/platform/games/{$this->game->uuid}/settings/global", kulipiSettings(['max_win_per_ladder' => 100]));
    $response->assertSessionHasErrors(['max_win_per_ladder']);
    expect(session('errors')->first('max_win_per_ladder'))->toContain('Lucky Star Betting');
});

it('leaves other games alone', function () {
    $other = Game::factory()->create(['slug' => 'vrrr-pha', 'settings' => [], 'settings_schema' => ['type' => 'object', 'properties' => [
        'min_bet_amount' => ['type' => 'number', 'minimum' => 1, 'default' => 5, 'x-group' => 'game'],
        'max_bet_amount' => ['type' => 'number', 'minimum' => 1, 'default' => 50, 'x-group' => 'game'],
    ]]]);
    $this->actingAs($this->admin)
        ->put("/platform/games/{$other->uuid}/settings/global", ['min_bet_amount' => 60, 'max_bet_amount' => 50])
        ->assertSessionHasNoErrors();
});

/** Runs resources/js/pages/games/kulipi-depth.ts under node (type stripping) and returns depthRows for each settings set. */
function depthPreview(array ...$settings): array
{
    $module = base_path('resources/js/pages/games/kulipi-depth.ts');
    $script = 'import { depthRows } from '.json_encode('file://'.$module).'; const cases = JSON.parse(process.argv[1]);'
        .' const t = Date.now(); const out = cases.map((c) => depthRows(c).length); console.log(JSON.stringify({ out, ms: Date.now() - t }));';
    $process = new \Symfony\Component\Process\Process(['node', '--no-warnings', '--input-type=module', '-e', $script, json_encode($settings)]);
    $process->mustRun();

    return json_decode(trim($process->getOutput()), true);
}

it('draws no depth preview for an off-grid edge or a stake range too wide to list', function () {
    $result = depthPreview(
        kulipiSettings(),
        kulipiSettings(['house_edge' => 0.043]),
        kulipiSettings(['max_bet_amount' => 5000000, 'max_win_per_ladder' => 100000000]),
        kulipiSettings(['max_bet_amount' => 1005, 'max_win_per_ladder' => 2000000]),
    );
    expect($result['out'])->toBe([10, 0, 0, 201]);
});
