<?php

namespace App\Services;

use App\Contracts\GameAdminClient;
use App\Models\Game;

/**
 * Resolves the admin client for a game by its catalogue row (PRD §4 P4).
 * Chinga Fantasy keeps its subclass for the jackpot endpoint and the
 * legacy URL fallback, Vrrr Pha for its RTP, exposure and seed-audit
 * consoles; every other game gets the generic HTTP client.
 */
class GameAdminClientFactory
{
    public function forGame(Game $game): GameAdminClient
    {
        if ($game->slug === FantasyAdminClient::GAME_SLUG) {
            return new FantasyAdminClient($game);
        }

        if ($game->slug === VrrrPhaAdminClient::GAME_SLUG) {
            return new VrrrPhaAdminClient($game);
        }

        return new HttpGameAdminClient($game);
    }

    public function forUuid(string $uuid): GameAdminClient
    {
        return $this->forGame(Game::query()->where('uuid', $uuid)->firstOrFail());
    }
}
