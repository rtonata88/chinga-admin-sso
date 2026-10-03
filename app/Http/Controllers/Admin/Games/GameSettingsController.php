<?php

namespace App\Http\Controllers\Admin\Games;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Tenant;
use App\Services\GameSettingsAuditor;
use App\Services\GameSettingsWriter;
use App\Support\FantasySettingsSchema;
use App\Support\SettingsSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings for any game in the catalogue, rendered from and validated
 * against games.settings_schema (PRD §4 P3). Global values live on
 * games.settings; sparse per-tenant overrides on tenant_games.custom_settings.
 */
class GameSettingsController extends Controller
{
    public function index(Game $game, GameSettingsAuditor $auditor): Response
    {
        $schema = SettingsSchema::fromGame($game);

        // Defaults first, saved values win, so the form always shows the
        // values actually in effect rather than blanks.
        $effective = array_merge($schema->defaults(), $game->settings ?? []);
        $rtp = FantasySettingsSchema::rtp($effective);
        $expectedWinners = $rtp === null ? null : FantasySettingsSchema::expectedWinners($effective);

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
                // Average winners per round, set by grid size, tier weights and house edge (outcomes stay independent).
                'expected_winners' => $expectedWinners,
                'grid_size' => isset($effective['grid_size']) ? (int) $effective['grid_size'] : null,
            ],
            'schema' => $game->settings_schema ?? ['type' => 'object', 'properties' => (object) []],
            'tenants' => $tenants,
            'audits' => $auditor->recent($game),
        ]);
    }

    public function updateGlobal(Request $request, Game $game, GameSettingsWriter $writer): RedirectResponse
    {
        $schema = SettingsSchema::fromGame($game);
        $validated = $request->validate($schema->rules());

        // Full replace, restricted to schema keys; a null means "unset". The writer
        // locks, applies the Kulipi Kuna rules, logs any RTP change and audits.
        $after = $writer->writeGlobal($game, self::withoutNulls($validated), $request->user());

        $note = $after === null ? '' : sprintf(' Theoretical RTP is now %.2f%%.', $after * 100);

        return redirect()->back()->with('success', 'Global settings updated.'.$note);
    }

    public function updateTenant(Request $request, Game $game, string $tenantUuid, GameSettingsWriter $writer): RedirectResponse
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

        // 404 when the tenant does not have this game; nothing is written.
        $writer->writeTenant($game, $tenant, (bool) ($validated['enabled'] ?? true), $overrides, $request->user());

        return redirect()->back()->with('success', "Settings updated for {$tenant->name}.");
    }

    private static function withoutNulls(array $values): array
    {
        return array_filter($values, fn ($value) => $value !== null);
    }
}
