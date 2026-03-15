<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app('current_tenant')) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant context required. Access this resource via a tenant subdomain.',
                ], 403);
            }

            abort(403, 'Tenant context required.');
        }

        return $next($request);
    }
}
