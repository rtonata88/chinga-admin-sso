<?php

namespace App\Http\Controllers\Api;

use App\Actions\Fortify\CreateNewUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class RegistrationController extends Controller
{
    private const DEFAULT_SCOPE = 'openid profile email wallet gaming:history';

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'client_id' => ['required', 'string'],
        ], [
            'client_id.required' => 'OAuth client_id is required to issue tokens.',
        ]);

        try {
            $creator = app(CreateNewUser::class);
            $user = $creator->create($request->all());
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }

        // Issue OAuth tokens via an internal password grant so the browser
        // gets both an access token AND a refresh token (delivered via the
        // same httpOnly cookie used by /api/v1/auth/login).
        $response = Http::asForm()
            ->acceptJson()
            ->post($request->root().'/oauth/token', [
                'grant_type' => 'password',
                'client_id' => $request->input('client_id'),
                'username' => $user->email,
                'password' => $request->input('password'),
                'scope' => $request->input('scope', self::DEFAULT_SCOPE),
            ]);

        if (!$response->successful()) {
            // User is created but token issuance failed — surface both.
            return response()->json([
                'user' => [
                    'uuid' => $user->uuid,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                ],
                'message' => 'Account created, but token issuance failed. Please log in.',
                'token_error' => $response->json(),
            ], 201);
        }

        $payload = $response->json() ?? [];
        $accessToken = $payload['access_token'] ?? null;
        $refreshToken = $payload['refresh_token'] ?? null;
        $expiresIn = (int) ($payload['expires_in'] ?? 0);

        $json = [
            'user' => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
            ],
            'access_token' => $accessToken,
            'token_type' => $payload['token_type'] ?? 'Bearer',
            'expires_in' => $expiresIn,
        ];

        $builder = response()->json($json, 201);

        if ($refreshToken) {
            $ttlMinutes = (int) config('passport.refresh_tokens_expire_in_minutes', 20);
            $builder = $builder->withCookie(
                AuthProxyController::buildRefreshCookie($refreshToken, $ttlMinutes)
            );
        }

        return $builder;
    }
}
