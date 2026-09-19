<?php

namespace App\Contracts;

/**
 * The admin API every game on the platform serves (PRD §3.3,
 *
 * @chinga/game-admin-contract). Any game implementing it works with the
 * existing SSO dashboards unmodified. Resolve an instance for a game via
 * App\Services\GameAdminClientFactory.
 */
interface GameAdminClient
{
    /**
     * Probe GET /api/health without auth. Never throws: an unreachable or
     * unconfigured backend yields ['status' => 'down', 'message' => ...].
     */
    public function health(): array;

    public function statsSummary(?string $tenantUuid = null, ?string $from = null, ?string $to = null): array;

    public function statsByDay(?string $tenantUuid = null, ?string $from = null, ?string $to = null): array;

    public function statsByTenant(?string $from = null, ?string $to = null): array;

    public function listRounds(?string $tenantUuid = null, int $limit = 50, int $offset = 0): array;

    public function getRound(int $id): array;

    public function listRoundBets(int $id, int $limit = 200, int $offset = 0): array;

    public function listUserBets(string $uuid, ?string $tenantUuid = null, int $limit = 50, int $offset = 0): array;

    public function recentBets(?string $tenantUuid = null, int $limit = 10): array;
}
