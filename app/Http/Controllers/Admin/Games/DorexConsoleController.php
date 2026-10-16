<?php

namespace App\Http\Controllers\Admin\Games;

use App\Services\DorexAdminClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dorex consoles (X4): rounds, exposure and RTP through the shared provably-fair pages
 * ('path' rounds: how the path ended and its peak, no crash point), and a round page of its
 * own that draws the redrawn path and shows the audit that re-settled every position on it.
 */
class DorexConsoleController extends ProvablyFairConsoleController
{
    private const REVEALED = ['ENDED', 'SETTLING', 'SETTLED'];

    public function __construct(DorexAdminClient $client)
    {
        $this->client = $client;
    }

    protected function gameName(): string
    {
        return 'Dorex';
    }

    protected function base(): string
    {
        return '/dorex';
    }

    protected function terms(): array
    {
        return ['one' => 'stake', 'many' => 'stakes', 'paid_out_meta' => 'to closed stakes'];
    }

    protected function kind(): string
    {
        return 'path';
    }

    protected function revealedStates(): array
    {
        return self::REVEALED;
    }

    public function round(Request $request, int $id): Response
    {
        $round = null;
        $positions = [];
        $verify = null;
        $error = null;
        try {
            $round = $this->client->getRound($id);
            $positions = $this->client->listRoundBets($id, 500, 0)['data'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('Dorex round failed', ['id' => $id, 'error' => $e->getMessage()]);
            $error = str_contains($e->getMessage(), 'HTTP 404')
                ? "Round #{$id} was not found."
                : 'Could not load the round. Is the Dorex engine reachable?';
        }

        // The audit exists once the seed is revealed; a failure must not take the page down.
        if ($round !== null && in_array($round['state'] ?? null, self::REVEALED, true)) {
            try {
                $verify = $this->client->verifyRound($id);
            } catch (\Throwable $e) {
                Log::warning('Dorex verify failed', ['id' => $id, 'error' => $e->getMessage()]);
                $verify = ['error' => 'The path audit could not be run.'];
            }
        }

        return Inertia::render('dorex/round-detail', $this->gameProps() + [
            // Positions do not name a tenant: they are in the round's tenant's currency.
            'round' => $round === null ? null : $this->currencies()->tagOne($round),
            'positions' => $positions,
            'verify' => $verify,
            'tenantNames' => $this->tenantNames(),
            'error' => $error,
            'backHref' => $this->base().'/rounds',
        ]);
    }
}
