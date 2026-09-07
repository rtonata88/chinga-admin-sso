<?php

namespace App\Support;

use App\Models\Game;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Which games a user administers, from the catalogue rather than a
 * hardcoded list (PRD §4 P5–P7). Platform admins see every active or
 * in-development game; tenant admins see the active games enabled for
 * their tenant; everyone else sees none.
 */
class GameCatalogue
{
    /** @return Collection<int, Game> */
    public static function forUser(?User $user, ?Tenant $tenant = null): Collection
    {
        if ($user === null) {
            return new Collection;
        }

        if ($user->isPlatformAdmin()) {
            return Game::query()
                ->whereIn('status', ['active', 'development'])
                ->orderBy('name')
                ->get();
        }

        $tenant ??= app('current_tenant') ?? $user->tenant;
        if ($tenant === null || ! $user->isTenantAdmin($tenant->id)) {
            return new Collection;
        }

        return $tenant->enabledGames()
            ->where('games.status', 'active')
            ->orderBy('games.name')
            ->get();
    }

    /** Games with an admin backend to probe, from the user's catalogue. @return Collection<int, Game> */
    public static function withBackendForUser(?User $user, ?Tenant $tenant = null): Collection
    {
        return self::forUser($user, $tenant)->filter(fn (Game $game) => $game->hasBackend())->values();
    }
}
