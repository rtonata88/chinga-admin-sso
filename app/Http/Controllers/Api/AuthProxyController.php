<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Cookie-backed auth proxy. Forwards to Passport's /oauth/token internally
 * so the refresh_token can be stored in an httpOnly cookie on the parent
 * domain instead of in the browser's localStorage.
 *
 * Frontend flow:
 *   POST /api/v1/auth/login   -> sets chinga_refresh cookie, returns access_token
 *   POST /api/v1/auth/refresh -> reads cookie, rotates cookie, returns new access_token
 *   POST /api/v1/auth/logout  -> clears cookie (and revokes the refresh token)
 */
class AuthProxyController extends Controller
{
    private const REFRESH_COOKIE = 'chinga_refresh';

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'string'],
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'scope' => ['nullable', 'string'],
        ]);

        return $this->forwardToken($request, [
            'grant_type' => 'password',
            'client_id' => $validated['client_id'],
            'username' => $validated['username'],
            'password' => $validated['password'],
            'scope' => $validated['scope'] ?? '',
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $refreshToken = $request->cookie(self::REFRESH_COOKIE);
        if (!$refreshToken) {
            return response()->json(['message' => 'No refresh token.'], 401);
        }

        $clientId = $request->input('client_id');
        if (!$clientId) {
            return response()->json(['message' => 'client_id is required.'], 422);
        }

        return $this->forwardToken($request, [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $refreshToken,
            'scope' => $request->input('scope', ''),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $refreshToken = $request->cookie(self::REFRESH_COOKIE);

        if ($refreshToken) {
            $this->revokeRefreshToken($refreshToken);
        }

        return response()
            ->json(['message' => 'Logged out.'])
            ->withCookie($this->buildRefreshCookie('', -1));
    }

    private function forwardToken(Request $request, array $body): JsonResponse
    {
        $response = Http::asForm()
            ->acceptJson()
            ->post($request->root().'/oauth/token', $body);

        $payload = $response->json() ?? [];

        if (!$response->successful()) {
            return response()->json(
                $payload ?: ['message' => 'Authentication failed.'],
                $response->status()
            );
        }

        $accessToken = $payload['access_token'] ?? null;
        $refreshToken = $payload['refresh_token'] ?? null;
        $expiresIn = (int) ($payload['expires_in'] ?? 0);
        $tokenType = $payload['token_type'] ?? 'Bearer';

        if (!$accessToken) {
            return response()->json(['message' => 'Token response missing access_token.'], 500);
        }

        $json = [
            'access_token' => $accessToken,
            'token_type' => $tokenType,
            'expires_in' => $expiresIn,
        ];

        $responseBuilder = response()->json($json);

        if ($refreshToken) {
            $ttlMinutes = (int) config('passport.refresh_tokens_expire_in_minutes', 30 * 24 * 60);
            $responseBuilder = $responseBuilder->withCookie(
                $this->buildRefreshCookie($refreshToken, $ttlMinutes)
            );
        }

        return $responseBuilder;
    }

    private function buildRefreshCookie(string $value, int $minutes): Cookie
    {
        return cookie(
            name: self::REFRESH_COOKIE,
            value: $value,
            minutes: $minutes,
            path: '/',
            domain: config('auth.refresh_cookie_domain') ?: null,
            secure: (bool) config('auth.refresh_cookie_secure', true),
            httpOnly: true,
            raw: false,
            sameSite: config('auth.refresh_cookie_same_site', 'lax'),
        );
    }

    private function revokeRefreshToken(string $refreshToken): void
    {
        // Refresh tokens in Passport are JWTs signed with the app key. The easiest
        // durable revocation is to delete matching rows in oauth_refresh_tokens
        // by their token ID. We decode the JWE/JWT to extract the jti.
        try {
            $parts = explode('.', $refreshToken);
            if (count($parts) < 2) {
                return;
            }
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            if (!is_array($payload) || empty($payload['refresh_token_id'] ?? $payload['jti'] ?? null)) {
                return;
            }
            $id = $payload['refresh_token_id'] ?? $payload['jti'];
            DB::table('oauth_refresh_tokens')->where('id', $id)->update(['revoked' => true]);
        } catch (\Throwable) {
            // Best-effort; cookie clear still happens.
        }
    }
}
