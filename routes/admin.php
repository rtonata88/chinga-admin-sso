<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RoleManagementController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Admin\VenueManagementController;
use App\Http\Controllers\Admin\WalletManagementController;
use App\Http\Controllers\Admin\WalletTransactionController;
use App\Http\Middleware\EnsureTenantAdmin;
use App\Http\Middleware\IsSuperAdmin;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin API Routes
|--------------------------------------------------------------------------
|
| These routes are for admin-only functionality. All routes require
| authentication and admin privileges.
|
*/

Route::middleware(['auth', EnsureTenantAdmin::class])->prefix('api/v1/admin')->name('api.admin.')->group(function () {
    // Dashboard & Reports
    Route::get('dashboard', [ReportController::class, 'dashboard'])->name('dashboard');

    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('registrations', [ReportController::class, 'registrations'])->name('registrations');
        Route::get('logins', [ReportController::class, 'logins'])->name('logins');
        Route::get('venues', [ReportController::class, 'venues'])->name('venues');
    });

    Route::get('audit-logs', [ReportController::class, 'auditLogs'])->name('audit-logs');

    // Revenue
    Route::get('revenue', [ReportController::class, 'revenue'])->name('revenue');
    Route::get('revenue/summary', [ReportController::class, 'revenueSummary'])->name('revenue.summary');

    // Assignable Roles
    Route::get('roles', [RoleManagementController::class, 'listRoles'])->name('roles.index');

    // User Management
    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/', [UserManagementController::class, 'index'])->name('index');
        Route::get('stats', [UserManagementController::class, 'stats'])->name('stats');
        Route::get('{uuid}', [UserManagementController::class, 'show'])->name('show');
        Route::put('{uuid}', [UserManagementController::class, 'update'])->name('update');
        Route::post('{uuid}/suspend', [UserManagementController::class, 'suspend'])->name('suspend');
        Route::post('{uuid}/ban', [UserManagementController::class, 'ban'])->name('ban');
        Route::post('{uuid}/activate', [UserManagementController::class, 'activate'])->name('activate');
        Route::post('{uuid}/unlock', [UserManagementController::class, 'unlock'])->name('unlock');
        Route::post('{uuid}/reset-password', [UserManagementController::class, 'resetPassword'])->name('reset-password');
        Route::get('{uuid}/roles', [RoleManagementController::class, 'getUserRoles'])->name('roles.show');
        Route::post('{uuid}/roles', [RoleManagementController::class, 'assignRole'])->name('roles.store');
        Route::delete('{uuid}/roles/{role}', [RoleManagementController::class, 'removeRole'])->name('roles.destroy');
    });

    // Venue Management
    Route::prefix('venues')->name('venues.')->group(function () {
        Route::get('/', [VenueManagementController::class, 'index'])->name('index');
        Route::post('/', [VenueManagementController::class, 'store'])->name('store');
        Route::get('stats', [VenueManagementController::class, 'stats'])->name('stats');
        Route::get('{uuid}', [VenueManagementController::class, 'show'])->name('show');
        Route::put('{uuid}', [VenueManagementController::class, 'update'])->name('update');
        Route::delete('{uuid}', [VenueManagementController::class, 'destroy'])->name('destroy');
        Route::post('{uuid}/suspend', [VenueManagementController::class, 'suspend'])->name('suspend');
        Route::post('{uuid}/activate', [VenueManagementController::class, 'activate'])->name('activate');

        // Venue staff
        Route::get('{uuid}/staff', [VenueManagementController::class, 'staff'])->name('staff');
        Route::post('{uuid}/staff', [VenueManagementController::class, 'addStaff'])->name('staff.add');

        // Venue terminals
        Route::get('{uuid}/terminals', [VenueManagementController::class, 'terminals'])->name('terminals');
        Route::post('{uuid}/terminals', [VenueManagementController::class, 'addTerminal'])->name('terminals.add');

        // Venue voucher codes
        Route::get('{uuid}/codes', [VenueManagementController::class, 'venueCodes'])->name('codes');
        Route::post('{uuid}/codes/generate', [VenueManagementController::class, 'generateCodes'])->name('codes.generate');
        Route::post('{uuid}/codes/{codeUuid}/void', [VenueManagementController::class, 'voidCode'])->name('codes.void');
        Route::post('{uuid}/codes/{codeUuid}/add-balance', [VenueManagementController::class, 'addBalance'])->name('codes.add-balance');
    });

    // Voucher code search
    Route::get('voucher-codes', [VenueManagementController::class, 'searchCodes'])->name('voucher-codes.search');

    // Wallet Management
    Route::get('wallets', [WalletManagementController::class, 'index'])->name('wallets.index');
    Route::get('wallets/{wallet}', [WalletManagementController::class, 'show'])->name('wallets.show');
    Route::post('wallets/{wallet}/deposit', [WalletManagementController::class, 'deposit'])->name('wallets.deposit');
    Route::post('wallets/{wallet}/withdraw', [WalletManagementController::class, 'withdraw'])->name('wallets.withdraw');
    Route::post('wallets/{wallet}/freeze', [WalletManagementController::class, 'freeze'])->name('wallets.freeze');
    Route::post('wallets/{wallet}/activate', [WalletManagementController::class, 'activate'])->name('wallets.activate');

    // Wallet Transactions
    Route::get('wallet-transactions', [WalletTransactionController::class, 'index'])->name('wallet-transactions.index');
});

// Super Admin only routes
Route::middleware(['auth', IsSuperAdmin::class])->prefix('api/v1/admin')->group(function () {
    // Add any super-admin only routes here
    // e.g., system configuration, admin user management
});
