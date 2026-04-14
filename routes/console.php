<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Cache;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('message:process-crons')
    ->everyMinute()
    ->withoutOverlapping(15)
    ->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
Schedule::command('message:process-delayed-crons')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
Schedule::command('message:process-enqueued')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
Schedule::command('messages:backfill-responded')
    ->everyTenMinutes()
    ->withoutOverlapping(20)
    ->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
Schedule::command('contaazul:sync-stale --target=all')
    ->dailyAt('01:00')
    ->withoutOverlapping(120)
    ->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
Schedule::command('contaazul:sync-stale --target=clients')
    ->dailyAt('08:45')
    ->withoutOverlapping(120)
    ->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
Schedule::command('contaazul:sync-stale --target=invoices')
    ->dailyAt('10:00')
    ->withoutOverlapping(120)
    ->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
Schedule::command('contaazul:refresh-tokens')
    ->everyThirtyMinutes()
    ->withoutOverlapping(60)
    ->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
Schedule::command('message:calculate-future')
    ->dailyAt('00:00')
    ->withoutOverlapping(120)
    ->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
Schedule::command('messages:sync-status')
    ->everyTenMinutes()
    ->withoutOverlapping(20)
    ->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
Schedule::call(function () {
    Cache::put('scheduler_heartbeat', now()->toDateTimeString(), 120);
})->everyMinute()->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
