<?php

namespace App\Services;

use App\Contracts\ProvablyFairAdminClient;
use App\Models\Game;

/**
 * Kulipi Kuna's GameAdminClient (K4 design A1): the generic HTTP client
 * plus the ladder consoles the engine exposes — standing riding liability
 * by level against the tenant's cap, realised RTP with the depth reached,
 * and the ladder verifier. A ladder spans rounds, so there is no round
 * verify: verifyRound() refuses.
 *
 * Like the other game clients it looks its Game row up lazily so it stays
 * constructor-injectable with no arguments. Tenants are always addressed
 * by uuid: the engine refuses a slug.
 */
class KulipiKunaAdminClient extends HttpGameAdminClient implements ProvablyFairAdminClient
{
    public const GAME_SLUG = 'kulipi-kuna';

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

        return $this->game ?? new Game(['name' => 'Kulipi Kuna', 'slug' => self::GAME_SLUG]);
    }

    protected function healthCacheKey(): string
    {
        return 'game_health_probe:'.($this->game()->uuid ?? self::GAME_SLUG);
    }

    protected function label(): string
    {
        return 'Kulipi Kuna';
    }

    /** Realised RTP over finished ladders against the stake-weighted theoretical figure, with the depth histogram. */
    public function rtp(?string $tenantUuid = null, ?string $from = null, ?string $to = null): array
    {
        return $this->get('/api/admin/rtp', array_filter([
            'tenant_uuid' => $tenantUuid,
            'from' => $from,
            'to' => $to,
        ]));
    }

    /** Kulipi's exposure is its riding liability; kept so shared callers of the interface still work. */
    public function exposure(?string $tenantUuid = null): array
    {
        return $this->riding($tenantUuid);
    }

    public function verifyRound(int $id): array
    {
        throw new \RuntimeException('Kulipi Kuna verifies a ladder across its rounds, not a round: use verifyLadder().');
    }

    /** Standing liability by level against the tenant's max_total_riding_per_round; alert at 80%. */
    public function riding(?string $tenantUuid = null): array
    {
        return $this->get('/api/admin/riding', array_filter([
            'tenant_uuid' => $tenantUuid,
        ]));
    }

    /** Every level of one ladder re-derived from the revealed seeds, with the engine's verdict. */
    public function verifyLadder(int $id): array
    {
        return $this->get("/api/admin/ladders/{$id}/verify");
    }
}
