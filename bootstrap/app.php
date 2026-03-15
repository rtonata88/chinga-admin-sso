<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/venue.php'));

            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/terminal.php'));

            // Admin routes - use web middleware for session-based SPA auth
            Route::middleware('web')
                ->group(base_path('routes/admin.php'));

            // Platform admin routes
            Route::middleware('web')
                ->group(base_path('routes/platform.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->append(ResolveTenant::class);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'platform.admin' => \App\Http\Middleware\EnsurePlatformAdmin::class,
            'tenant.admin' => \App\Http\Middleware\EnsureTenantAdmin::class,
            'tenant.context' => \App\Http\Middleware\EnsureTenantContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
