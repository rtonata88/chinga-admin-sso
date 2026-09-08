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
        if (! $game || $game->slug !== 'chinga-fantasy') {
            return response()->json(['message' => 'Game not found.'], 404);
        }

        $teams = FantasyTeam::active()
            ->select(
                'uuid', 'name', 'short_name', 'logo_url', 'country', 'league',
                'mascot', 'display_name', 'icon_slug', 'accent_color'
            )
            ->orderBy('name')
            ->get();

        return response()->json(['teams' => $teams]);
    }

    public function config(Request $request, string $gameUuid): JsonResponse
    {
        $game = Game::where('uuid', $gameUuid)->first();
        if (! $game) {
            return response()->json(['message' => 'Game not found.'], 404);
        }

        $globalSettings = $game->settings ?? [];
        $tenantUuid = $request->input('tenant_uuid');
        $tenantSettings = [];
        $matchedTenant = null;

        if ($tenantUuid) {
            // Strict UUID match. An unknown tenant is an error, not "global
            // settings": an engine must never run a tenant on defaults by
            // accident (a slug here used to silently fall through).
            $matchedTenant = Tenant::where('uuid', $tenantUuid)->first();
            if (! $matchedTenant) {
                return response()->json(['message' => 'Tenant not found.'], 404);
            }
            $pivot = $game->tenants()->where('tenants.id', $matchedTenant->id)->first();
            if ($pivot) {
                $tenantSettings = $pivot->pivot->custom_settings ?? [];
            }
        }

        // Only a null override means "inherit"; false and 0 are real values
        // (auto_cashout_enabled = false must win over the global true).
        $overrides = array_filter(is_array($tenantSettings) ? $tenantSettings : [], fn ($value) => $value !== null);
        $mergedSettings = array_merge($globalSettings, $overrides);

        return response()->json([
            'game_uuid' => $game->uuid,
            'tenant_uuid' => $matchedTenant?->uuid,
            'settings' => $mergedSettings,
        ]);
    }
}
