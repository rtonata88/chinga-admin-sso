<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Services\OAuth\OpenIDConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserInfoController extends Controller
{
    public function __construct(
        protected OpenIDConnectService $oidcService
    ) {}

    /**
     * Get the current user's OpenID Connect claims.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->token();

        // Get scopes from the current access token
        $scopes = $token->scopes ?? [];

        // Build claims based on scopes
        $claims = $this->oidcService->buildUserInfoClaims($user, $scopes);

        return response()->json($claims);
    }
}
