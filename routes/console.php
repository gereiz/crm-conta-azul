<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('message:process-crons')->everyMinute();
Schedule::command('message:process-delayed-crons')->everyMinute();
Schedule::command('contaazul:sync-stale')->dailyAt('01:00');
Schedule::command('contaazul:refresh-tokens')->everyThirtyMinutes();
Schedule::command('message:calculate-future')->dailyAt('00:00');
