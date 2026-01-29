<?php

namespace App\Console\Commands;

use App\Models\ContaAzulConnection;
use App\Services\FutureMessageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CalculateFutureMessages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'message:calculate-future {connection_id?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calcula e atualiza a tabela de envios futuros baseada nas regras atuais';

    protected $futureService;

    public function __construct(FutureMessageService $futureService)
    {
        parent::__construct();
        $this->futureService = $futureService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $connectionId = $this->argument('connection_id');

        $query = ContaAzulConnection::where('is_active', true);
        if ($connectionId) {
            $query->where('id', $connectionId);
        }

        $connections = $query->get();

        $this->info("Calculando envios futuros para {$connections->count()} conexões...");

        foreach ($connections as $connection) {
            try {
                $this->futureService->calculateForConnection($connection);
                $this->info("Conexão {$connection->empresa_nome}: Atualizado.");
            } catch (\Exception $e) {
                $this->error("Erro na conexão {$connection->empresa_nome}: ".$e->getMessage());
                Log::error("Erro no cálculo de envios futuros (Conn {$connection->id}): ".$e->getMessage());
            }
        }

        $this->info('Cálculo concluído.');

        return Command::SUCCESS;
    }
}
