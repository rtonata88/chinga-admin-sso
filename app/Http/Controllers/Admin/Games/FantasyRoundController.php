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
            'backHref' => $request->query('back') === 'admin' ? '/admin/fantasy/rounds' : '/fantasy/rounds',
        ]);
    }

    /**
     * Tenant-admin variant: always scoped to the current tenant, no cross-tenant
     * dropdown. Renders the same Inertia pages with lockedTenantUuid set so the
     * UI can hide tenant-switching controls.
     */
    public function tenantIndex(Request $request)
    {
        $tenant = app('current_tenant');
        if (!$tenant) {
            abort(404, 'Tenant not found.');
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;

        $rounds = [];
        $error = null;

        try {
            $response = $this->client->listRounds($tenant->uuid, $perPage, $offset);
            $rounds = $response['data'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('FantasyRound tenantIndex failed', ['error' => $e->getMessage()]);
            $error = 'Could not load rounds. Is the fantasy backend reachable?';
        }

        return Inertia::render('fantasy/rounds', [
            'rounds' => $rounds,
            'tenants' => [],
            'filters' => [
                'tenant_uuid' => $tenant->uuid,
                'page' => $page,
                'per_page' => $perPage,
            ],
            'error' => $error,
            'lockedTenantUuid' => $tenant->uuid,
            'detailHrefBase' => '/admin/fantasy/rounds',
            'listHref' => '/admin/fantasy/rounds',
        ]);
    }

    public function tenantShow(Request $request, int $id)
    {
        $tenant = app('current_tenant');
        if (!$tenant) {
            abort(404);
        }

        $round = null;
        $bets = [];
        $error = null;

        try {
            $round = $this->client->getRound($id);
            $betsResponse = $this->client->listRoundBets($id, 500, 0);
            $bets = $betsResponse['data'] ?? [];

            // Sanity: tenant admin can only see rounds belonging to their tenant.
            if ($round && ($round['tenant_uuid'] ?? null) !== $tenant->uuid) {
                abort(404);
            }
        } catch (\Throwable $e) {
            Log::warning('FantasyRound tenantShow failed', ['id' => $id, 'error' => $e->getMessage()]);
            $error = 'Could not load round details.';
        }

        return Inertia::render('fantasy/round-detail', [
            'round' => $round,
            'bets' => $bets,
            'error' => $error,
            'backHref' => '/admin/fantasy/rounds',
        ]);
    }
}
