<?php

use App\Http\Controllers\Api\LaunchExchangeController;
use App\Http\Controllers\Api\Operator\LaunchController;
use App\Http\Controllers\Api\Operator\PlayerController;
use App\Http\Controllers\Api\Operator\TransferController;
use App\Http\Middleware\AuthenticateOperator;
use Illuminate\Support\Facades\Route;

/*
| Operator integration (docs/platform/operator-integration.md). The operator's own
| servers call these, signed with the integration's secret (AuthenticateOperator);
| every call is bound to the operator's tenant.
*/
Route::middleware(['api', AuthenticateOperator::class, 'throttle:1200,1'])
    ->prefix('api/v1/operator')->name('api.operator.')->group(function () {
        Route::post('launch', LaunchController::class)->name('launch');
        Route::post('transfers', TransferController::class)->name('transfers');
        Route::get('players/{playerId}/balance', [PlayerController::class, 'balance'])->name('players.balance');
        Route::post('players/{playerId}/block', [PlayerController::class, 'block'])->name('players.block');
        Route::post('players/{playerId}/unblock', [PlayerController::class, 'unblock'])->name('players.unblock');
    });

// The game page in the operator's iframe trades its one-time code for a player token.
Route::middleware(['api', 'throttle:60,1'])
    ->post('api/v1/launch/exchange', LaunchExchangeController::class)->name('api.launch.exchange');
