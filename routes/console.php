<?php

use App\Jobs\SyncMarketDataJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new SyncMarketDataJob)->dailyAt('02:00');

// Intraday refresh from EU open until US close; the nightly run catches final closes.
Schedule::job(new SyncMarketDataJob)->hourly()->weekdays()
    ->between('9:00', '22:00')->timezone('Europe/Amsterdam');
