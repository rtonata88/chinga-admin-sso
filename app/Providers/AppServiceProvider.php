<?php

namespace App\Providers;

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
    }

    /**
     * Configure Laravel Passport.
     */
    private function configurePassport(): void
    {
        // Token expiration times
        Passport::tokensExpireIn(now()->addHour());
        Passport::refreshTokensExpireIn(now()->addDays(30));
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
