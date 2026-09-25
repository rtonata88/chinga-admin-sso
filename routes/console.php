<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('revenue:calculate --period=daily')->dailyAt('02:00');
Schedule::command('game-sessions:cleanup')->hourly();
Schedule::command('wallets:reconcile')->dailyAt('03:00');
// Realised vs theoretical RTP per game, the pricing-bug tripwire (Fantasy v2 PRD §11).
Schedule::command('games:rtp-drift --days=7')->dailyAt('04:00');
