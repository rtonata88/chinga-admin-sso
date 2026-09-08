<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Tenant;
use App\Support\OAuthClientGames;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant discovery for game engines (PRD §4, session 3): which tenants have
 * this game enabled. A scheduled engine runs one round loop per tenant and
 * needs this list; Fantasy never did because it creates rounds lazily.
 *
 * client_credentials + gaming:read, and the client must be bound to the
 * game through oauth_client_games.
 */
class GameTenantsController extends Controller
{
    public function index(Request $request, string $gameUuid): JsonResponse
    {
        $game = Game::where('uuid', $gameUuid)->first();
        if (! $game) {
            return response()->json(['message' => 'Game not found.'], 404);
        }

        $clientId = OAuthClientGames::clientIdFromRequest($request);
        if (! $clientId || ! OAuthClientGames::isBound($clientId, $game->id)) {
            return response()->json(['message' => 'OAuth client is not authorized for this game.'], 403);
        }

        $tenants = $game->tenants()
            ->wherePivot('enabled', true)
            ->where('tenants.status', 'active')
            ->orderBy('tenants.slug')
            ->get()
            ->map(fn (Tenant $tenant) => [
                'uuid' => $tenant->uuid,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'country_code' => $tenant->country_code,
                'currency' => $tenant->currency,
                'status' => $tenant->status,
            ])
            ->values();

        return response()->json(['data' => $tenants]);
    }
}
