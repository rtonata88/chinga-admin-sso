<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantGameController extends Controller
{
    public function index(Tenant $tenant): JsonResponse
    {
        $assignedGames = $tenant->games()->get();
        $availableGames = Game::where('status', 'active')
            ->whereNotIn('id', $assignedGames->pluck('id'))
            ->get();

        return response()->json([
            'assigned' => $assignedGames,
            'available' => $availableGames,
        ]);
    }

    public function sync(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'games' => ['required', 'array'],
            'games.*.uuid' => ['required', 'exists:games,uuid'],
            'games.*.enabled' => ['boolean'],
            'games.*.custom_settings' => ['nullable', 'array'],
        ]);

        $syncData = [];
        foreach ($validated['games'] as $gameData) {
            $game = Game::where('uuid', $gameData['uuid'])->first();
            $syncData[$game->id] = [
                'enabled' => $gameData['enabled'] ?? true,
                'custom_settings' => isset($gameData['custom_settings']) ? json_encode($gameData['custom_settings']) : null,
            ];
        }

        $tenant->games()->sync($syncData);

        return response()->json([
            'data' => $tenant->games()->get(),
            'message' => 'Game assignments updated.',
        ]);
    }

    public function update(Request $request, Tenant $tenant, Game $game): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['boolean'],
            'custom_settings' => ['nullable', 'array'],
        ]);

        $tenant->games()->updateExistingPivot($game->id, [
            'enabled' => $validated['enabled'] ?? true,
            'custom_settings' => isset($validated['custom_settings']) ? json_encode($validated['custom_settings']) : null,
        ]);

        return response()->json([
            'data' => $tenant->games()->where('game_id', $game->id)->first(),
        ]);
    }
}
