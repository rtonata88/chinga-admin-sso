<?php

use App\Contracts\GameAdminClient;
use App\Exceptions\MissingBackendUrlException;
use App\Models\Game;
use App\Services\FantasyAdminClient;
use App\Services\GameAdminClientFactory;
use App\Services\HttpGameAdminClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'app.url' => 'http://sso.test',
        'services.sso_internal.client_id' => 'internal-id',
        'services.sso_internal.client_secret' => 'internal-secret',
    ]);
});

function fakeBackends(array $hosts): void
{
    $fakes = ['sso.test/oauth/token' => Http::response(['access_token' => 'tok-123', 'expires_in' => 600])];
    foreach ($hosts as $host) {
        $fakes["{$host}/api/health"] = Http::response(['status' => 'ok', 'host' => $host]);
        $fakes["{$host}/*"] = Http::response(['host' => $host, 'bets_placed' => 1]);
    }
    Http::fake($fakes);
}

test('factory resolves a game by uuid to its own backend URL', function () {
    $game = Game::factory()->create(['backend_url' => 'http://engine.test/']);
    fakeBackends(['engine.test']);

    $client = app(GameAdminClientFactory::class)->forUuid($game->uuid);

    expect($client)->toBeInstanceOf(GameAdminClient::class)
        ->and($client)->toBeInstanceOf(HttpGameAdminClient::class)
        ->and($client)->not->toBeInstanceOf(FantasyAdminClient::class);

    $client->statsSummary();
    Http::assertSent(fn ($request) => $request->url() === 'http://engine.test/api/admin/stats/summary'
        && $request->hasHeader('Authorization', 'Bearer tok-123'));
});

test('factory returns the fantasy client for the chinga-fantasy slug', function () {
    $game = Game::factory()->fantasy()->create(['backend_url' => 'http://fantasy.test']);

    $client = app(GameAdminClientFactory::class)->forGame($game);

    expect($client)->toBeInstanceOf(FantasyAdminClient::class);
});

test('unknown uuid throws', function () {
    expect(fn () => app(GameAdminClientFactory::class)->forUuid('00000000-0000-0000-0000-000000000000'))
        ->toThrow(ModelNotFoundException::class);
});

test('a game without a backend URL cannot make admin calls and reports itself down', function () {
    $game = Game::factory()->create(['backend_url' => null]);
    Http::fake();

    $client = app(GameAdminClientFactory::class)->forGame($game);

    expect(fn () => $client->statsSummary())->toThrow(MissingBackendUrlException::class);
    expect($client->health()['status'])->toBe('down');
    Http::assertNothingSent();
});

test('two games resolve to two different backends with separate health caches', function () {
    $a = Game::factory()->create(['name' => 'Game A', 'backend_url' => 'http://a.test']);
    $b = Game::factory()->create(['name' => 'Game B', 'backend_url' => 'http://b.test']);
    fakeBackends(['a.test', 'b.test']);
    $factory = app(GameAdminClientFactory::class);

    expect($factory->forGame($a)->health()['host'])->toBe('a.test')
        ->and($factory->forGame($b)->health()['host'])->toBe('b.test');

    $factory->forGame($a)->listRounds(null, 5, 0);
    $factory->forGame($b)->listUserBets('user-1');

    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'http://a.test/api/admin/rounds'));
    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'http://b.test/api/admin/users/user-1/bets'));
});

test('the contract exposes the PRD admin endpoint set', function () {
    $methods = array_map(fn ($m) => $m->getName(), (new ReflectionClass(GameAdminClient::class))->getMethods());
    sort($methods);

    expect($methods)->toBe([
        'getRound', 'health', 'listRoundBets', 'listRounds', 'listUserBets',
        'recentBets', 'statsByDay', 'statsByTenant', 'statsSummary',
    ]);
});

test('a backend HTTP error surfaces as a RuntimeException naming the game', function () {
    $game = Game::factory()->create(['name' => 'Broken Game', 'backend_url' => 'http://broken.test']);
    Http::fake([
        'sso.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        'broken.test/*' => Http::response(['message' => 'nope'], 500),
    ]);

    expect(fn () => app(GameAdminClientFactory::class)->forGame($game)->recentBets())
        ->toThrow(RuntimeException::class, 'Broken Game');
});
