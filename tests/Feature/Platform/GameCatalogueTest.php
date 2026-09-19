<?php

use App\Models\Game;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('platform_admin');
});

test('platform admin can register a game with its own backend and launch URLs', function () {
    $response = $this->actingAs($this->platformAdmin)->postJson('/api/v1/platform/games', [
        'name' => 'Vrrr Pha',
        'slug' => 'vrrr-pha',
        'type' => 'instant',
        'status' => 'development',
        'backend_url' => 'http://localhost:3002',
        'launch_url' => 'http://localhost:5174',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.backend_url', 'http://localhost:3002')
        ->assertJsonPath('data.launch_url', 'http://localhost:5174');

    $this->assertDatabaseHas('games', [
        'slug' => 'vrrr-pha',
        'backend_url' => 'http://localhost:3002',
        'launch_url' => 'http://localhost:5174',
    ]);
});

test('backend and launch URLs can be updated and round-trip through show', function () {
    $game = Game::factory()->create();

    $this->actingAs($this->platformAdmin)
        ->putJson("/api/v1/platform/games/{$game->uuid}", [
            'backend_url' => 'https://engine.example.test',
            'launch_url' => 'https://play.example.test',
        ])
        ->assertOk()
        ->assertJsonPath('data.backend_url', 'https://engine.example.test');

    $this->actingAs($this->platformAdmin)
        ->getJson("/api/v1/platform/games/{$game->uuid}")
        ->assertOk()
        ->assertJsonPath('data.backend_url', 'https://engine.example.test')
        ->assertJsonPath('data.launch_url', 'https://play.example.test');
});

test('malformed backend URL is rejected', function () {
    $game = Game::factory()->create();

    $this->actingAs($this->platformAdmin)
        ->putJson("/api/v1/platform/games/{$game->uuid}", ['backend_url' => 'not a url'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['backend_url']);
});

test('backend URL may be cleared', function () {
    $game = Game::factory()->create(['backend_url' => 'http://old.test']);

    $this->actingAs($this->platformAdmin)
        ->putJson("/api/v1/platform/games/{$game->uuid}", ['backend_url' => null])
        ->assertOk();

    expect($game->fresh()->backend_url)->toBeNull();
});
