<?php

use App\Http\Controllers\Terminal\AuthController;
use App\Http\Middleware\AuthenticateTerminal;
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
