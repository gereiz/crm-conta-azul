<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ContaAzulService;

class SyncInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invoices:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sincroniza faturas em atraso da Conta Azul para o banco local';

    /**
     * Execute the console command.
     */
    public function handle(ContaAzulService $service)
    {
        $this->info('Iniciando sincronização de faturas em atraso...');

        try {
            $count = $service->syncOverdueInvoices();
            $this->info("Sincronização concluída com sucesso! {$count} faturas processadas.");
        } catch (\Exception $e) {
            $this->error('Erro durante a sincronização: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
