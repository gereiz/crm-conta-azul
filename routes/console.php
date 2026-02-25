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
Schedule::command('message:process-enqueued')->everyTwoMinutes()->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
Schedule::command('messages:backfill-responded')->everyFiveMinutes()->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
Schedule::command('contaazul:sync-stale')->dailyAt('01:00')->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
// Alinhado ao fluxo multi-empresa e tokens por conexão
Schedule::command('contaazul:sync-stale')->dailyAt('10:00')->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
// Nova janela: sincronização diária de apenas Clientes às 08:45
Schedule::command('contaazul:sync-stale')->dailyAt('08:45')->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
Schedule::command('contaazul:refresh-tokens')->everyThirtyMinutes()->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
Schedule::command('message:calculate-future')->dailyAt('00:00')->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
Schedule::command('messages:sync-status')->everyFiveMinutes()->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
Schedule::call(function () {
    Cache::put('scheduler_heartbeat', now()->toDateTimeString(), 120);
})->everyMinute()->timezone(config('app.timezone') ?: 'America/Sao_Paulo');
