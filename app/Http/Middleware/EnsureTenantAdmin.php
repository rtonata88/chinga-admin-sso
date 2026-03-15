<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }
            abort(401);
        }

        // Platform admins can also access tenant admin routes
        if ($user->isPlatformAdmin()) {
            return $next($request);
        }

        if (! $user->isTenantAdmin()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Tenant admin access required.',
                ], 403);
            }

            abort(403, 'Unauthorized. Tenant admin access required.');
        }

        return $next($request);
    }
}
