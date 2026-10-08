<?php

use App\Models\Game;
use App\Support\SettingsSchema;
use Database\Seeders\DorexGameSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Passport\Client;

test('seeds Dorex with the PRD §9 settings, its schema and no backend by default', function () {
    config(['services.dorex.backend_url' => null, 'services.dorex.launch_url' => null]);
    $this->seed(DorexGameSeeder::class);

    $game = Game::where('slug', 'dorex')->firstOrFail();
    expect($game->status)->toBe('development')
        ->and($game->type)->toBe('instant')
        ->and($game->backend_url)->toBeNull()
        ->and($game->settings['house_edge'])->toBe(0.04)
        ->and($game->settings['q'])->toBe(0.0012)
        ->and($game->settings['vol'])->toBe(0.04)
        ->and($game->settings['max_ticks'])->toBe(450)
        ->and($game->settings['tick_ms'])->toBe(100)
        ->and($game->settings['max_price'])->toBe(500)
        ->and($game->settings['max_win_per_position'])->toBe(16000)
        ->and($game->settings['max_total_exposure_per_round'])->toBe(25000)
        ->and($game->settings['min_bet_amount'])->toBe(10)
        ->and($game->settings['max_bet_amount'])->toBe(50)
        ->and($game->settings['betting_seconds'])->toBe(8)
        ->and($game->settings['end_display_seconds'])->toBe(3);

    SettingsSchema::assertValid($game->settings_schema);
    $schema = new SettingsSchema($game->settings_schema);
    expect($schema->keys())->toHaveCount(13)
        ->and($schema->isOverridable('house_edge'))->toBeFalse()
        ->and($schema->isOverridable('q'))->toBeFalse()
        ->and($schema->isOverridable('vol'))->toBeFalse()
        ->and($schema->isOverridable('max_bet_amount'))->toBeTrue();
});

test('the schema keeps the edge inside 2–10% and money whole', function () {
    $this->seed(DorexGameSeeder::class);
    $game = Game::where('slug', 'dorex')->firstOrFail();
    $rules = (new SettingsSchema($game->settings_schema))->rules();
    $valid = fn (array $override) => Validator::make([...$game->settings, ...$override], $rules)->passes();

    expect($valid(['house_edge' => 0.035]))->toBeTrue()
        ->and($valid(['house_edge' => 0.015]))->toBeFalse()
        ->and($valid(['house_edge' => 0.105]))->toBeFalse()
        ->and($valid(['q' => 0.02]))->toBeFalse()
        ->and($valid(['vol' => 0.001]))->toBeFalse()
        ->and($valid(['max_ticks' => 10]))->toBeFalse()
        ->and($valid(['min_bet_amount' => 10.5]))->toBeFalse()
        ->and($valid(['betting_seconds' => 4]))->toBeFalse();
});

test('seeds an engine client restricted to wallet:write and gaming:read, bound to the game, and a public web client', function () {
    $this->seed(DorexGameSeeder::class);
    $game = Game::where('slug', 'dorex')->firstOrFail();

    $engine = Client::query()->where('name', 'Dorex Engine')->where('revoked', false)->firstOrFail();
    expect($engine->grant_types)->toBe(['client_credentials'])
        ->and($engine->scopes)->toBe(['wallet:write', 'gaming:read'])
        ->and(DB::table('oauth_client_games')->where('oauth_client_id', $engine->id)->where('game_id', $game->id)->exists())->toBeTrue();

    $web = Client::query()->where('name', 'Dorex Web')->where('revoked', false)->firstOrFail();
    expect($web->grant_types)->toBe(['password', 'refresh_token'])
        ->and($web->confidential())->toBeFalse();
});

test('running the seeder twice creates nothing new, and backend URLs follow config', function () {
    $this->seed(DorexGameSeeder::class);
    $before = [Game::count(), Client::count(), DB::table('oauth_client_games')->count()];
    config(['services.dorex.backend_url' => 'http://localhost:3005', 'services.dorex.launch_url' => 'http://localhost:5177']);

    $this->seed(DorexGameSeeder::class);

    expect([Game::count(), Client::count(), DB::table('oauth_client_games')->count()])->toBe($before);
    $game = Game::where('slug', 'dorex')->firstOrFail();
    expect($game->backend_url)->toBe('http://localhost:3005')
        ->and($game->launch_url)->toBe('http://localhost:5177');
});
