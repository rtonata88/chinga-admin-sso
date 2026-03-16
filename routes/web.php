<?php

use App\Http\Controllers\Admin\DashboardController;
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
});

require __DIR__.'/settings.php';
