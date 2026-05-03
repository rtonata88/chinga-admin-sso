<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FantasyAdminClient;
use Illuminate\Http\JsonResponse;

/**
 * Returns the cached health status of the chinga-fantasy backend.
 * Consumed by the FantasyHealthBanner on every admin layout to surface
 * connectivity issues without breaking the dashboards.
 */
class FantasyHealthController extends Controller
{
    public function show(FantasyAdminClient $client): JsonResponse
    {
        return response()->json([
            'data' => $client->health(),
        ]);
    }
}
