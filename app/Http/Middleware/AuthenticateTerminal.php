<?php

namespace App\Http\Middleware;

use App\Models\VenueTerminal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateTerminal
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-Terminal-Key');

        if (!$apiKey) {
            return response()->json([
                'message' => 'Terminal API key is required.',
            ], 401);
        }

        // Find terminal by hashed API key
        $hashedKey = hash('sha256', $apiKey);
        $terminal = VenueTerminal::where('api_key', $hashedKey)->first();

        if (!$terminal) {
            return response()->json([
                'message' => 'Invalid terminal API key.',
            ], 401);
        }

        if (!$terminal->isActive()) {
            return response()->json([
                'message' => 'This terminal is not active.',
            ], 403);
        }

        if (!$terminal->venue->isActive()) {
            return response()->json([
                'message' => 'This venue is not active.',
            ], 403);
        }

        // Verify terminal's venue belongs to resolved tenant
        $tenant = app('current_tenant');
        if ($tenant && $terminal->venue->tenant_id !== $tenant->id) {
            return response()->json([
                'message' => 'Terminal does not belong to this tenant.',
            ], 403);
        }

        // Attach terminal to request
        $request->merge(['terminal' => $terminal]);
        $request->setUserResolver(fn () => $terminal);

        // Record heartbeat
        $terminal->recordHeartbeat($request->ip());

        return $next($request);
    }
}
