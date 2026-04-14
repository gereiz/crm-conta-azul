<?php

namespace App\Console\Commands;

use App\Models\MessageCron;
use App\Services\MessageCronService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ProcessDelayedMessageCrons extends Command
{
    protected $signature = 'message:process-delayed-crons';

    protected $description = 'Processa automaticamente crons atrasados que permitem execução fora do horário.';

    protected MessageCronService $service;

    public function __construct(MessageCronService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    public function handle()
    {
        $result = \App\Services\CronMutexService::run('cron:message-pipeline', 0, 900, function () {
            $tz = config('app.timezone') ?: 'America/Sao_Paulo';
            $now = Carbon::now($tz);
            $today = $now->toDateString();
            $currentTime = $now->format('H:i');

            $this->info("Verificando crons atrasados: {$now->toDateTimeString()} (TZ: {$tz})");

            $crons = MessageCron::where('is_active', true)
                ->where('run_when_delayed', true)
                ->where('send_time', '<=', $currentTime)
                ->where(function ($q) use ($today) {
                    $q->whereNull('last_run_at')
                        ->orWhereDate('last_run_at', '<', $today);
                })
                ->with(['messageTemplate', 'whatsappNumber'])
                ->orderBy('send_time', 'asc')
                ->get();

            if ($crons->isEmpty()) {
                $this->info('Nenhuma automação atrasada elegível para processamento.');

                return self::SUCCESS;
            }

            foreach ($crons as $cron) {
                $this->info("Processando atrasado: {$cron->name} (ID {$cron->id}, horário {$cron->send_time})");
                $stats = $this->service->processCron($cron, true);
                $this->info('Resultado: '.json_encode($stats));
            }

            $this->info('Processamento de atrasados concluído.');

            return self::SUCCESS;
        });

        if ($result === null) {
            $this->info('Processamento de atrasados ignorado: fila principal já está em execução.');

            return self::SUCCESS;
        }

        return $result ?? self::SUCCESS;
    }
}
