<?php

use App\Models\Game;
use App\Models\GameSettingsAudit;
use App\Models\Tenant;
use App\Models\User;
use App\Support\FantasySettingsSchema;
use App\Support\KulipiKunaSettingsSchema;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * K4 design A2: every settings save for every game writes one audit row
 * with before and after settings and RTP; a save that changes nothing
 * writes none. The Settings page lists the latest 50.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ndapewa Shilongo']);
    $this->admin->assignRole('platform_admin');
    $this->tenant = Tenant::factory()->create(['name' => 'Lucky Star Betting']);

    $this->kulipi = Game::factory()->create([
        'name' => 'Kulipi Kuna', 'slug' => 'kulipi-kuna',
        'settings' => ['house_edge' => 0.04, 'hard_level_cap' => 10, 'max_win_per_ladder' => 16000, 'max_total_riding_per_round' => 25000,
            'min_bet_amount' => 5, 'max_bet_amount' => 50, 'level_decision_seconds' => 6, 'reveal_seconds' => 2],
        'settings_schema' => KulipiKunaSettingsSchema::definition(),
    ]);
    $this->kulipi->tenants()->attach($this->tenant->id, ['enabled' => true]);
    $this->fantasy = Game::factory()->fantasy()->create(['settings' => [], 'settings_schema' => FantasySettingsSchema::definition()]);
});

function kulipiGlobal(array $over = []): array
{
    return array_merge(['house_edge' => 0.04, 'hard_level_cap' => 10, 'max_win_per_ladder' => 16000, 'max_total_riding_per_round' => 25000,
        'min_bet_amount' => 5, 'max_bet_amount' => 50, 'level_decision_seconds' => 6, 'reveal_seconds' => 2], $over);
}

it('audits a global save with before and after settings and RTP', function () {
    $this->actingAs($this->admin)
        ->put("/platform/games/{$this->kulipi->uuid}/settings/global", kulipiGlobal(['house_edge' => 0.05]))
        ->assertSessionHasNoErrors();

    $row = GameSettingsAudit::query()->sole();
    expect($row->game_id)->toBe($this->kulipi->id);
    expect($row->tenant_id)->toBeNull();
    expect($row->user_id)->toBe($this->admin->id);
    expect($row->scope)->toBe('global');
    expect((float) $row->before['house_edge'])->toBe(0.04);
    expect((float) $row->after['house_edge'])->toBe(0.05);
    expect((float) $row->rtp_before)->toBe(0.96);
    expect((float) $row->rtp_after)->toBe(0.95);
});

it('writes nothing when a save changes nothing', function () {
    $this->actingAs($this->admin)->put("/platform/games/{$this->kulipi->uuid}/settings/global", kulipiGlobal());
    expect(GameSettingsAudit::query()->count())->toBe(0);
});

it('audits a tenant save, including a change to enabled only', function () {
    $this->actingAs($this->admin)
        ->put("/platform/games/{$this->kulipi->uuid}/settings/tenant/{$this->tenant->uuid}", ['enabled' => false, 'custom_settings' => []])
        ->assertSessionHasNoErrors();
    $row = GameSettingsAudit::query()->sole();
    expect($row->scope)->toBe('tenant');
    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->before['enabled'])->toBeTrue();
    expect($row->after['enabled'])->toBeFalse();

    $this->actingAs($this->admin)
        ->put("/platform/games/{$this->kulipi->uuid}/settings/tenant/{$this->tenant->uuid}", ['enabled' => false, 'custom_settings' => []]);
    expect(GameSettingsAudit::query()->count())->toBe(1);
});

it('audits every game, not only Kulipi Kuna', function () {
    $defaults = collect(FantasySettingsSchema::definition()['properties'])->map(fn ($p) => $p['default'] ?? null)->filter(fn ($v) => $v !== null)->all();
    $this->actingAs($this->admin)
        ->put("/platform/games/{$this->fantasy->uuid}/settings/global", array_merge($defaults, ['house_edge' => 0.12]))
        ->assertSessionHasNoErrors();
    expect(GameSettingsAudit::query()->where('game_id', $this->fantasy->id)->count())->toBe(1);
});

it('lists the latest changes on the Settings page, newest first, with what changed', function () {
    $this->actingAs($this->admin)->put("/platform/games/{$this->kulipi->uuid}/settings/global", kulipiGlobal(['max_bet_amount' => 40]));
    $this->actingAs($this->admin)->put("/platform/games/{$this->kulipi->uuid}/settings/global", kulipiGlobal(['max_bet_amount' => 40, 'house_edge' => 0.05]));

    $this->actingAs($this->admin)
        ->get("/platform/games/{$this->kulipi->uuid}/settings")
        ->assertInertia(fn (Assert $page) => $page
            ->has('audits', 2)
            ->where('audits.0.by', 'Ndapewa Shilongo')
            ->where('audits.0.scope', 'global')
            ->where('audits.0.changes.0.key', 'house_edge')
            ->where('audits.0.rtp_after', 0.95)
            ->where('audits.1.changes.0.key', 'max_bet_amount'));
});

it('lists a tenant change override by override in History', function () {
    $this->kulipi->tenants()->updateExistingPivot($this->tenant->id, ['custom_settings' => ['max_bet_amount' => 50, 'min_bet_amount' => 5]]);
    $this->actingAs($this->admin)
        ->put("/platform/games/{$this->kulipi->uuid}/settings/tenant/{$this->tenant->uuid}", ['enabled' => true, 'custom_settings' => ['max_bet_amount' => 40, 'min_bet_amount' => 5]])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->get("/platform/games/{$this->kulipi->uuid}/settings")
        ->assertInertia(fn (Assert $page) => $page
            ->has('audits', 1)
            ->where('audits.0.scope', 'tenant')
            ->where('audits.0.tenant', 'Lucky Star Betting')
            ->has('audits.0.changes', 1)
            ->where('audits.0.changes.0.key', 'custom_settings.max_bet_amount')
            ->where('audits.0.changes.0.before', 50)
            ->where('audits.0.changes.0.after', 40));
});

it('ignores key order at every depth when deciding whether anything changed', function () {
    $auditor = app(\App\Services\GameSettingsAuditor::class);
    $row = $auditor->record($this->kulipi, $this->tenant, $this->admin,
        ['enabled' => true, 'custom_settings' => ['max_bet_amount' => 40, 'min_bet_amount' => 5]],
        ['custom_settings' => ['min_bet_amount' => 5, 'max_bet_amount' => 40], 'enabled' => true],
        0.96, 0.96);
    expect($row)->toBeNull();
    expect(\App\Services\GameSettingsAuditor::changes(
        ['custom_settings' => ['a' => ['y' => 1, 'x' => 2]]],
        ['custom_settings' => ['a' => ['x' => 2, 'y' => 1], 'b' => 3]],
    ))->toBe([['key' => 'custom_settings.b', 'before' => null, 'after' => 3]]);
});
