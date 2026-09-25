<?php

use App\Models\Game;
use App\Models\Tenant;
use App\Models\User;
use App\Support\FantasySettingsSchema;
use Inertia\Testing\AssertableInertia as Assert;

function crashSchema(): array
{
    return [
        'type' => 'object',
        'required' => ['min_bet_amount', 'house_edge'],
        'properties' => [
            'min_bet_amount' => ['type' => 'number', 'minimum' => 1, 'default' => 5, 'x-group' => 'game'],
            'house_edge' => ['type' => 'number', 'minimum' => 0, 'maximum' => 0.5, 'default' => 0.04, 'x-group' => 'game'],
            'auto_cashout_enabled' => ['type' => 'boolean', 'default' => true, 'x-group' => 'game'],
            'geo_allowed_countries' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => ['NA'], 'x-group' => 'game'],
            'default_business_model' => ['type' => 'string', 'enum' => ['reseller', 'direct'], 'default' => 'reseller', 'x-group' => 'commercial', 'x-tenant-overridable' => false],
        ],
    ];
}

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('platform_admin');

    $this->fantasy = Game::factory()->fantasy()->create([
        'settings' => ['min_bet_amount' => 12],
        'settings_schema' => FantasySettingsSchema::definition(),
    ]);
    $this->crash = Game::factory()->create([
        'name' => 'Vrrr Pha',
        'slug' => 'vrrr-pha',
        'settings' => [],
        'settings_schema' => crashSchema(),
    ]);
    $this->tenant = Tenant::factory()->create();
});

test('settings page renders from the schema with saved values over defaults', function () {
    $this->actingAs($this->platformAdmin)
        ->get("/platform/games/{$this->fantasy->uuid}/settings")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('games/settings')
            ->where('game.uuid', $this->fantasy->uuid)
            ->where('game.settings.min_bet_amount', 12)
            ->where('game.settings.grid_size', 50)
            ->where('game.theoretical_rtp', 0.9)
            ->has('schema.properties.grid_size')
            ->where('schema.properties.grid_size.maximum', 100)
            ->has('tenants', 0)
        );
});

test('global update is validated from the schema and stores only schema keys', function () {
    $this->actingAs($this->platformAdmin)
        ->put("/platform/games/{$this->fantasy->uuid}/settings/global", ['grid_size' => 1000])
        ->assertSessionHasErrors(['grid_size']);

    $valid = array_merge(
        (new \App\Support\SettingsSchema(FantasySettingsSchema::definition()))->defaults(),
        ['grid_size' => 40, 'not_in_schema' => 'ignored'],
    );

    $this->actingAs($this->platformAdmin)
        ->put("/platform/games/{$this->fantasy->uuid}/settings/global", $valid)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $settings = $this->fantasy->fresh()->settings;
    expect($settings['grid_size'])->toBe(40)
        ->and($settings)->not->toHaveKey('not_in_schema');
});

test('a boolean false and an array survive a global update', function () {
    $this->actingAs($this->platformAdmin)
        ->put("/platform/games/{$this->crash->uuid}/settings/global", [
            'min_bet_amount' => 5,
            'house_edge' => 0.04,
            'auto_cashout_enabled' => false,
            'geo_allowed_countries' => ['NA', 'ZA'],
        ])
        ->assertSessionHasNoErrors();

    $settings = $this->crash->fresh()->settings;
    expect($settings['auto_cashout_enabled'])->toBeFalse()
        ->and($settings['geo_allowed_countries'])->toBe(['NA', 'ZA']);
});

test('tenant override keeps false, drops non-overridable keys and is stored as an array', function () {
    $this->crash->tenants()->attach($this->tenant->id, ['enabled' => true]);

    $this->actingAs($this->platformAdmin)
        ->put("/platform/games/{$this->crash->uuid}/settings/tenant/{$this->tenant->uuid}", [
            'enabled' => true,
            'custom_settings' => [
                'auto_cashout_enabled' => false,
                'default_business_model' => 'direct',
                'min_bet_amount' => null,
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $pivot = $this->crash->tenants()->where('tenants.id', $this->tenant->id)->first()->pivot;
    expect($pivot->custom_settings)->toBe(['auto_cashout_enabled' => false])
        ->and($pivot->enabled)->toBeTrue();
});

test('the game config endpoint merges tenant overrides and preserves false', function () {
    $this->crash->update(['settings' => ['min_bet_amount' => 5, 'auto_cashout_enabled' => true]]);
    $this->crash->tenants()->attach($this->tenant->id, [
        'enabled' => true,
        'custom_settings' => ['auto_cashout_enabled' => false, 'house_edge' => null],
    ]);

    $this->getJson("/api/v1/games/{$this->crash->uuid}/config?tenant_uuid={$this->tenant->uuid}")
        ->assertOk()
        ->assertJsonPath('settings.min_bet_amount', 5)
        ->assertJsonPath('settings.auto_cashout_enabled', false)
        ->assertJsonMissingPath('settings.house_edge');
});

test('tenant game sync stores custom_settings through the pivot cast', function () {
    $this->actingAs($this->platformAdmin)
        ->postJson("/api/v1/platform/tenants/{$this->tenant->uuid}/games", [
            'games' => [['uuid' => $this->crash->uuid, 'enabled' => true, 'custom_settings' => ['min_bet_amount' => 20]]],
        ])
        ->assertOk()
        ->assertJsonPath('data.0.pivot.custom_settings.min_bet_amount', 20);

    expect($this->tenant->games()->first()->pivot->custom_settings)->toBe(['min_bet_amount' => 20]);
});

test('legacy fantasy settings routes redirect to the generic page', function () {
    $this->actingAs($this->platformAdmin)
        ->get('/fantasy/settings')
        ->assertRedirect("/platform/games/{$this->fantasy->uuid}/settings");
});

test('tenant admins cannot open game settings', function () {
    $tenantAdmin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $tenantAdmin->assignRole('tenant_admin', $this->tenant->id);

    $this->actingAs($tenantAdmin)
        ->get("/platform/games/{$this->crash->uuid}/settings")
        ->assertForbidden();
});

test('the platform catalogue API rejects a schema outside the supported subset', function () {
    $this->actingAs($this->platformAdmin)
        ->putJson("/api/v1/platform/games/{$this->crash->uuid}", [
            'settings_schema' => ['type' => 'object', 'properties' => ['x' => ['type' => 'object']]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['settings_schema']);
});

test('the house edge is bounded to the RTP band and a change is logged with before and after RTP (PRD §11)', function () {
    $defaults = (new \App\Support\SettingsSchema(FantasySettingsSchema::definition()))->defaults();

    // 0.20 would be 80% RTP: outside the 85–98% band, refused at validation, nothing saved.
    $this->actingAs($this->platformAdmin)
        ->put("/platform/games/{$this->fantasy->uuid}/settings/global", array_merge($defaults, ['house_edge' => 0.2]))
        ->assertSessionHasErrors(['house_edge']);
    expect($this->fantasy->fresh()->settings)->not->toHaveKey('house_edge');

    \Illuminate\Support\Facades\Log::spy();
    $this->actingAs($this->platformAdmin)
        ->put("/platform/games/{$this->fantasy->uuid}/settings/global", array_merge($defaults, ['house_edge' => 0.12]))
        ->assertRedirect()
        ->assertSessionHas('success', 'Global settings updated. Theoretical RTP is now 88.00%.');
    expect((float) $this->fantasy->fresh()->settings['house_edge'])->toBe(0.12);
    \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx) => $msg === 'game.rtp_changed' && $ctx['rtp_after'] === 0.88)->once();
});

test('pick_count cannot be changed from four', function () {
    $defaults = (new \App\Support\SettingsSchema(FantasySettingsSchema::definition()))->defaults();
    $this->actingAs($this->platformAdmin)
        ->put("/platform/games/{$this->fantasy->uuid}/settings/global", array_merge($defaults, ['pick_count' => 5]))
        ->assertSessionHasErrors(['pick_count']);
});

test('v1 settings migrate to their v2 keys and the accident-prone keys are dropped', function () {
    $new = FantasySettingsSchema::migrateV1([
        'min_bet_amount' => 12, 'max_bet_amount' => 60, 'display_teams' => 50, 'winning_teams_count' => 28,
        'jackpot_percentage' => 15, 'max_jackpot_amount' => 40000, 'min_jackpot_amount' => 150,
        'round_betting_seconds' => 25, 'default_revenue_share_pct' => 65,
    ]);
    expect($new)->toEqual([
        'min_bet_amount' => 12, 'max_bet_amount' => 60, 'jackpot_cap' => 40000, 'jackpot_activation' => 150,
        'betting_seconds' => 25, 'default_revenue_share_pct' => 65,
    ]);
    expect(FantasySettingsSchema::rtp(['house_edge' => 0.1]))->toBe(0.9)
        ->and(FantasySettingsSchema::rtp([]))->toBeNull();
});
