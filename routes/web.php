<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\Games\FantasyRoundController;
use App\Http\Controllers\Admin\TenantInvoiceController;
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

// Tenant overview lives at its own URL — the page identity is the
// tenant's overview, not "admin". The other admin operational pages
// stay under /admin/* (Users, Wallets, Withdrawals, …) since those
// are operations rather than dashboards.
Route::middleware(['auth', 'verified', EnsureTenantAdmin::class])->group(function () {
    Route::get('tenant-overview', [DashboardController::class, 'index'])->name('admin.dashboard');
    Route::get('tenant-overview/{tenant_uuid}/invoice', [TenantInvoiceController::class, 'show'])
        ->name('admin.tenant.invoice');
    // Backwards-compat redirect for /admin bookmarks. Keep until we're
    // confident no external link points here.
    Route::get('admin', fn () => redirect('/tenant-overview'));
});

// Admin operations
Route::middleware(['auth', 'verified', EnsureTenantAdmin::class])->prefix('admin')->group(function () {
    Route::get('users', [DashboardController::class, 'users'])->name('admin.users');
    Route::get('users/{uuid}', fn (string $uuid) => Inertia::render('admin/users/show', ['uuid' => $uuid]))->name('admin.users.show');
    Route::get('voucher-codes', [DashboardController::class, 'voucherCodes'])->name('admin.voucher-codes');
    Route::get('reports', [DashboardController::class, 'reports'])->name('admin.reports');
    Route::get('audit-logs', [DashboardController::class, 'auditLogs'])->name('admin.audit-logs');
    Route::get('wallets', [DashboardController::class, 'wallets'])->name('admin.wallets');
    Route::get('wallet-transactions', [DashboardController::class, 'walletTransactions'])->name('admin.wallet-transactions');
    Route::get('withdrawals', [DashboardController::class, 'withdrawals'])->name('admin.withdrawals');
    Route::get('revenue', fn () => Inertia::render('admin/revenue'))->name('admin.revenue');

    // Venue management — tenant-scoped automatically via Venue's
    // BelongsToTenant global scope (the admin venue API at
    // /api/v1/admin/venues/* is what these pages talk to). Platform
    // admins continue to use /platform/tenants/{uuid}/venues/{uuid}
    // for cross-tenant work; tenant admins live here.
    Route::get('venues', fn () => Inertia::render('admin/venues/index'))->name('admin.venues');
    Route::get('venues/{uuid}', fn (string $uuid) => Inertia::render('admin/venues/show', ['uuid' => $uuid]))->name('admin.venues.show');

    // Tenant-scoped fantasy rounds (always scoped to the admin's tenant).
    Route::get('fantasy/rounds', [FantasyRoundController::class, 'tenantIndex'])->name('admin.fantasy.rounds');
    Route::get('fantasy/rounds/{id}', [FantasyRoundController::class, 'tenantShow'])
        ->whereNumber('id')
        ->name('admin.fantasy.rounds.show');
});

require __DIR__.'/settings.php';
