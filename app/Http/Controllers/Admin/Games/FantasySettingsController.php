<?php

namespace App\Http\Controllers\Admin\Games;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FantasySettingsController extends Controller
{
    private function getFantasyGame(): Game
    {
        return Game::where('slug', 'chinga-fantasy')->firstOrFail();
    }

    public function index(Request $request)
    {
        $game = $this->getFantasyGame();

        $tenants = $game->tenants()
            ->select('tenants.id', 'tenants.uuid', 'tenants.name', 'tenants.slug')
            ->get()
            ->map(fn ($tenant) => [
                'uuid' => $tenant->uuid,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'enabled' => $tenant->pivot->enabled,
                'custom_settings' => $tenant->pivot->custom_settings ?? [],
            ]);

        return Inertia::render('admin/games/fantasy/settings', [
            'game' => [
                'uuid' => $game->uuid,
                'name' => $game->name,
                'settings' => $game->settings ?? [],
            ],
            'tenants' => $tenants,
        ]);
    }

    public function updateGlobalSettings(Request $request)
    {
        $game = $this->getFantasyGame();

        $validated = $request->validate([
            'min_bet_amount' => ['required', 'numeric', 'min:1'],
            'max_bet_amount' => ['required', 'numeric', 'min:1'],
            'display_teams' => ['required', 'integer', 'min:4', 'max:50'],
            'round_betting_seconds' => ['required', 'integer', 'min:10', 'max:300'],
            'round_results_seconds' => ['required', 'integer', 'min:5', 'max:120'],
            'round_dialog_seconds' => ['required', 'integer', 'min:5', 'max:120'],
            'min_jackpot_amount' => ['required', 'numeric', 'min:0'],
            'jackpot_percentage' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $game->update(['settings' => $validated]);
        return redirect()->back()->with('success', 'Global settings updated.');
    }

    public function updateTenantSettings(Request $request, string $tenantUuid)
    {
        $game = $this->getFantasyGame();
        $tenant = Tenant::where('uuid', $tenantUuid)->firstOrFail();

        $validated = $request->validate([
            'enabled' => ['boolean'],
            'custom_settings' => ['nullable', 'array'],
            'custom_settings.min_bet_amount' => ['nullable', 'numeric', 'min:1'],
            'custom_settings.max_bet_amount' => ['nullable', 'numeric', 'min:1'],
            'custom_settings.display_teams' => ['nullable', 'integer', 'min:4', 'max:50'],
            'custom_settings.round_betting_seconds' => ['nullable', 'integer', 'min:10'],
            'custom_settings.round_results_seconds' => ['nullable', 'integer', 'min:5'],
            'custom_settings.round_dialog_seconds' => ['nullable', 'integer', 'min:5'],
            'custom_settings.min_jackpot_amount' => ['nullable', 'numeric', 'min:0'],
            'custom_settings.jackpot_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $game->tenants()->updateExistingPivot($tenant->id, [
            'enabled' => $validated['enabled'] ?? true,
            'custom_settings' => json_encode($validated['custom_settings'] ?? []),
        ]);

        return redirect()->back()->with('success', "Settings updated for {$tenant->name}.");
    }
}
