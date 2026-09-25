<?php

namespace App\Services;

use App\Contracts\ProvablyFairAdminClient;
use App\Models\Game;
use Illuminate\Support\Facades\Log;

/**
 * Chinga Fantasy's GameAdminClient: the generic HTTP client plus the
 * fantasy-only jackpot endpoint, and a lazy lookup of the fantasy Game row
 * so the class stays constructor-injectable with no arguments (it is
 * injected into controllers that serve pages with no game rows, and
 * tests that hit them).
 *
 * The legacy CHINGA_FANTASY_API_URL config key is a deprecated fallback for
 * rows that predate games.backend_url; it logs a warning so it can be retired.
 */
class FantasyAdminClient extends HttpGameAdminClient implements ProvablyFairAdminClient
{
    public const GAME_SLUG = 'chinga-fantasy';

    private bool $gameResolved;

    private ?string $resolvedBaseUrl = null;

    public function __construct(?Game $game = null)
    {
        parent::__construct($game);
        $this->gameResolved = $game !== null;
    }

    public function game(): Game
    {
        if (! $this->gameResolved) {
            $this->game = Game::query()->where('slug', self::GAME_SLUG)->first();
            $this->gameResolved = true;
        }

        return $this->game ?? new Game(['name' => 'Chinga Fantasy', 'slug' => self::GAME_SLUG]);
    }

    protected function baseUrl(): string
    {
        if ($this->resolvedBaseUrl !== null) {
            return $this->resolvedBaseUrl;
        }

        $url = $this->game()->backend_url;

        if (! is_string($url) || $url === '') {
            $url = (string) config('services.chinga_fantasy.api_url');
            Log::warning('FantasyAdminClient: games.backend_url is empty for chinga-fantasy; falling back to services.chinga_fantasy.api_url (deprecated).');
        }

        return $this->resolvedBaseUrl = rtrim($url, '/');
    }

    protected function healthCacheKey(): string
    {
        return 'game_health_probe:'.($this->game()->uuid ?? self::GAME_SLUG);
    }

    protected function label(): string
    {
        return 'Chinga Fantasy';
    }

    /** Realised RTP over the window against the edge the rounds were drawn with (v2 engine). */
    public function rtp(?string $tenantUuid = null, ?string $from = null, ?string $to = null): array
    {
        return $this->get('/api/admin/rtp', array_filter([
            'tenant_uuid' => $tenantUuid,
            'from' => $from,
            'to' => $to,
        ]));
    }

    /** Every open round's committed stake and worst case against the tenant cap (v2 engine). */
    public function exposure(?string $tenantUuid = null): array
    {
        return $this->get('/api/admin/exposure', array_filter([
            'tenant_uuid' => $tenantUuid,
        ]));
    }

    /** Seed audit: the engine re-derives the committed grid from the revealed seed (v2 engine). */
    public function verifyRound(int $id): array
    {
        return $this->get("/api/admin/rounds/{$id}/verify");
    }

    /** The v2 engine's team pool (gaming:read); v1 has no such endpoint. */
    public function listTeams(): array
    {
        return $this->get('/api/admin/teams')['data'] ?? [];
    }

    /** @param array{name: string, short_name: string, colour: string, active?: bool} $team */
    public function createTeam(array $team): array
    {
        return $this->send('POST', '/api/admin/teams', $team);
    }

    public function updateTeam(int $id, array $patch): array
    {
        return $this->send('PUT', "/api/admin/teams/{$id}", $patch);
    }

    /** Teams are never deleted (rounds reference them); the engine deactivates. */
    public function deactivateTeam(int $id): array
    {
        return $this->send('DELETE', "/api/admin/teams/{$id}");
    }

    public function listJackpotTransactions(?string $tenantUuid = null, int $limit = 50, int $offset = 0): array
    {
        return $this->get('/api/admin/jackpot-transactions', array_filter([
            'tenant_uuid' => $tenantUuid,
            'limit' => $limit,
            'offset' => $offset,
        ]));
    }
}
