<?php

namespace App\Services;

use App\Models\Game;

/**
 * Vrrr Pha's GameAdminClient: the generic HTTP client plus the crash-game
 * consoles the engine exposes (PRD §8.3, §8.5): realised RTP against the
 * theoretical figure, live exposure per open round, and the seed audit
 * that recomputes a round's crash point from its revealed seeds.
 *
 * Like FantasyAdminClient it looks its Game row up lazily so it stays
 * constructor-injectable with no arguments. Tenants are always addressed
 * by uuid: the engine refuses a slug.
 */
class VrrrPhaAdminClient extends HttpGameAdminClient
{
    public const GAME_SLUG = 'vrrr-pha';

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

        return $this->game ?? new Game(['name' => 'Vrrr Pha', 'slug' => self::GAME_SLUG]);
    }

    protected function healthCacheKey(): string
    {
        return 'game_health_probe:'.($this->game()->uuid ?? self::GAME_SLUG);
    }

    protected function label(): string
    {
        return 'Vrrr Pha';
    }

    /** Realised RTP over the window against the house edge the rounds were drawn with. */
    public function rtp(?string $tenantUuid = null, ?string $from = null, ?string $to = null): array
    {
        return $this->get('/api/admin/rtp', array_filter([
            'tenant_uuid' => $tenantUuid,
            'from' => $from,
            'to' => $to,
        ]));
    }

    /** Every open round's committed stake against its tenant cap and its worst case. */
    public function exposure(?string $tenantUuid = null): array
    {
        return $this->get('/api/admin/exposure', array_filter([
            'tenant_uuid' => $tenantUuid,
        ]));
    }

    /** Seed audit: the engine recomputes the crash point from the persisted seeds and says whether it matches. */
    public function verifyRound(int $id): array
    {
        return $this->get("/api/admin/rounds/{$id}/verify");
    }
}
