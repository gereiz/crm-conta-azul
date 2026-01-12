<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ContaAzulService;

class TestContaAzulApi extends Command
{
    protected $signature = 'test:contaazul-clients';
    protected $description = 'Test Conta Azul Clients API and dump response';

    public function handle(ContaAzulService $service)
    {
        $this->info('Fetching clients from Conta Azul...');
        try {
            $this->info('Fetching clients from Conta Azul...');
            $response = $service->getClients(['page' => 1, 'size' => 10]);
            $this->info(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }
    }
}
