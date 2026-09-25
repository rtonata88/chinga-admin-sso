<?php

namespace App\Contracts;

/**
 * What the provably-fair consoles need beyond GameAdminClient: realised
 * RTP against the theoretical figure, live exposure per open round, and
 * the seed audit that re-derives a round from its revealed seed.
 */
interface ProvablyFairAdminClient extends GameAdminClient
{
    public function rtp(?string $tenantUuid = null, ?string $from = null, ?string $to = null): array;

    public function exposure(?string $tenantUuid = null): array;

    public function verifyRound(int $id): array;
}
