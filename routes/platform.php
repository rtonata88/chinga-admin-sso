<?php

use App\Http\Controllers\Platform\DashboardController;
use App\Http\Controllers\Platform\GameController;
use App\Http\Controllers\Platform\RevenueController;
use App\Http\Controllers\Platform\TenantController;
use App\Http\Controllers\Platform\TenantGameController;
use App\Http\Controllers\Platform\TenantVenueController;
use App\Http\Middleware\EnsurePlatformAdmin;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Platform Admin Routes
|--------------------------------------------------------------------------
|
| These routes are for Chinga Games platform administrators to manage
| tenants, games, revenue, and system-wide configuration.
|
*/

// Platform admin web pages
Route::middleware(['auth', 'verified', EnsurePlatformAdmin::class])->prefix('platform')->group(function () {
    Route::get('/', fn () => Inertia::render('platform/dashboard'))->name('platform.dashboard');
    Route::get('tenants', fn () => Inertia::render('platform/tenants/index'))->name('platform.tenants');
    Route::get('tenants/{uuid}', fn (string $uuid) => Inertia::render('platform/tenants/show', ['uuid' => $uuid]))->name('platform.tenants.show');
    Route::get('tenants/{uuid}/venues/{venueUuid}', fn (string $uuid, string $venueUuid) => Inertia::render('platform/tenants/venues/show', ['tenantUuid' => $uuid, 'uuid' => $venueUuid]))->name('platform.tenants.venues.show');
    Route::get('games', fn () => Inertia::render('platform/games/index'))->name('platform.games');
    Route::get('games/{uuid}', fn (string $uuid) => Inertia::render('platform/games/show', ['uuid' => $uuid]))->name('platform.games.show');
    Route::get('revenue', fn () => Inertia::render('platform/revenue/index'))->name('platform.revenue');
});

// Platform admin API routes
Route::middleware(['auth', EnsurePlatformAdmin::class])->prefix('api/v1/platform')->name('api.platform.')->group(function () {
    // Dashboard
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Tenant CRUD
    Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
    Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
    Route::get('tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
    Route::put('tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
    Route::post('tenants/{tenant}/suspend', [TenantController::class, 'suspend'])->name('tenants.suspend');
    Route::post('tenants/{tenant}/activate', [TenantController::class, 'activate'])->name('tenants.activate');
    Route::post('tenants/{tenant}/terminate', [TenantController::class, 'terminate'])->name('tenants.terminate');

    // Tenant game assignments
    Route::get('tenants/{tenant}/games', [TenantGameController::class, 'index'])->name('tenants.games.index');
    Route::post('tenants/{tenant}/games', [TenantGameController::class, 'sync'])->name('tenants.games.sync');
    Route::put('tenants/{tenant}/games/{game}', [TenantGameController::class, 'update'])->name('tenants.games.update');

    // Tenant OAuth clients
    Route::get('tenants/{tenant}/oauth-clients', [TenantController::class, 'oauthClients'])->name('tenants.oauth-clients');
    Route::post('tenants/{tenant}/oauth-clients', [TenantController::class, 'createOAuthClient'])->name('tenants.oauth-clients.store');

    // Game catalog CRUD
    Route::get('games', [GameController::class, 'index'])->name('games.index');
    Route::post('games', [GameController::class, 'store'])->name('games.store');
    Route::get('games/{game}', [GameController::class, 'show'])->name('games.show');
    Route::put('games/{game}', [GameController::class, 'update'])->name('games.update');

    // Tenant venues
    Route::get('tenants/{tenant}/venues', [TenantVenueController::class, 'index'])->name('tenants.venues.index');
    Route::post('tenants/{tenant}/venues', [TenantVenueController::class, 'store'])->name('tenants.venues.store');
    Route::get('tenants/{tenant}/venues/{uuid}', [TenantVenueController::class, 'show'])->name('tenants.venues.show');
    Route::post('tenants/{tenant}/venues/{uuid}/suspend', [TenantVenueController::class, 'suspend'])->name('tenants.venues.suspend');
    Route::post('tenants/{tenant}/venues/{uuid}/activate', [TenantVenueController::class, 'activate'])->name('tenants.venues.activate');
    Route::get('tenants/{tenant}/venues/{uuid}/staff', [TenantVenueController::class, 'staff'])->name('tenants.venues.staff');
    Route::post('tenants/{tenant}/venues/{uuid}/staff', [TenantVenueController::class, 'addStaff'])->name('tenants.venues.staff.store');
    Route::get('tenants/{tenant}/venues/{uuid}/terminals', [TenantVenueController::class, 'terminals'])->name('tenants.venues.terminals');
    Route::post('tenants/{tenant}/venues/{uuid}/terminals', [TenantVenueController::class, 'addTerminal'])->name('tenants.venues.terminals.store');

    // Revenue reports
    Route::get('revenue', [RevenueController::class, 'index'])->name('revenue.index');
    Route::get('revenue/summary', [RevenueController::class, 'summary'])->name('revenue.summary');
});
