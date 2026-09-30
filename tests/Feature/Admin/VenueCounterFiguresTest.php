<?php

use App\Models\Game;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Venue;
use App\Support\FantasySettingsSchema;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('platform_admin');
    $this->tenant = Tenant::factory()->create(['name' => 'Lucky Star Betting', 'slug' => 'lucky-star-betting']);
    Game::factory()->fantasy()->create(['backend_url' => 'http://fantasy.test', 'settings_schema' => FantasySettingsSchema::definition()]);
    $this->venue = Venue::create(['tenant_id' => $this->tenant->id, 'name' => 'Tigers Bar', 'slug' => 'tigers-bar', 'address_line_1' => '1 Independence Ave', 'city' => 'Windhoek']);
});

test('the venue detail carries the counter figures from the engine, for this venue only', function () {
    Http::fake([
        'sso.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        'fantasy.test/api/admin/stats/by-venue*' => Http::response(['period' => [], 'venues' => [
            ['venue_uuid' => 'other-venue', 'tickets' => 99, 'stake' => '999.00', 'won' => '1.00', 'paid_out' => '1.00', 'unpaid_won' => '0.00', 'rounds' => 9],
            ['venue_uuid' => $this->venue->uuid, 'tickets' => 41, 'stake' => '410.00', 'won' => '236.50', 'paid_out' => '190.00', 'unpaid_won' => '46.50', 'rounds' => 22],
        ]]),
    ]);
    $this->actingAs($this->platformAdmin)
        ->getJson("/api/v1/admin/venues/{$this->venue->uuid}")
        ->assertOk()
        ->assertJsonPath('data.fantasy_counter.tickets', 41)
        ->assertJsonPath('data.fantasy_counter.stake', '410.00')
        ->assertJsonPath('data.fantasy_counter.paid_out', '190.00')
        ->assertJsonPath('data.fantasy_counter.unpaid_won', '46.50')
        ->assertJsonPath('data.fantasy_counter.period_days', 30);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/api/admin/stats/by-venue') && $r->data()['tenant_uuid'] === $this->tenant->uuid);
});

test('an unreachable engine leaves the venue detail usable with the figures marked unavailable', function () {
    Http::fake([
        'sso.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        'fantasy.test/*' => Http::response(['message' => 'down'], 503),
    ]);
    $this->actingAs($this->platformAdmin)
        ->getJson("/api/v1/admin/venues/{$this->venue->uuid}")
        ->assertOk()
        ->assertJsonPath('data.fantasy_counter', null)
        ->assertJsonPath('data.name', 'Tigers Bar');
});
