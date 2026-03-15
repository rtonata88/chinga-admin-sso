<?php

use App\Http\Controllers\Terminal\AuthController;
use App\Http\Controllers\Terminal\PlayerController;
use App\Http\Middleware\AuthenticateTerminal;
use App\Http\Middleware\AuthenticateVoucherSession;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Terminal API Routes
|--------------------------------------------------------------------------
|
| Routes for game terminals/kiosks at venues.
|
*/

// Terminal authentication
Route::prefix('terminal')->group(function () {
    Route::post('auth', [AuthController::class, 'authenticateTerminal'])->name('terminal.auth');
});

// Routes requiring terminal authentication
Route::prefix('terminal')->middleware([AuthenticateTerminal::class])->group(function () {
    Route::post('heartbeat', [AuthController::class, 'heartbeat'])->name('terminal.heartbeat');
});

// Voucher code authentication (for games)
Route::prefix('venue/auth')->middleware([AuthenticateTerminal::class])->group(function () {
    Route::post('code', [AuthController::class, 'authenticateCode'])->name('venue.code.auth');
});

// Player routes (require active voucher session)
Route::prefix('venue/player')->middleware([AuthenticateVoucherSession::class])->group(function () {
    Route::get('balance', [PlayerController::class, 'balance'])->name('venue.player.balance');
    Route::get('can-play', [PlayerController::class, 'canPlay'])->name('venue.player.can-play');
    Route::post('debit', [PlayerController::class, 'debit'])->name('venue.player.debit');
    Route::post('credit', [PlayerController::class, 'credit'])->name('venue.player.credit');
    Route::post('transaction', [PlayerController::class, 'transaction'])->name('venue.player.transaction');
    Route::get('transactions', [PlayerController::class, 'transactions'])->name('venue.player.transactions');
});

// Session management (require active voucher session)
Route::prefix('venue/auth/code')->middleware([AuthenticateVoucherSession::class])->group(function () {
    Route::post('verify-pin', [AuthController::class, 'verifyPin'])->name('venue.code.verify-pin');
    Route::post('logout', [AuthController::class, 'logout'])->name('venue.code.logout');
    Route::get('session', [AuthController::class, 'sessionInfo'])->name('venue.code.session');
});
