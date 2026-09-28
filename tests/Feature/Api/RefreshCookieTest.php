<?php

use App\Http\Controllers\Api\AuthProxyController;

test('the refresh cookie is secure on https and plain on http, and lives as long as the refresh token', function () {
    config(['auth.refresh_cookie_secure' => true, 'passport.refresh_tokens_expire_in_minutes' => 20]);
    $secure = AuthProxyController::buildRefreshCookie('tok', (int) config('passport.refresh_tokens_expire_in_minutes'));
    expect($secure->isSecure())->toBeTrue()
        ->and($secure->isHttpOnly())->toBeTrue()
        ->and($secure->getSameSite())->toBe('lax')
        ->and($secure->getExpiresTime())->toBeGreaterThan(time() + 19 * 60)
        ->and($secure->getExpiresTime())->toBeLessThanOrEqual(time() + 20 * 60 + 5);

    config(['auth.refresh_cookie_secure' => false]);
    expect(AuthProxyController::buildRefreshCookie('tok', 20)->isSecure())->toBeFalse();
});

test('the secure default follows the scheme of APP_URL', function () {
    // The config file resolves the default from APP_URL at load time; the local SSO is http.
    $config = require base_path('config/auth.php');
    expect($config['refresh_cookie_secure'])->toBe(str_starts_with((string) env('APP_URL', ''), 'https://'));
});
