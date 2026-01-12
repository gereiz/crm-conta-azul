<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\MessageCron;
use App\Services\MessageCronService;
use Carbon\Carbon;

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
        $now = Carbon::now();
        $currentTime = $now->format('H:i');
        
        $this->info("Iniciando processamento de crons: {$now->toDateTimeString()}");

        // Buscar crons ativos que devem rodar neste minuto
        $crons = MessageCron::where('is_active', true)
            ->where('send_time', $currentTime)
            ->with(['messageTemplate', 'whatsappNumber'])
            ->get();

        if ($crons->isEmpty()) {
            $this->info("Nenhum cron agendado para {$currentTime}.");
            return;
        }

        foreach ($crons as $cron) {
            $this->info("Processando cron: {$cron->name} (ID: {$cron->id})");
            $stats = $this->messageCronService->processCron($cron);
            $this->info("Resultado: " . json_encode($stats));
        }

        $this->info("Processamento concluído.");
    }
}
