<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Services\OAuth\OpenIDConnectService;
use Illuminate\Http\JsonResponse;

class OpenIDConnectController extends Controller
{
    public function __construct(
        protected OpenIDConnectService $oidcService
    ) {}

    /**
     * Get the OpenID Connect discovery document.
     */
    public function configuration(): JsonResponse
    {
        return response()->json($this->oidcService->getDiscoveryDocument());
    }

    /**
     * Get the JSON Web Key Set.
     */
    public function jwks(): JsonResponse
    {
        return response()->json($this->oidcService->getJwks());
    }
}
