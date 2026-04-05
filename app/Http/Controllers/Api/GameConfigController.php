<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FantasyTeam;
use App\Models\Game;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameConfigController extends Controller
{
    public function teams(Request $request, string $gameUuid): JsonResponse
    {
        $game = Game::where('uuid', $gameUuid)->first();
        if (!$game || $game->slug !== 'chinga-fantasy') {
            return response()->json(['message' => 'Game not found.'], 404);
        }

        $teams = FantasyTeam::active()
            ->select('uuid', 'name', 'short_name', 'logo_url', 'country', 'league')
            ->orderBy('name')
            ->get();

        return response()->json(['teams' => $teams]);
    }

    public function config(Request $request, string $gameUuid): JsonResponse
    {
        $game = Game::where('uuid', $gameUuid)->first();
        if (!$game) {
            return response()->json(['message' => 'Game not found.'], 404);
        }

        $globalSettings = $game->settings ?? [];
        $tenantUuid = $request->input('tenant_uuid');
        $tenantSettings = [];

        if ($tenantUuid) {
            $tenant = Tenant::where('uuid', $tenantUuid)->first();
            if ($tenant) {
                $pivot = $game->tenants()->where('tenants.id', $tenant->id)->first();
                if ($pivot) {
                    $tenantSettings = $pivot->pivot->custom_settings ?? [];
                }
            }
        }

        $mergedSettings = array_merge($globalSettings, array_filter($tenantSettings));

        return response()->json([
            'game_uuid' => $game->uuid,
            'settings' => $mergedSettings,
        ]);
    }
}
