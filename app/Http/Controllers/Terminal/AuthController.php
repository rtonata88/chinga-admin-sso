<?php

namespace App\Http\Controllers\Terminal;

use App\Http\Controllers\Controller;
use App\Models\VenueTerminal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    /**
     * Authenticate terminal with API key.
     */
    public function authenticateTerminal(Request $request): JsonResponse
    {
        $request->validate([
            'terminal_id' => ['required', 'string'],
            'venue_id' => ['required', 'string'],
        ]);

        $apiKey = $request->header('X-Terminal-Key');

        if (!$apiKey) {
            return response()->json([
                'success' => false,
                'message' => 'Terminal API key is required.',
            ], 401);
        }

        $hashedKey = hash('sha256', $apiKey);
        $terminal = VenueTerminal::where('api_key', $hashedKey)
            ->whereHas('venue', fn ($q) => $q->where('uuid', $request->input('venue_id')))
            ->where('uuid', $request->input('terminal_id'))
            ->first();

        if (!$terminal) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid terminal credentials.',
            ], 401);
        }

        if (!$terminal->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Terminal is not active.',
            ], 403);
        }

        if (!$terminal->venue->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Venue is not active.',
            ], 403);
        }

        $terminal->recordHeartbeat($request->ip());

        return response()->json([
            'success' => true,
            'message' => 'Terminal authenticated.',
            'terminal' => [
                'uuid' => $terminal->uuid,
                'name' => $terminal->name,
                'type' => $terminal->type,
            ],
            'venue' => [
                'uuid' => $terminal->venue->uuid,
                'name' => $terminal->venue->name,
                'currency' => $terminal->venue->currency,
            ],
        ]);
    }

    /**
     * Terminal heartbeat.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $terminal = $request->terminal;

        $terminal->recordHeartbeat($request->ip());

        return response()->json([
            'success' => true,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
