<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Applies the `oauth-token` rate limiter to Passport's token endpoints only.
 * Registered globally in bootstrap/app.php; short-circuits for every other request.
 */
class ThrottleOAuthToken extends ThrottleRequests
{
    public function handle($request, Closure $next, $maxAttempts = 'oauth-token', $decayMinutes = 1, $prefix = '')
    {
        if (!$this->shouldThrottle($request)) {
            return $next($request);
        }

        // ThrottleRequests::handle() only takes the named-limiter branch when
        // called with exactly 3 arguments (func_num_args() === 3). Pass just
        // three so the base class looks up our registered limiter.
        return parent::handle($request, $next, $maxAttempts);
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
