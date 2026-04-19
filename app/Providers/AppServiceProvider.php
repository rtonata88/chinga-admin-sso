<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register default null tenant context for artisan/queue/test contexts.
        // ResolveTenant middleware replaces this with the actual tenant via app()->instance().
        $this->app->bind('current_tenant', fn () => null);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePassport();
        $this->configureRateLimiters();
    }

    private function configureRateLimiters(): void
    {
        RateLimiter::for('oauth-token', function (Request $request) {
            return [
                Limit::perMinute(20)->by($request->ip()),
                Limit::perMinute(10)->by($request->input('username') ?? $request->input('client_id') ?? $request->ip()),
            ];
        });
    }

    /**
     * Configure Laravel Passport.
     */
    private function configurePassport(): void
    {
        // Player token lifetimes sized for a 20-minute idle logout window.
        // Active players silently refresh every ~10 minutes; if they go idle
        // for > 20 minutes, the refresh token expires and they must re-login.
        // Personal access tokens default to 6 months for venue-staff use; the
        // fantasy-voucher PAT is explicitly shortened to 20 minutes in the
        // voucher-session controller so voucher players must re-enter their
        // code after a 20-minute idle window.
        Passport::tokensExpireIn(now()->addMinutes(10));
        Passport::refreshTokensExpireIn(now()->addMinutes(20));
        Passport::personalAccessTokensExpireIn(now()->addMonths(6));

        // Enable PKCE
        Passport::enablePasswordGrant();

        // Define OAuth scopes
        Passport::tokensCan([
            'openid' => 'OpenID Connect identity claims',
            'profile' => 'Access your profile information (name, username, avatar)',
            'email' => 'Access your email address',
            'phone' => 'Access your phone number',
            'wallet' => 'Read your wallet balance',
            'wallet:write' => 'Make wallet transactions',
            'kyc' => 'Access your KYC verification status',
            'gaming:history' => 'Access your gaming history',
            'gaming:read' => 'Read gaming data (admin/reporting, service-to-service)',
            'admin' => 'Administrative access',
        ]);

        // Set default scopes for tokens without specified scopes
        Passport::setDefaultScope([
            'openid',
            'profile',
            'email',
        ]);
    }
}
