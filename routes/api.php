<?php

use App\Http\Controllers\Api\KycController;
use App\Http\Controllers\Api\ResponsibleGamblingController;
use App\Http\Controllers\Auth\PhoneVerificationController;
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
    // Public phone verification (for registration)
    Route::post('phone/send-otp', [PhoneVerificationController::class, 'sendOtp'])
        ->name('api.phone.send-otp');
    Route::post('phone/verify', [PhoneVerificationController::class, 'verify'])
        ->name('api.phone.verify');

    // OAuth2 authenticated routes
    Route::middleware('auth:api')->group(function () {
        // OpenID Connect userinfo endpoint
        Route::get('oauth/userinfo', [UserInfoController::class, 'show'])
            ->name('api.oauth.userinfo');

        // Phone verification (authenticated)
        Route::post('phone/update', [PhoneVerificationController::class, 'update'])
            ->name('api.phone.update');
        Route::post('phone/resend', [PhoneVerificationController::class, 'resendOtp'])
            ->name('api.phone.resend');

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

        // KYC endpoints
        Route::prefix('kyc')->group(function () {
            Route::get('status', [KycController::class, 'status'])->name('kyc.status');
            Route::get('requirements', [KycController::class, 'requirements'])->name('kyc.requirements');
            Route::get('documents', [KycController::class, 'documents'])->name('kyc.documents');
            Route::post('documents', [KycController::class, 'upload'])->name('kyc.documents.upload');
            Route::get('documents/{uuid}', [KycController::class, 'show'])->name('kyc.documents.show');
            Route::delete('documents/{uuid}', [KycController::class, 'destroy'])->name('kyc.documents.destroy');
        });

        // Responsible Gambling endpoints
        Route::prefix('responsible-gambling')->group(function () {
            Route::get('/', [ResponsibleGamblingController::class, 'status'])->name('responsible-gambling.status');
            Route::get('options', [ResponsibleGamblingController::class, 'options'])->name('responsible-gambling.options');

            // Deposit limits
            Route::put('deposit-limits', [ResponsibleGamblingController::class, 'updateDepositLimits'])
                ->name('responsible-gambling.deposit-limits');
            Route::delete('pending-limits', [ResponsibleGamblingController::class, 'cancelPendingLimits'])
                ->name('responsible-gambling.cancel-pending');

            // Session limits
            Route::put('session-limits', [ResponsibleGamblingController::class, 'updateSessionLimits'])
                ->name('responsible-gambling.session-limits');

            // Reality check
            Route::put('reality-check', [ResponsibleGamblingController::class, 'updateRealityCheck'])
                ->name('responsible-gambling.reality-check');

            // Login restrictions
            Route::put('login-restrictions', [ResponsibleGamblingController::class, 'updateLoginRestrictions'])
                ->name('responsible-gambling.login-restrictions');

            // Self-exclusion
            Route::get('self-exclude', [ResponsibleGamblingController::class, 'selfExclusionStatus'])
                ->name('responsible-gambling.self-exclude.status');
            Route::post('self-exclude', [ResponsibleGamblingController::class, 'selfExclude'])
                ->name('responsible-gambling.self-exclude');
            Route::get('self-exclude/history', [ResponsibleGamblingController::class, 'selfExclusionHistory'])
                ->name('responsible-gambling.self-exclude.history');
        });
    });
});
