<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Exceptions\AuthenticationException;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passport's scope middleware validates a client_credentials bearer token
 * but keeps nothing on the request, and $request->user() is null for a
 * machine token, so controllers had no way to learn which OAuth client is
 * calling. This validates once more (cheap: the JWT is already in memory)
 * and attaches the token's client id and scopes as request attributes.
 */
class ResolveOAuthClient
{
    public function __construct(private ResourceServer $server) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $psr = $this->server->validateAuthenticatedRequest((new PsrHttpFactory)->createRequest($request));
        } catch (OAuthServerException) {
            throw new AuthenticationException;
        }

        $token = AccessToken::fromPsrRequest($psr);
        $request->attributes->set('oauth_client_id', (string) $token->oauth_client_id);
        $request->attributes->set('oauth_scopes', $token->oauth_scopes ?? []);

        return $next($request);
    }
}
