<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateVenueStaff
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $staff = $request->user('venue-staff');

        if (!$staff) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!$staff->isActive()) {
            return response()->json([
                'message' => 'Your account is not active.',
            ], 403);
        }

        if (!$staff->venue->isActive()) {
            return response()->json([
                'message' => 'This venue is not active.',
            ], 403);
        }

        // Verify staff's venue belongs to resolved tenant
        $tenant = app('current_tenant');
        if ($tenant && $staff->venue->tenant_id !== $tenant->id) {
            return response()->json([
                'message' => 'Staff does not belong to this tenant.',
            ], 403);
        }

        return $next($request);
    }
}
