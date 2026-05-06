<?php

namespace App\Http\Controllers\Admin\Games;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FantasySettingsController extends Controller
{
    /**
     * Effective defaults the chinga-fantasy game backend falls back to when
     * a setting isn't saved in SSO. Mirrors gameConfigService.DEFAULTS in
     * chinga-fantasy/app/services/gameConfigService.js so the admin form
     * always shows the values currently in use, not blanks.
     *
     * If you change either side, change both — they're a contract between
     * the two services.
     */
    private const DEFAULTS = [
        'min_bet_amount' => 5,
        'max_bet_amount' => 50,
        'display_teams' => 50,
        'round_betting_seconds' => 30,
        'round_results_seconds' => 30,
        'round_dialog_seconds' => 30,
        'min_jackpot_amount' => 100,
        'max_jackpot_amount' => 50000,
        'jackpot_percentage' => 15,
        'winning_teams_count' => 20,
        // Commercial defaults applied to NEW tenants of this game.
        'default_business_model' => 'reseller',
        'default_revenue_share_pct' => 70,
        'default_tax_pct' => 0,
        'house_edge_target_pct' => 5,
    ];

    private function getFantasyGame(): Game
    {
        return Game::where('slug', 'chinga-fantasy')->firstOrFail();
    }

    public function index(Request $request)
    {
        $game = $this->getFantasyGame();

        // Merge defaults with whatever is saved so the form always renders
        // the current effective values. Saved values win on conflict.
        $effectiveSettings = array_merge(self::DEFAULTS, $game->settings ?? []);

        $tenants = $game->tenants()
            ->select('tenants.id', 'tenants.uuid', 'tenants.name', 'tenants.slug')
            ->get()
            ->map(fn ($tenant) => [
                'uuid' => $tenant->uuid,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'enabled' => $tenant->pivot->enabled,
                // Tenant overrides stay sparse — empty means "inherit global".
                'custom_settings' => $tenant->pivot->custom_settings ?? [],
            ]);

        return Inertia::render('fantasy/settings', [
            'game' => [
                'uuid' => $game->uuid,
                'name' => $game->name,
                'settings' => $effectiveSettings,
            ],
            'defaults' => self::DEFAULTS,
            'tenants' => $tenants,
        ]);
    }

    public function updateGlobalSettings(Request $request)
    {
        $game = $this->getFantasyGame();

        $validated = $request->validate([
            'min_bet_amount' => ['required', 'numeric', 'min:1'],
            'max_bet_amount' => ['required', 'numeric', 'min:1'],
            'display_teams' => ['required', 'integer', 'min:4', 'max:100'],
            'round_betting_seconds' => ['required', 'integer', 'min:10', 'max:300'],
            'round_results_seconds' => ['required', 'integer', 'min:5', 'max:120'],
            'round_dialog_seconds' => ['required', 'integer', 'min:5', 'max:120'],
            'min_jackpot_amount' => ['required', 'numeric', 'min:0'],
            'max_jackpot_amount' => ['required', 'numeric', 'min:0'],
            'jackpot_percentage' => ['required', 'integer', 'min:0', 'max:100'],
            'winning_teams_count' => ['required', 'integer', 'min:4', 'max:50'],
            // Commercial defaults applied to NEW tenants of this game.
            'default_business_model' => ['nullable', 'in:reseller,direct'],
            'default_revenue_share_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'default_tax_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'house_edge_target_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
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
            'custom_settings.display_teams' => ['nullable', 'integer', 'min:4', 'max:100'],
            'custom_settings.round_betting_seconds' => ['nullable', 'integer', 'min:10'],
            'custom_settings.round_results_seconds' => ['nullable', 'integer', 'min:5'],
            'custom_settings.round_dialog_seconds' => ['nullable', 'integer', 'min:5'],
            'custom_settings.min_jackpot_amount' => ['nullable', 'numeric', 'min:0'],
            'custom_settings.max_jackpot_amount' => ['nullable', 'numeric', 'min:0'],
            'custom_settings.jackpot_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
            'custom_settings.winning_teams_count' => ['nullable', 'integer', 'min:4', 'max:50'],
        ]);

        $game->tenants()->updateExistingPivot($tenant->id, [
            'enabled' => $validated['enabled'] ?? true,
            'custom_settings' => json_encode($validated['custom_settings'] ?? []),
        ]);

        return redirect()->back()->with('success', "Settings updated for {$tenant->name}.");
    }
}
