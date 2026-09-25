<?php

namespace App\Http\Controllers\Admin\Games;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Tenant;
use App\Support\FantasySettingsSchema;
use App\Support\SettingsSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings for any game in the catalogue, rendered from and validated
 * against games.settings_schema (PRD §4 P3). Global values live on
 * games.settings; sparse per-tenant overrides on tenant_games.custom_settings.
 */
class GameSettingsController extends Controller
{
    public function index(Game $game): Response
    {
        $schema = SettingsSchema::fromGame($game);

        // Defaults first, saved values win, so the form always shows the
        // values actually in effect rather than blanks.
        $effective = array_merge($schema->defaults(), $game->settings ?? []);
        $rtp = FantasySettingsSchema::rtp($effective);

        $tenants = $game->tenants()
            ->select('tenants.id', 'tenants.uuid', 'tenants.name', 'tenants.slug')
            ->orderBy('tenants.name')
            ->get()
            ->map(fn (Tenant $tenant) => [
                'uuid' => $tenant->uuid,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'enabled' => (bool) $tenant->pivot->enabled,
                'custom_settings' => (object) ($tenant->pivot->custom_settings ?? []),
            ]);

        return Inertia::render('games/settings', [
            'game' => [
                'uuid' => $game->uuid,
                'name' => $game->name,
                'slug' => $game->slug,
                'settings' => (object) $effective,
                // Shown next to the form when the game prices by house edge (PRD §11).
                'theoretical_rtp' => $rtp,
            ],
            'schema' => $game->settings_schema ?? ['type' => 'object', 'properties' => (object) []],
            'tenants' => $tenants,
        ]);
    }

    public function updateGlobal(Request $request, Game $game): RedirectResponse
    {
        $schema = SettingsSchema::fromGame($game);
        $validated = $request->validate($schema->rules());
        $next = self::withoutNulls($validated);

        // PRD §11 admin guardrails: the theoretical RTP is computed on every save and every
        // change of it is logged with before and after. The allowed band is the schema's
        // house_edge bounds, so an out-of-band edge never passes validation above.
        $before = FantasySettingsSchema::rtp($game->settings ?? []);
        $after = FantasySettingsSchema::rtp($next);
        if ($after !== null && $before !== $after) {
            Log::info('game.rtp_changed', [
                'game' => $game->slug,
                'by' => $request->user()?->id,
                'house_edge_before' => $game->settings['house_edge'] ?? null,
                'house_edge_after' => $next['house_edge'],
                'rtp_before' => $before,
                'rtp_after' => $after,
            ]);
        }

        // Full replace, restricted to schema keys; a null means "unset".
        $game->update(['settings' => $next]);

        $note = $after === null ? '' : sprintf(' Theoretical RTP is now %.2f%%.', $after * 100);

        return redirect()->back()->with('success', 'Global settings updated.'.$note);
    }

    public function updateTenant(Request $request, Game $game, string $tenantUuid): RedirectResponse
    {
        $schema = SettingsSchema::fromGame($game);
        $tenant = Tenant::where('uuid', $tenantUuid)->firstOrFail();

        $rules = ['enabled' => ['boolean'], 'custom_settings' => ['nullable', 'array']];
        foreach ($schema->rules(tenantOverride: true) as $key => $set) {
            $rules["custom_settings.{$key}"] = $set;
        }
        $validated = $request->validate($rules);

        // Overrides stay sparse: only keys with a value are stored, so an
        // empty field means "inherit the global default".
        $overrides = self::withoutNulls($validated['custom_settings'] ?? []);

        $game->tenants()->updateExistingPivot($tenant->id, [
            'enabled' => $validated['enabled'] ?? true,
            'custom_settings' => $overrides === [] ? null : $overrides,
        ]);

        return redirect()->back()->with('success', "Settings updated for {$tenant->name}.");
    }

    private static function withoutNulls(array $values): array
    {
        return array_filter($values, fn ($value) => $value !== null);
    }
}
