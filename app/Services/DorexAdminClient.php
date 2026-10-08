<?php

namespace App\Services;

use App\Contracts\ProvablyFairAdminClient;
use App\Models\Game;

/**
 * Dorex's GameAdminClient (X4): the generic HTTP client plus the consoles the engine
 * exposes (PRD §10): realised RTP against the start price with its standard error, live
 * exposure per open round, and the seed audit that redraws the whole path from the
 * persisted seed and re-settles every position on it.
 *
 * Like FantasyAdminClient it looks its Game row up lazily so it stays
 * constructor-injectable with no arguments. Tenants are always addressed
 * by uuid: the engine refuses a slug.
 */
class DorexAdminClient extends HttpGameAdminClient implements ProvablyFairAdminClient
{
    public const GAME_SLUG = 'dorex';

    private bool $gameResolved;

    public function __construct(?Game $game = null)
    {
        parent::__construct($game);
        $this->gameResolved = $game !== null;
    }

    public function game(): Game
    {
        if (! $this->gameResolved) {
            $this->game = Game::query()->where('slug', self::GAME_SLUG)->first();
            $this->gameResolved = true;
        }

        return $this->game ?? new Game(['name' => 'Dorex', 'slug' => self::GAME_SLUG]);
    }

    protected function healthCacheKey(): string
    {
        return 'game_health_probe:'.($this->game()->uuid ?? self::GAME_SLUG);
    }

    protected function label(): string
    {
        return 'Dorex';
    }

    /** Realised RTP over closed positions against the start price, with its standard error. */
    public function rtp(?string $tenantUuid = null, ?string $from = null, ?string $to = null): array
    {
        return $this->get('/api/admin/rtp', array_filter([
            'tenant_uuid' => $tenantUuid,
            'from' => $from,
            'to' => $to,
        ]));
    }

    /** Every open round's stake against its tenant cap, and each open stake paid at its ceiling. */
    public function exposure(?string $tenantUuid = null): array
    {
        return $this->get('/api/admin/exposure', array_filter([
            'tenant_uuid' => $tenantUuid,
        ]));
    }

    /** Seed audit: the engine redraws the path from the persisted seed and re-settles every closed position. */
    public function verifyRound(int $id): array
    {
        return $this->get("/api/admin/rounds/{$id}/verify");
    }
}
