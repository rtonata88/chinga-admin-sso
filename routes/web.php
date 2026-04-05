<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\Games\FantasyRoundController;
use App\Http\Controllers\Admin\Games\FantasySettingsController;
use App\Http\Controllers\Admin\Games\FantasyTeamController;
use App\Http\Controllers\UserDashboardController;
use App\Http\Middleware\EnsureTenantAdmin;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return redirect('/login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', UserDashboardController::class)->name('dashboard');
});

// Admin routes
Route::middleware(['auth', 'verified', EnsureTenantAdmin::class])->prefix('admin')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('admin.dashboard');
    Route::get('users', [DashboardController::class, 'users'])->name('admin.users');
    Route::get('voucher-codes', [DashboardController::class, 'voucherCodes'])->name('admin.voucher-codes');
    Route::get('reports', [DashboardController::class, 'reports'])->name('admin.reports');
    Route::get('audit-logs', [DashboardController::class, 'auditLogs'])->name('admin.audit-logs');
    Route::get('wallets', [DashboardController::class, 'wallets'])->name('admin.wallets');
    Route::get('wallet-transactions', [DashboardController::class, 'walletTransactions'])->name('admin.wallet-transactions');
    Route::get('revenue', fn () => Inertia::render('admin/revenue'))->name('admin.revenue');

    // Fantasy Game Management
    Route::prefix('games/fantasy')->group(function () {
        Route::get('teams', [FantasyTeamController::class, 'index'])->name('admin.games.fantasy.teams');
        Route::post('teams', [FantasyTeamController::class, 'store'])->name('admin.games.fantasy.teams.store');
        Route::put('teams/{team}', [FantasyTeamController::class, 'update'])->name('admin.games.fantasy.teams.update');
        Route::delete('teams/{team}', [FantasyTeamController::class, 'destroy'])->name('admin.games.fantasy.teams.destroy');
        Route::post('teams/bulk-toggle', [FantasyTeamController::class, 'bulkToggle'])->name('admin.games.fantasy.teams.bulk-toggle');

        Route::get('settings', [FantasySettingsController::class, 'index'])->name('admin.games.fantasy.settings');
        Route::put('settings/global', [FantasySettingsController::class, 'updateGlobalSettings'])->name('admin.games.fantasy.settings.global');
        Route::put('settings/tenant/{tenantUuid}', [FantasySettingsController::class, 'updateTenantSettings'])->name('admin.games.fantasy.settings.tenant');

        Route::get('rounds', [FantasyRoundController::class, 'index'])->name('admin.games.fantasy.rounds');
    });
});

require __DIR__.'/settings.php';
