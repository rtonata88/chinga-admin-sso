<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolve($request);

        app()->instance('current_tenant', $tenant);

        return $next($request);
    }

    private function resolve(Request $request): ?Tenant
    {
        // 1. Check OAuth token's client tenant_id (API requests)
        if ($request->user('api') && $request->user('api')->token()) {
            $client = $request->user('api')->token()->client;
            if ($client && $client->tenant_id) {
                return Tenant::find($client->tenant_id);
            }
        }

        // 2. Subdomain extraction
        $host = $request->getHost();
        $tenant = $this->resolveFromSubdomain($host);
        if ($tenant) {
            return $tenant;
        }

        // 3. Custom domain lookup
        $tenant = Tenant::where('domain', $host)->where('status', 'active')->first();
        if ($tenant) {
            return $tenant;
        }

        // 4. X-Tenant-ID header fallback (UUID or slug)
        $tenantHeader = $request->header('X-Tenant-ID');
        if ($tenantHeader) {
            return Tenant::where(function ($q) use ($tenantHeader) {
                $q->where('uuid', $tenantHeader)->orWhere('slug', $tenantHeader);
            })->where('status', 'active')->first();
        }

        // 5. Authenticated user's tenant_id
        $user = $request->user();
        if ($user && $user->tenant_id) {
            return Tenant::find($user->tenant_id);
        }

        return null;
    }

    private function resolveFromSubdomain(string $host): ?Tenant
    {
        // Extract subdomain from hosts like "betwin.sso.chingagames.com"
        $baseDomains = array_filter([
            config('app.base_domain', 'sso.chingagames.com'),
            parse_url(config('app.url'), PHP_URL_HOST),
            'localhost',
        ]);

        foreach ($baseDomains as $baseDomain) {
            if (str_ends_with($host, '.' . $baseDomain)) {
                $subdomain = str_replace('.' . $baseDomain, '', $host);

                if ($subdomain && $subdomain !== 'www') {
                    return Tenant::where('slug', $subdomain)
                        ->where('status', 'active')
                        ->first();
                }
            }
        }

        return null;
    }
}
