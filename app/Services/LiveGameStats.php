<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Live stats from every game backend, one call per game, for the revenue
 * summary and the tenant overview (PRD §4 P6). A backend that is down
 * contributes an error and no rows rather than failing the page.
 */
class LiveGameStats
{
    public function __construct(private GameAdminClientFactory $factory) {}

    /**
     * statsSummary for one tenant, per game.
     *
     * @param  Collection<int, Game>  $games
     * @return list<array{game: Game, stats: array, error: ?string}>
     */
    public function summaryForTenant(Collection $games, Tenant $tenant, string $fromIso, string $toIso): array
    {
        return $games->map(function (Game $game) use ($tenant, $fromIso, $toIso) {
            try {
                $client = $this->factory->forGame($game);
                $stats = $client->statsSummary($this->tenantKey($game, $tenant), $fromIso, $toIso);

                return ['game' => $game, 'stats' => $stats, 'error' => null];
            } catch (\Throwable $e) {
                Log::warning('Live stats summary fetch failed', ['game' => $game->slug, 'error' => $e->getMessage()]);

                return ['game' => $game, 'stats' => [], 'error' => $e->getMessage()];
            }
        })->values()->all();
    }

    /**
     * statsByTenant per game.
     *
     * @param  Collection<int, Game>  $games
     * @return list<array{game: Game, rows: array, error: ?string}>
     */
    public function byTenant(Collection $games, string $fromIso, string $toIso): array
    {
        return $games->map(function (Game $game) use ($fromIso, $toIso) {
            try {
                $resp = $this->factory->forGame($game)->statsByTenant($fromIso, $toIso);

                return ['game' => $game, 'rows' => $resp['tenants'] ?? [], 'error' => null];
            } catch (\Throwable $e) {
                Log::warning('Live stats by-tenant fetch failed', ['game' => $game->slug, 'error' => $e->getMessage()]);

                return ['game' => $game, 'rows' => [], 'error' => $e->getMessage()];
            }
        })->values()->all();
    }

    /**
     * Resolver from a backend row's tenant_uuid to a local Tenant. Every
     * backend files tenants under the SSO uuid (PRD §4.3).
     *
     * @param  list<string>  $keys
     * @return \Closure(?string): ?Tenant
     */
    public function tenantResolver(array $keys): \Closure
    {
        $keys = array_values(array_unique(array_filter($keys)));
        $byUuid = Tenant::whereIn('uuid', $keys)->get()->keyBy('uuid');

        return fn (?string $key): ?Tenant => $key ? $byUuid->get($key) : null;
    }

    /** The key a game's backend files this tenant under: the SSO uuid, for every game. */
    public function tenantKey(Game $game, Tenant $tenant): string
    {
        return $tenant->uuid;
    }
}
