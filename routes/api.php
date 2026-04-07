<?php

use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\OAuth\OAuthClientController;
use App\Http\Controllers\OAuth\OpenIDConnectController;
use App\Http\Controllers\OAuth\UserInfoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group.
|
*/

// OpenID Connect Discovery (no auth required)
Route::get('.well-known/openid-configuration', [OpenIDConnectController::class, 'configuration']);
Route::get('.well-known/jwks.json', [OpenIDConnectController::class, 'jwks']);

Route::prefix('v1')->group(function () {
    // Public endpoints
    Route::post('register', [RegistrationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('api.register');

    // OAuth2 authenticated routes
    Route::middleware('auth:api')->group(function () {
        // OpenID Connect userinfo endpoint
        Route::get('oauth/userinfo', [UserInfoController::class, 'show'])
            ->name('api.oauth.userinfo');

        // OAuth Client management (for game developers)
        Route::prefix('oauth/clients')->group(function () {
            Route::get('/', [OAuthClientController::class, 'index'])
                ->name('api.oauth.clients.index');
            Route::post('/', [OAuthClientController::class, 'store'])
                ->name('api.oauth.clients.store');
            Route::get('{id}', [OAuthClientController::class, 'show'])
                ->name('api.oauth.clients.show');
            Route::put('{id}', [OAuthClientController::class, 'update'])
                ->name('api.oauth.clients.update');
            Route::delete('{id}', [OAuthClientController::class, 'destroy'])
                ->name('api.oauth.clients.destroy');
            Route::post('{id}/regenerate-secret', [OAuthClientController::class, 'regenerateSecret'])
                ->name('api.oauth.clients.regenerate-secret');
        });

        // User profile endpoint
        Route::get('user', function (\Illuminate\Http\Request $request) {
            return $request->user();
        })->name('api.user');

    });

    // Tenant resolution (public, used by game frontends)
    Route::post('tenant/resolve', function (\Illuminate\Http\Request $request) {
        $slug = $request->input('slug') ?? $request->header('X-Tenant-ID');

        if (!$slug) {
            return response()->json(['message' => 'Tenant slug is required.'], 422);
        }

        $tenant = \App\Models\Tenant::where('slug', $slug)
            ->where('status', 'active')
            ->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found.'], 404);
        }

        return response()->json([
            'tenant' => [
                'uuid' => $tenant->uuid,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'currency' => $tenant->currency,
                'country_code' => $tenant->country_code,
            ],
        ]);
    })->name('api.tenant.resolve');

    // Game server config endpoints (read-only, no auth required)
    Route::get('games/{gameUuid}/teams', [\App\Http\Controllers\Api\GameConfigController::class, 'teams'])->name('api.games.teams');
    Route::get('games/{gameUuid}/config', [\App\Http\Controllers\Api\GameConfigController::class, 'config'])->name('api.games.config');
});
