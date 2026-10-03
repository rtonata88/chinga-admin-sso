<?php

namespace App\Http\Controllers\Admin\Games;

use App\Services\KulipiKunaAdminClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kulipi Kuna consoles (K4 design A1): rounds and RTP through the shared
 * provably-fair pages with ladder nouns; riding liability by level and a
 * ladder verifier on pages of their own. A ladder spans rounds, so the
 * round page never runs a round seed audit.
 */
class KulipiKunaConsoleController extends ProvablyFairConsoleController
{
    public function __construct(private readonly KulipiKunaAdminClient $kulipi)
    {
        $this->client = $kulipi;
    }

    protected function gameName(): string
    {
        return 'Kulipi Kuna';
    }

    protected function base(): string
    {
        return '/kulipi-kuna';
    }

    protected function terms(): array
    {
        return ['one' => 'ladder', 'many' => 'ladders', 'paid_out_meta' => 'to collected ladders'];
    }

    protected function kind(): string
    {
        return 'ladder';
    }

    protected function revealedStates(): array
    {
        return [];
    }

    public function riding(Request $request): Response
    {
        $tenantUuid = $this->tenantUuid($request);

        $riding = null;
        $error = null;
        try {
            $riding = $this->kulipi->riding($tenantUuid);
        } catch (\Throwable $e) {
            Log::warning('Kulipi Kuna riding failed', ['error' => $e->getMessage()]);
            $error = 'Could not load riding. Is the Kulipi Kuna engine reachable?';
        }

        return Inertia::render('kulipi-kuna/riding', $this->gameProps() + [
            'riding' => $riding,
            'tenants' => $this->tenants(),
            'filters' => ['tenant_uuid' => $tenantUuid],
            'fetchedAt' => now()->toIso8601String(),
            'error' => $error,
        ]);
    }

    public function ladder(Request $request): Response
    {
        $raw = (string) $request->query('id', '');
        $id = preg_match('/^\d{1,15}$/', $raw) === 1 ? (int) $raw : null;

        $result = null;
        $error = null;
        if ($id !== null) {
            try {
                $result = $this->kulipi->verifyLadder($id);
            } catch (\Throwable $e) {
                Log::warning('Kulipi Kuna ladder verify failed', ['id' => $id, 'error' => $e->getMessage()]);
                $error = str_contains($e->getMessage(), 'HTTP 404')
                    ? "Ladder #{$id} was not found."
                    : 'The ladder could not be verified. Is the Kulipi Kuna engine reachable?';
            }
        }

        return Inertia::render('kulipi-kuna/ladder', $this->gameProps() + [
            'ladderId' => $id,
            'result' => $result,
            // A ladder with no revealed level yet has nothing to verify: the engine
            // reports a sequence mismatch for it, which is not a verdict.
            'pending' => $result !== null && (int) ($result['steps'] ?? 0) === 0,
            'error' => $error,
        ]);
    }
}
