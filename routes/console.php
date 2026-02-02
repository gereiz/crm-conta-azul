<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Cache;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('message:process-crons')->everyMinute()->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
Schedule::command('message:process-delayed-crons')->everyMinute()->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
Schedule::command('contaazul:sync-stale')->dailyAt('01:00')->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
Schedule::command('contaazul:refresh-tokens')->everyThirtyMinutes()->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
Schedule::command('message:calculate-future')->dailyAt('00:00')->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
Schedule::call(function () {
    Cache::put('scheduler_heartbeat', now()->toDateTimeString(), 120);
})->everyMinute()->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
