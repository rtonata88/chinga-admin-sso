<?php

use App\Models\Game;
use App\Support\SettingsSchema;
use Database\Seeders\KulipiKunaGameSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Passport\Client;

test('seeds Kulipi Kuna with the PRD §9 settings, its schema and no backend by default', function () {
    config(['services.kulipi_kuna.backend_url' => null, 'services.kulipi_kuna.launch_url' => null]);
    $this->seed(KulipiKunaGameSeeder::class);

    $game = Game::where('slug', 'kulipi-kuna')->firstOrFail();
    expect($game->status)->toBe('development')
        ->and($game->type)->toBe('instant')
        ->and($game->backend_url)->toBeNull()
        ->and($game->settings['house_edge'])->toBe(0.04)
        ->and($game->settings['hard_level_cap'])->toBe(10)
        ->and($game->settings['max_win_per_ladder'])->toBe(16000)
        ->and($game->settings['max_total_riding_per_round'])->toBe(25000)
        ->and($game->settings['min_bet_amount'])->toBe(5)
        ->and($game->settings['max_bet_amount'])->toBe(50)
        ->and($game->settings['level_decision_seconds'])->toBe(6)
        ->and($game->settings['reveal_seconds'])->toBe(2);

    SettingsSchema::assertValid($game->settings_schema);
    $schema = new SettingsSchema($game->settings_schema);
    expect($schema->keys())->toHaveCount(8)
        ->and($schema->isOverridable('house_edge'))->toBeFalse()
        ->and($schema->isOverridable('hard_level_cap'))->toBeFalse()
        ->and($schema->isOverridable('max_bet_amount'))->toBeTrue();
});

test('the schema keeps the edge on the 0.005 grid inside 2–10% and money whole', function () {
    $this->seed(KulipiKunaGameSeeder::class);
    $game = Game::where('slug', 'kulipi-kuna')->firstOrFail();
    $rules = (new SettingsSchema($game->settings_schema))->rules();
    $valid = fn (array $override) => Validator::make([...$game->settings, ...$override], $rules)->passes();

    expect($valid(['house_edge' => 0.035]))->toBeTrue()
        ->and($valid(['house_edge' => 0.1]))->toBeTrue()
        ->and($valid(['house_edge' => 0.033]))->toBeFalse()
        ->and($valid(['house_edge' => 0.015]))->toBeFalse()
        ->and($valid(['house_edge' => 0.105]))->toBeFalse()
        ->and($valid(['min_bet_amount' => 5.5]))->toBeFalse()
        ->and($valid(['max_win_per_ladder' => 16000.5]))->toBeFalse()
        ->and($valid(['hard_level_cap' => 11]))->toBeFalse()
        ->and($valid(['level_decision_seconds' => 4]))->toBeFalse();
});

test('seeds an engine client restricted to wallet:write and gaming:read, bound to the game, and a public web client', function () {
    $this->seed(KulipiKunaGameSeeder::class);
    $game = Game::where('slug', 'kulipi-kuna')->firstOrFail();

    $engine = Client::query()->where('name', 'Kulipi Kuna Engine')->where('revoked', false)->firstOrFail();
    expect($engine->grant_types)->toBe(['client_credentials'])
        ->and($engine->scopes)->toBe(['wallet:write', 'gaming:read'])
        ->and(DB::table('oauth_client_games')->where('oauth_client_id', $engine->id)->where('game_id', $game->id)->exists())->toBeTrue();

    $web = Client::query()->where('name', 'Kulipi Kuna Web')->where('revoked', false)->firstOrFail();
    expect($web->grant_types)->toBe(['password', 'refresh_token'])
        ->and($web->confidential())->toBeFalse();
});

test('running the seeder twice creates nothing new, and backend URLs follow config', function () {
    $this->seed(KulipiKunaGameSeeder::class);
    $before = [Game::count(), Client::count(), DB::table('oauth_client_games')->count()];
    config(['services.kulipi_kuna.backend_url' => 'http://localhost:3004', 'services.kulipi_kuna.launch_url' => 'http://localhost:5176']);

    $this->seed(KulipiKunaGameSeeder::class);

    expect([Game::count(), Client::count(), DB::table('oauth_client_games')->count()])->toBe($before);
    $game = Game::where('slug', 'kulipi-kuna')->firstOrFail();
    expect($game->backend_url)->toBe('http://localhost:3004')
        ->and($game->launch_url)->toBe('http://localhost:5176');
});
