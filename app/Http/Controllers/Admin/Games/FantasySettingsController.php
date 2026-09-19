<?php

namespace App\Http\Controllers\Admin\Games;

use App\Http\Controllers\Controller;
use App\Models\Game;
use Illuminate\Http\RedirectResponse;

/**
 * Chinga Fantasy's settings are now rendered from its settings_schema by
 * GameSettingsController like every other game. These routes stay so old
 * bookmarks and the generated route helpers keep working.
 */
class FantasySettingsController extends Controller
{
    private function redirectToGeneric(): RedirectResponse
    {
        $game = Game::where('slug', 'chinga-fantasy')->firstOrFail();

        return redirect()->route('games.settings', $game);
    }

    public function index(): RedirectResponse
    {
        return $this->redirectToGeneric();
    }

    public function updateGlobalSettings(): RedirectResponse
    {
        return $this->redirectToGeneric();
    }

    public function updateTenantSettings(): RedirectResponse
    {
        return $this->redirectToGeneric();
    }
}
