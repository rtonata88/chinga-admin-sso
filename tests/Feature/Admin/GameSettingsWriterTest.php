<?php

use App\Models\Game;
use App\Models\GameSettingsAudit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\GameSettingsAuditor;
use App\Support\FantasySettingsSchema;
use App\Support\KulipiKunaSettingsSchema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * K4 final review, findings 1, 2 and 7: every settings write, from the admin
 * console or the platform API, goes through GameSettingsWriter — validated
 * against the schema and the Kulipi Kuna rules, written and audited in one
 * transaction — and the audit trail outlives nothing it describes.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('platform_admin');
    $this->tenant = Tenant::factory()->create(['name' => 'Lucky Star Betting']);
    $this->other = Tenant::factory()->create(['name' => 'Unattached Bets']);

    $this->kulipi = Game::factory()->create([
        'name' => 'Kulipi Kuna', 'slug' => 'kulipi-kuna', 'status' => 'active',
        'settings' => writerKulipi(),
        'settings_schema' => KulipiKunaSettingsSchema::definition(),
    ]);
    $this->kulipi->tenants()->attach($this->tenant->id, ['enabled' => true]);
    $this->fantasy = Game::factory()->fantasy()->create(['status' => 'active', 'settings' => [], 'settings_schema' => FantasySettingsSchema::definition()]);
});

function writerKulipi(array $over = []): array
{
    return array_merge(['house_edge' => 0.04, 'hard_level_cap' => 10, 'max_win_per_ladder' => 16000, 'max_total_riding_per_round' => 25000,
        'min_bet_amount' => 5, 'max_bet_amount' => 50, 'level_decision_seconds' => 6, 'reveal_seconds' => 2], $over);
}

function failingAuditor(): void
{
    app()->instance(GameSettingsAuditor::class, new class extends GameSettingsAuditor
    {
        public function record(...$args): never
        {
            throw new RuntimeException('audit store down');
        }
    });
}

// Finding 1: atomic writes, no fabricated rows.

it('rolls back a global save when the audit cannot be written', function () {
    failingAuditor();
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->admin)->put("/platform/games/{$this->kulipi->uuid}/settings/global", writerKulipi(['house_edge' => 0.05])))
        ->toThrow(RuntimeException::class, 'audit store down');
    expect((float) $this->kulipi->fresh()->settings['house_edge'])->toBe(0.04);
});

it('rolls back a tenant save when the audit cannot be written', function () {
    failingAuditor();
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->admin)->put("/platform/games/{$this->kulipi->uuid}/settings/tenant/{$this->tenant->uuid}", ['enabled' => false, 'custom_settings' => ['max_bet_amount' => 40]]))
        ->toThrow(RuntimeException::class, 'audit store down');
    $pivot = DB::table('tenant_games')->where('game_id', $this->kulipi->id)->where('tenant_id', $this->tenant->id)->first();
    expect((bool) $pivot->enabled)->toBeTrue();
    expect($pivot->custom_settings)->toBeNull();
});

it('404s a tenant save for a tenant that does not have the game, and writes nothing', function () {
    $this->actingAs($this->admin)
        ->put("/platform/games/{$this->kulipi->uuid}/settings/tenant/{$this->other->uuid}", ['enabled' => true, 'custom_settings' => ['max_bet_amount' => 40]])
        ->assertNotFound();
    expect(GameSettingsAudit::query()->count())->toBe(0);
    expect(DB::table('tenant_games')->where('tenant_id', $this->other->id)->exists())->toBeFalse();
});

// Finding 2: the platform API goes through the same path.

it('PUT /games/{game}: refuses an out-of-band house edge with 422', function () {
    $this->actingAs($this->admin)
        ->putJson("/api/v1/platform/games/{$this->kulipi->uuid}", ['settings' => writerKulipi(['house_edge' => 0.5])])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['settings.house_edge']);
    expect((float) $this->kulipi->fresh()->settings['house_edge'])->toBe(0.04);
    expect(GameSettingsAudit::query()->count())->toBe(0);
});

it('PUT /games/{game}: refuses a config the Kulipi rules refuse', function () {
    $this->actingAs($this->admin)
        ->putJson("/api/v1/platform/games/{$this->kulipi->uuid}", ['settings' => writerKulipi(['max_win_per_ladder' => 95]), 'name' => 'Renamed'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['settings.max_win_per_ladder']);
    $fresh = $this->kulipi->fresh();
    expect((int) $fresh->settings['max_win_per_ladder'])->toBe(16000);
    expect($fresh->name)->toBe('Kulipi Kuna');
});

it('PUT /games/{game}: a valid change writes an audit row', function () {
    $this->actingAs($this->admin)
        ->putJson("/api/v1/platform/games/{$this->kulipi->uuid}", ['settings' => writerKulipi(['house_edge' => 0.05])])
        ->assertOk()
        ->assertJsonPath('data.settings.house_edge', 0.05);
    $row = GameSettingsAudit::query()->sole();
    expect($row->scope)->toBe('global');
    expect($row->user_id)->toBe($this->admin->id);
    expect((float) $row->rtp_after)->toBe(0.95);
});

it('PUT /games/{game}: an update without settings leaves them and the audit alone', function () {
    $this->actingAs($this->admin)
        ->putJson("/api/v1/platform/games/{$this->kulipi->uuid}", ['name' => 'Kulipi Kuna!'])
        ->assertOk();
    expect((float) $this->kulipi->fresh()->settings['house_edge'])->toBe(0.04);
    expect(GameSettingsAudit::query()->count())->toBe(0);
});

it('PUT /tenants/{tenant}/games/{game}: refuses an out-of-band house edge with 422', function () {
    $this->actingAs($this->admin)
        ->putJson("/api/v1/platform/tenants/{$this->tenant->uuid}/games/{$this->kulipi->uuid}", ['enabled' => true, 'custom_settings' => ['house_edge' => 0.5]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['custom_settings.house_edge']);
    // An overridable key outside its schema bounds is refused the same way.
    $this->actingAs($this->admin)
        ->putJson("/api/v1/platform/tenants/{$this->tenant->uuid}/games/{$this->kulipi->uuid}", ['enabled' => true, 'custom_settings' => ['max_bet_amount' => -5]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['custom_settings.max_bet_amount']);
    expect(GameSettingsAudit::query()->count())->toBe(0);
});

it('PUT /tenants/{tenant}/games/{game}: refuses an override the Kulipi rules refuse', function () {
    $this->actingAs($this->admin)
        ->putJson("/api/v1/platform/tenants/{$this->tenant->uuid}/games/{$this->kulipi->uuid}", ['enabled' => true, 'custom_settings' => ['min_bet_amount' => 60]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['custom_settings.min_bet_amount']);
    expect(GameSettingsAudit::query()->count())->toBe(0);
});

it('PUT /tenants/{tenant}/games/{game}: a valid change writes an audit row', function () {
    $this->actingAs($this->admin)
        ->putJson("/api/v1/platform/tenants/{$this->tenant->uuid}/games/{$this->kulipi->uuid}", ['enabled' => true, 'custom_settings' => ['max_bet_amount' => 40]])
        ->assertOk()
        ->assertJsonPath('data.pivot.custom_settings.max_bet_amount', 40);
    $row = GameSettingsAudit::query()->sole();
    expect($row->scope)->toBe('tenant');
    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->after['custom_settings'])->toBe(['max_bet_amount' => 40]);
});

it('POST /tenants/{tenant}/games: refuses an out-of-band house edge with 422 and changes nothing', function () {
    $this->actingAs($this->admin)
        ->postJson("/api/v1/platform/tenants/{$this->tenant->uuid}/games", ['games' => [['uuid' => $this->kulipi->uuid, 'custom_settings' => ['house_edge' => 0.5]]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['games.0.custom_settings.house_edge']);
    expect(GameSettingsAudit::query()->count())->toBe(0);
});

it('POST /tenants/{tenant}/games: refuses an override the Kulipi rules refuse, and rolls the whole sync back', function () {
    $this->actingAs($this->admin)
        ->postJson("/api/v1/platform/tenants/{$this->tenant->uuid}/games", ['games' => [
            ['uuid' => $this->fantasy->uuid],
            ['uuid' => $this->kulipi->uuid, 'custom_settings' => ['min_bet_amount' => 60]],
        ]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['games.1.custom_settings.min_bet_amount']);
    expect(DB::table('tenant_games')->where('tenant_id', $this->tenant->id)->where('game_id', $this->fantasy->id)->exists())->toBeFalse();
    expect(GameSettingsAudit::query()->count())->toBe(0);
});

it('POST /tenants/{tenant}/games: audits a change, an attach, a detach and a nulled override', function () {
    $this->kulipi->tenants()->updateExistingPivot($this->tenant->id, ['custom_settings' => ['max_bet_amount' => 40]]);

    // Nulls Kulipi's overrides and attaches Fantasy.
    $this->actingAs($this->admin)
        ->postJson("/api/v1/platform/tenants/{$this->tenant->uuid}/games", ['games' => [
            ['uuid' => $this->kulipi->uuid, 'enabled' => true, 'custom_settings' => null],
            ['uuid' => $this->fantasy->uuid, 'enabled' => true],
        ]])
        ->assertOk()
        ->assertJsonPath('message', 'Game assignments updated.');

    $nulled = GameSettingsAudit::query()->where('game_id', $this->kulipi->id)->sole();
    expect($nulled->before['custom_settings'])->toBe(['max_bet_amount' => 40]);
    expect($nulled->after['custom_settings'])->toBe([]);
    expect(DB::table('tenant_games')->where('tenant_id', $this->tenant->id)->where('game_id', $this->kulipi->id)->value('custom_settings'))->toBeNull();
    $attached = GameSettingsAudit::query()->where('game_id', $this->fantasy->id)->sole();
    expect($attached->before)->toBe([]);
    expect($attached->after['enabled'])->toBeTrue();

    // Detaches Kulipi.
    $this->actingAs($this->admin)
        ->postJson("/api/v1/platform/tenants/{$this->tenant->uuid}/games", ['games' => [['uuid' => $this->fantasy->uuid, 'enabled' => true]]])
        ->assertOk();
    $detached = GameSettingsAudit::query()->where('game_id', $this->kulipi->id)->orderByDesc('id')->first();
    expect($detached->after)->toBe([]);
    expect(DB::table('tenant_games')->where('tenant_id', $this->tenant->id)->where('game_id', $this->kulipi->id)->exists())->toBeFalse();
    // Re-sending the same assignments changes nothing and audits nothing.
    $count = GameSettingsAudit::query()->count();
    $this->actingAs($this->admin)
        ->postJson("/api/v1/platform/tenants/{$this->tenant->uuid}/games", ['games' => [['uuid' => $this->fantasy->uuid, 'enabled' => true]]])
        ->assertOk();
    expect(GameSettingsAudit::query()->count())->toBe($count);
});

// Finding 7: the audit trail does not vanish with its game.

it('refuses to delete a game that has settings audits', function () {
    $this->actingAs($this->admin)->put("/platform/games/{$this->kulipi->uuid}/settings/global", writerKulipi(['house_edge' => 0.05]));
    expect(GameSettingsAudit::query()->count())->toBe(1);

    expect(fn () => DB::table('games')->where('id', $this->kulipi->id)->delete())->toThrow(QueryException::class);
    expect(GameSettingsAudit::query()->count())->toBe(1);
});
