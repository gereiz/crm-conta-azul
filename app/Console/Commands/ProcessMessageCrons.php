<?php

namespace App\Console\Commands;

use App\Models\MessageCron;
use App\Services\MessageCronService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ProcessMessageCrons extends Command
{
    protected $signature = 'message:process-crons';

    protected $description = 'Processa envios automáticos de mensagens configurados no sistema.';

    protected $messageCronService;

    public function __construct(MessageCronService $messageCronService)
    {
        parent::__construct();
        $this->messageCronService = $messageCronService;
    }

    public function handle()
    {
        return \App\Services\CronMutexService::run('cron:global', 600, 900, function () {
            $tz = config('app.timezone') ?: 'America/Sao_Paulo';
            $now = Carbon::now($tz);
            $currentTime = $now->format('H:i');

            $this->info("Iniciando processamento de crons: {$now->toDateTimeString()} (TZ: {$tz}, HH:mm={$currentTime})");

            $crons = MessageCron::where('is_active', true)
                ->where(function ($q) use ($currentTime) {
                    $q->where('send_time', $currentTime)
                        ->orWhere('send_time', ltrim($currentTime, '0'));
                })
                ->with(['messageTemplate', 'whatsappNumber'])
                ->get();

            if ($crons->isEmpty()) {
                $this->info("Nenhum cron agendado para {$currentTime}.");

                return;
            }

            foreach ($crons as $cron) {
                $this->info("Processando cron: {$cron->name} (ID: {$cron->id})");
                $stats = $this->messageCronService->processCron($cron);
                $this->info('Resultado: '.json_encode($stats));
            }

            $this->info('Processamento concluído.');
        });
    }
}
