<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The per-client game binding (oauth_client_games): an OAuth client may
 * only act on behalf of games it has been authorised for.
 */
class OAuthClientGames
{
    public static function isBound(string $clientId, int $gameId): bool
    {
        return DB::table('oauth_client_games')
            ->where('oauth_client_id', $clientId)
            ->where('game_id', $gameId)
            ->exists();
    }

    /** Client id attached by ResolveOAuthClient, or null when the route lacks it. */
    public static function clientIdFromRequest(Request $request): ?string
    {
        $id = $request->attributes->get('oauth_client_id');

        return is_string($id) && $id !== '' ? $id : null;
    }
}
