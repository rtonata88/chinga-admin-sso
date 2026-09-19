<?php

use App\Models\Game;
use App\Services\FantasyAdminClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

test('resolves the fantasy backend from games.backend_url', function () {
    Game::factory()->fantasy()->create(['backend_url' => 'http://fantasy.test/']);
    config(['services.chinga_fantasy.api_url' => 'http://should-not-be-used.test']);
    Http::fake(['fantasy.test/*' => Http::response(['status' => 'ok'])]);

    $health = app(FantasyAdminClient::class)->health();

    expect($health['status'])->toBe('ok');
    Http::assertSent(fn ($request) => $request->url() === 'http://fantasy.test/api/health');
});

test('falls back to the deprecated env-driven config when the game row has no backend_url', function () {
    Game::factory()->fantasy()->create(['backend_url' => null]);
    config(['services.chinga_fantasy.api_url' => 'http://legacy.test']);
    Http::fake(['legacy.test/*' => Http::response(['status' => 'ok'])]);
    Log::spy();

    app(FantasyAdminClient::class)->health();

    Http::assertSent(fn ($request) => $request->url() === 'http://legacy.test/api/health');
    Log::shouldHaveReceived('warning')->once();
});

test('can be constructed without touching the database', function () {
    $client = new FantasyAdminClient();

    expect($client)->toBeInstanceOf(FantasyAdminClient::class);
    $this->assertDatabaseCount('games', 0);
});
