<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the `oauth-token` rate limiter to Passport's token endpoints only.
 * Registered globally in bootstrap/app.php; short-circuits for every other request.
 */
class ThrottleOAuthToken extends ThrottleRequests
{
    public function handle($request, Closure $next, $maxAttempts = 'oauth-token', $decayMinutes = 1, $prefix = ''): Response
    {
        if (!$this->shouldThrottle($request)) {
            return $next($request);
        }

        return parent::handle($request, $next, $maxAttempts, $decayMinutes, $prefix);
    }

    private function shouldThrottle(Request $request): bool
    {
        if ($request->method() !== 'POST') {
            return false;
        }

        $path = ltrim($request->path(), '/');

        return $path === 'oauth/token' || $path === 'oauth/token/refresh';
    }
}
