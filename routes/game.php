<?php

use App\Http\Controllers\Api\GameSessionController;
use App\Http\Controllers\Api\VoucherWebSessionController;
use App\Http\Middleware\AuthenticateGameSession;
use App\Http\Middleware\AuthenticateTerminal;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Game API Routes
|--------------------------------------------------------------------------
|
| Unified game session API for both wallet (online) and voucher code
| (in-store terminal) game sessions.
|
*/

// Wallet session start (requires authenticated user via OAuth)
Route::middleware(['api', 'auth:api'])->prefix('api/v1/game/session/start')->group(function () {
    Route::post('/wallet', [GameSessionController::class, 'startWalletSession'])->name('api.game.session.start.wallet');
});

// Terminal session start (requires terminal API key)
Route::middleware(['api', AuthenticateTerminal::class])->prefix('api/v1/game/session/start')->group(function () {
    Route::post('/terminal', [GameSessionController::class, 'startTerminalSession'])->name('api.game.session.start.terminal');
});

// Web voucher session start (no terminal key required, tenant-scoped)
Route::middleware(['api'])->prefix('api/v1/game/session/start')->group(function () {
    Route::post('/voucher-web', [VoucherWebSessionController::class, 'start'])
        ->middleware('throttle:20,1')
        ->name('api.game.session.start.voucher-web');
});

// Game session routes (require active game session token)
Route::middleware(['api', AuthenticateGameSession::class])->prefix('api/v1/game')->name('api.game.')->group(function () {
    Route::post('/session/end', [GameSessionController::class, 'endSession'])->name('session.end');
    Route::get('/session/info', [GameSessionController::class, 'sessionInfo'])->name('session.info');
    Route::get('/balance', [GameSessionController::class, 'balance'])->name('balance');
    Route::post('/debit', [GameSessionController::class, 'debit'])->name('debit');
    Route::post('/credit', [GameSessionController::class, 'credit'])->name('credit');
    Route::get('/transactions', [GameSessionController::class, 'transactions'])->name('transactions');
});
