<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\Games\FantasyRoundController;
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
    Route::get('users/{uuid}', fn (string $uuid) => Inertia::render('admin/users/show', ['uuid' => $uuid]))->name('admin.users.show');
    Route::get('voucher-codes', [DashboardController::class, 'voucherCodes'])->name('admin.voucher-codes');
    Route::get('reports', [DashboardController::class, 'reports'])->name('admin.reports');
    Route::get('audit-logs', [DashboardController::class, 'auditLogs'])->name('admin.audit-logs');
    Route::get('wallets', [DashboardController::class, 'wallets'])->name('admin.wallets');
    Route::get('wallet-transactions', [DashboardController::class, 'walletTransactions'])->name('admin.wallet-transactions');
    Route::get('revenue', fn () => Inertia::render('admin/revenue'))->name('admin.revenue');

    // Tenant-scoped fantasy rounds (always scoped to the admin's tenant).
    Route::get('fantasy/rounds', [FantasyRoundController::class, 'tenantIndex'])->name('admin.fantasy.rounds');
    Route::get('fantasy/rounds/{id}', [FantasyRoundController::class, 'tenantShow'])
        ->whereNumber('id')
        ->name('admin.fantasy.rounds.show');
});

require __DIR__.'/settings.php';
