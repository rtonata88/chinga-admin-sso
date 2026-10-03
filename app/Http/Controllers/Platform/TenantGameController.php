<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Tenant;
use App\Services\GameSettingsWriter;
use App\Support\SettingsSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    /**
     * Replace the tenant's game assignments. Each game's overrides are
     * validated against its schema and the Kulipi Kuna rules, and every
     * attach, change (including nulling the overrides) and detach goes
     * through GameSettingsWriter, so it is audited (K4 final review, finding 2).
     */
    public function sync(Request $request, Tenant $tenant, GameSettingsWriter $writer): JsonResponse
    {
        $validated = $request->validate([
            'games' => ['required', 'array'],
            'games.*.uuid' => ['required', 'exists:games,uuid'],
            'games.*.enabled' => ['boolean'],
            'games.*.custom_settings' => ['nullable', 'array'],
        ]);

        $wanted = [];
        foreach ($validated['games'] as $i => $gameData) {
            $game = Game::where('uuid', $gameData['uuid'])->firstOrFail();
            $prefix = "games.{$i}.custom_settings.";
            $wanted[$game->id] = [
                'game' => $game,
                'enabled' => (bool) ($gameData['enabled'] ?? true),
                'overrides' => GameSettingsWriter::validateOverrides(SettingsSchema::fromGame($game), $gameData['custom_settings'] ?? [], $prefix),
                'prefix' => $prefix,
            ];
        }

        DB::transaction(function () use ($tenant, $wanted, $writer, $request) {
            foreach ($tenant->games()->get() as $current) {
                if (! isset($wanted[$current->id])) {
                    $writer->detachTenant($current, $tenant, $request->user());
                }
            }
            foreach ($wanted as $w) {
                $writer->attachTenant($w['game'], $tenant, $w['enabled'], $w['overrides'], $request->user(), $w['prefix']);
            }
        });

        return response()->json([
            'data' => $tenant->games()->get(),
            'message' => 'Game assignments updated.',
        ]);
    }

    public function update(Request $request, Tenant $tenant, Game $game, GameSettingsWriter $writer): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['boolean'],
            'custom_settings' => ['nullable', 'array'],
        ]);

        $overrides = GameSettingsWriter::validateOverrides(SettingsSchema::fromGame($game), $validated['custom_settings'] ?? []);

        // 404 when the tenant does not have this game; nothing is written.
        $writer->writeTenant($game, $tenant, (bool) ($validated['enabled'] ?? true), $overrides, $request->user());

        return response()->json([
            'data' => $tenant->games()->where('game_id', $game->id)->first(),
        ]);
    }
}
