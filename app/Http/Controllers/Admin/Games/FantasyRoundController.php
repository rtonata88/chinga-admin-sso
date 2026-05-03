<?php

namespace App\Http\Controllers\Admin\Games;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\FantasyAdminClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class FantasyRoundController extends Controller
{
    public function __construct(protected FantasyAdminClient $client) {}

    public function index(Request $request)
    {
        $tenantUuid = $request->query('tenant_uuid') ?: null;
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;

        $rounds = [];
        $error = null;

        try {
            $response = $this->client->listRounds($tenantUuid, $perPage, $offset);
            $rounds = $response['data'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('FantasyRound index failed', ['error' => $e->getMessage()]);
            $error = 'Could not load rounds. Is the fantasy backend reachable?';
        }

        $tenants = Tenant::where('status', 'active')
            ->orderBy('name')
            ->get(['uuid', 'name', 'slug']);

        return Inertia::render('fantasy/rounds', [
            'rounds' => $rounds,
            'tenants' => $tenants,
            'filters' => [
                'tenant_uuid' => $tenantUuid,
                'page' => $page,
                'per_page' => $perPage,
            ],
            'error' => $error,
        ]);
    }

    public function show(Request $request, int $id)
    {
        $round = null;
        $bets = [];
        $error = null;

        try {
            $round = $this->client->getRound($id);
            $betsResponse = $this->client->listRoundBets($id, 500, 0);
            $bets = $betsResponse['data'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('FantasyRound show failed', ['id' => $id, 'error' => $e->getMessage()]);
            $error = 'Could not load round details.';
        }

        return Inertia::render('fantasy/round-detail', [
            'round' => $round,
            'bets' => $bets,
            'error' => $error,
        ]);
    }
}
