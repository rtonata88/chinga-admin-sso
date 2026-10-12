<?php

use App\Http\Controllers\Api\Channels\ChannelMoneyController;
use App\Http\Controllers\Api\Channels\ChannelPlayersController;
use App\Http\Controllers\Api\Channels\LadderNotifyController;
use App\Http\Controllers\Api\Channels\SimulatorController;
use App\Http\Middleware\ResolveOAuthClient;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Middleware\EnsureClientIsResourceOwner;

/*
| Channels (Kulipi Kuna USSD design §5): the USSD gateway acts for players of the
| tenants it is bound to (client_credentials + channel:ussd). Mobile-money callbacks
| are authenticated by the provider's own signature. Simulator routes exist only
| with CHANNEL_SIMULATOR on.
*/
Route::middleware(['api', EnsureClientIsResourceOwner::using('channel:ussd'), ResolveOAuthClient::class, 'throttle:600,1'])
    ->prefix('api/v1/channels')->name('api.channels.')->group(function () {
        Route::get('ussd/routing', [ChannelPlayersController::class, 'routing'])->name('routing');
        Route::post('players/resolve', [ChannelPlayersController::class, 'resolve'])->name('players.resolve');
        Route::post('players/accept-terms', [ChannelPlayersController::class, 'acceptTerms'])->name('players.terms');
        Route::post('players/language', [ChannelPlayersController::class, 'language'])->name('players.language');
        Route::post('players/token', [ChannelPlayersController::class, 'token'])->name('players.token');
        Route::get('players/{uuid}/summary', [ChannelPlayersController::class, 'summary'])->name('players.summary');
        Route::post('deposits', [ChannelMoneyController::class, 'deposit'])->name('deposits');
        Route::post('withdrawals', [ChannelMoneyController::class, 'withdraw'])->name('withdrawals');
        Route::post('sms', [ChannelMoneyController::class, 'sms'])->name('sms');

        Route::prefix('simulator')->name('simulator.')->group(function () {
            Route::get('subscribers', [SimulatorController::class, 'subscribers'])->name('subscribers');
            Route::put('subscribers/{msisdn}', [SimulatorController::class, 'updateSubscriber'])->name('subscribers.update');
            Route::get('phone', [SimulatorController::class, 'phone'])->name('phone');
            Route::post('prompts/{reference}', [SimulatorController::class, 'answerPrompt'])->name('prompts.answer');
        });
    });

Route::middleware(['api', EnsureClientIsResourceOwner::using('wallet:write'), ResolveOAuthClient::class, 'throttle:600,1'])
    ->post('api/v1/notify/ladder', LadderNotifyController::class)->name('api.notify.ladder');

Route::middleware(['api', 'throttle:600,1'])
    ->post('api/v1/mobile-money/callbacks/{providerUuid}', [ChannelMoneyController::class, 'callback'])->name('api.mobile-money.callback');
