<?php

use App\Models\Game;
use App\Models\GameSettingsAudit;
use App\Models\Tenant;
use App\Models\User;
use App\Support\FantasySettingsSchema;
use App\Support\KulipiKunaSettingsSchema;
use Illuminate\Support\Facades\DB;

/**
 * The tenants page's "Manage games" dialog and enable toggle never send
 * custom_settings. A missing key must keep the overrides already stored —
 * it used to wipe them, silently dropping an operator's Kulipi Kuna stake and
 * win limits. Sending the key (null, {} or an object) still replaces them.
 */
beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('platform_admin');
    $this->tenant = Tenant::factory()->create(['name' => 'Lucky Star Betting']);

    $this->kulipi = Game::factory()->create([
        'name' => 'Kulipi Kuna', 'slug' => 'kulipi-kuna', 'status' => 'active',
        'settings' => ['house_edge' => 0.04, 'hard_level_cap' => 10, 'max_win_per_ladder' => 16000, 'max_total_riding_per_round' => 25000,
            'min_bet_amount' => 5, 'max_bet_amount' => 50, 'level_decision_seconds' => 6, 'reveal_seconds' => 2],
        'settings_schema' => KulipiKunaSettingsSchema::definition(),
    ]);
    $this->kulipi->tenants()->attach($this->tenant->id, ['enabled' => true, 'custom_settings' => ['max_bet_amount' => 40]]);
    $this->fantasy = Game::factory()->fantasy()->create(['status' => 'active', 'settings' => [], 'settings_schema' => FantasySettingsSchema::definition()]);
});

function storedOverrides(Game $game, Tenant $tenant): ?array
{
    $raw = DB::table('tenant_games')->where('game_id', $game->id)->where('tenant_id', $tenant->id)->value('custom_settings');

    return $raw === null ? null : json_decode((string) $raw, true);
}

it('keeps the overrides when the enable toggle sends no custom_settings', function () {
    $this->actingAs($this->admin)
        ->putJson("/api/v1/platform/tenants/{$this->tenant->uuid}/games/{$this->kulipi->uuid}", ['enabled' => false])
        ->assertOk();

    expect(storedOverrides($this->kulipi, $this->tenant))->toBe(['max_bet_amount' => 40]);
    expect((bool) DB::table('tenant_games')->where('game_id', $this->kulipi->id)->where('tenant_id', $this->tenant->id)->value('enabled'))->toBeFalse();
    $row = GameSettingsAudit::query()->sole();
    expect($row->before)->toBe(['enabled' => true, 'custom_settings' => ['max_bet_amount' => 40]]);
    expect($row->after)->toBe(['enabled' => false, 'custom_settings' => ['max_bet_amount' => 40]]);
});

it('still clears the overrides when custom_settings is sent empty', function () {
    $this->actingAs($this->admin)
        ->putJson("/api/v1/platform/tenants/{$this->tenant->uuid}/games/{$this->kulipi->uuid}", ['enabled' => true, 'custom_settings' => []])
        ->assertOk();

    expect(storedOverrides($this->kulipi, $this->tenant))->toBeNull();
});

it('keeps overrides on games that stay through Manage games, and attaches new games with none', function () {
    $this->actingAs($this->admin)
        ->postJson("/api/v1/platform/tenants/{$this->tenant->uuid}/games", ['games' => [
            ['uuid' => $this->kulipi->uuid, 'enabled' => true],
            ['uuid' => $this->fantasy->uuid, 'enabled' => true],
        ]])
        ->assertOk();

    expect(storedOverrides($this->kulipi, $this->tenant))->toBe(['max_bet_amount' => 40]);
    expect(storedOverrides($this->fantasy, $this->tenant))->toBeNull();
    // Kulipi kept everything, so only the Fantasy attach is a change.
    expect(GameSettingsAudit::query()->where('game_id', $this->kulipi->id)->count())->toBe(0);
    expect(GameSettingsAudit::query()->where('game_id', $this->fantasy->id)->count())->toBe(1);
});

it('drops a game left out of Manage games, audited', function () {
    $this->actingAs($this->admin)
        ->postJson("/api/v1/platform/tenants/{$this->tenant->uuid}/games", ['games' => [['uuid' => $this->fantasy->uuid, 'enabled' => true]]])
        ->assertOk();

    expect(DB::table('tenant_games')->where('game_id', $this->kulipi->id)->where('tenant_id', $this->tenant->id)->exists())->toBeFalse();
    expect(GameSettingsAudit::query()->where('game_id', $this->kulipi->id)->sole()->after)->toBe([]);
});

it('locks every game a sync touches in ascending id order before writing', function () {
    $lockedIds = [];
    DB::listen(function ($q) use (&$lockedIds) {
        if (str_contains($q->sql, 'for update') && str_contains($q->sql, '`games`') && preg_match('/ in \(([^)]*)\)/', $q->sql, $m) === 1) {
            // whereKey on integer ids inlines them into the SQL rather than binding them.
            $lockedIds[] = [
                'ids' => $q->bindings !== [] ? $q->bindings : array_map('trim', explode(',', $m[1])),
                'ordered' => str_contains($q->sql, 'order by `id` asc'),
            ];
        }
    });

    $this->actingAs($this->admin)
        ->postJson("/api/v1/platform/tenants/{$this->tenant->uuid}/games", ['games' => [
            ['uuid' => $this->fantasy->uuid, 'enabled' => true],
        ]])
        ->assertOk();

    $expected = [$this->kulipi->id, $this->fantasy->id];
    sort($expected);
    expect($lockedIds)->not->toBeEmpty();
    // One statement takes every lock, scanning in id order, so two syncs can never wait on each other.
    expect(array_map('intval', $lockedIds[0]['ids']))->toBe($expected);
    expect($lockedIds[0]['ordered'])->toBeTrue();
});
