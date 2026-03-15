<?php

use App\Http\Controllers\Venue\AuthController;
use App\Http\Controllers\Venue\VoucherCodeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Venue Staff API Routes
|--------------------------------------------------------------------------
|
| Routes for venue staff operations (bar/pub gaming staff portal).
|
*/

// Public routes (no auth required)
Route::prefix('venue')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->name('venue.login');
    Route::post('auth/pin-login', [AuthController::class, 'pinLogin'])->name('venue.pin-login');
});

// Protected routes (staff auth required)
Route::prefix('venue')->middleware(['auth:sanctum'])->group(function () {
    // Auth
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('venue.logout');
    Route::get('profile', [AuthController::class, 'profile'])->name('venue.profile');
    Route::put('profile/password', [AuthController::class, 'changePassword'])->name('venue.change-password');
    Route::post('profile/pin', [AuthController::class, 'setPin'])->name('venue.set-pin');

    // Voucher Codes
    Route::get('codes', [VoucherCodeController::class, 'index'])->name('venue.codes.index');
    Route::post('codes', [VoucherCodeController::class, 'store'])->name('venue.codes.store');
    Route::get('codes/{code}', [VoucherCodeController::class, 'show'])->name('venue.codes.show');
    Route::get('codes/{code}/balance', [VoucherCodeController::class, 'balance'])->name('venue.codes.balance');
    Route::post('codes/{code}/load', [VoucherCodeController::class, 'load'])->name('venue.codes.load');
    Route::post('codes/{code}/cashout', [VoucherCodeController::class, 'cashout'])->name('venue.codes.cashout');
    Route::post('codes/{code}/deactivate', [VoucherCodeController::class, 'deactivate'])->name('venue.codes.deactivate');
    Route::post('codes/{code}/transfer', [VoucherCodeController::class, 'transfer'])->name('venue.codes.transfer');
    Route::post('codes/{code}/set-pin', [VoucherCodeController::class, 'setPin'])->name('venue.codes.set-pin');
    Route::post('codes/{code}/extend', [VoucherCodeController::class, 'extend'])->name('venue.codes.extend');
    Route::get('codes/{code}/transactions', [VoucherCodeController::class, 'transactions'])->name('venue.codes.transactions');
});
