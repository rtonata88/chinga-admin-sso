<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\GameAdminClientFactory;
use App\Support\GameCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cached health of every game backend the current admin can see. Consumed
 * by the GameHealthBanner on the admin layout so connectivity problems in
 * any engine surface without breaking the dashboards.
 */
class GameHealthController extends Controller
{
    public function index(Request $request, GameAdminClientFactory $factory): JsonResponse
    {
        $games = GameCatalogue::withBackendForUser($request->user());

        $data = $games->map(function (Game $game) use ($factory) {
            $health = $factory->forGame($game)->health();

            return [
                'game_uuid' => $game->uuid,
                'name' => $game->name,
                'slug' => $game->slug,
                'status' => $health['status'] ?? 'down',
                'message' => $health['message'] ?? null,
                'db' => $health['db'] ?? null,
            ];
        })->values();

        return response()->json(['data' => $data]);
    }
}
