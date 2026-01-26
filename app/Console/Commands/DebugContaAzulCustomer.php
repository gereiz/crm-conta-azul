<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ContaAzulApiService;
use App\Models\ContaAzulConnection;

class DebugContaAzulCustomer extends Command
{
    // Assinatura do comando: permite passar ID da conexão e termo de busca opcional
    protected $signature = 'debug:contaazul-customer {connection_id?} {--search=}';
    protected $description = 'Despeja o JSON bruto de um cliente da Conta Azul para inspeção de campos';

    public function handle(ContaAzulApiService $service)
    {
        // 1. Obter a conexão
        $connectionId = $this->argument('connection_id');
        
        if ($connectionId) {
            $connection = ContaAzulConnection::find($connectionId);
        } else {
            // Pega a primeira conexão ativa se nenhuma for especificada
            $connection = ContaAzulConnection::where('is_active', true)->first();
        }

        if (!$connection) {
            $this->error('Nenhuma conexão Conta Azul ativa encontrada.');
            return;
        }

        $this->info("Usando conexão: {$connection->empresa_nome} (ID: {$connection->id})");

        // 2. Preparar parâmetros de busca
        $search = $this->option('search');
        $params = [
            'page' => 1, 
            'size' => 10 // Correção: API exige mínimo de 10
        ];
        
        if ($search) {
            $this->info("Buscando por: '{$search}'...");
            $params['search'] = $search;
        } else {
            $this->info("Buscando o cliente mais recente (padrão)...");
        }

        // 3. Fazer a requisição usando o serviço existente
        try {
            // O método getClients chama o endpoint /v1/pessoas
            $response = $service->getClients($connection, $params);
            
            if (!$response) {
                $this->error('A API retornou vazio ou houve erro na requisição.');
                return;
            }

            // 4. Exibir o JSON Bruto
            $this->info(str_repeat('-', 50));
            $this->info('RESPOSTA JSON BRUTA:');
            $this->info(str_repeat('-', 50));
            
            // JSON_PRETTY_PRINT facilita a leitura humana
            $json = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->line($json);
            
            $this->info(str_repeat('-', 50));

            // 5. Análise automática (tentativa de encontrar o campo)
            $this->checkBirthDateFields($response);

        } catch (\Exception $e) {
            $this->error("Erro ao executar: " . $e->getMessage());
        }
    }

    private function checkBirthDateFields($data)
    {
        // Normaliza para pegar o primeiro item, dependendo da estrutura de resposta
        $client = null;
        if (isset($data[0])) {
            $client = $data[0];
        } elseif (isset($data['items'][0])) {
            $client = $data['items'][0];
        } elseif (isset($data['id'])) { // Retorno de cliente único
            $client = $data;
        }

        if ($client) {
            $this->info('Análise de campos de data encontrados:');
            $found = false;
            foreach ($client as $key => $value) {
                if (str_contains(strtolower($key), 'nascimento') || str_contains(strtolower($key), 'birth') || str_contains(strtolower($key), 'date')) {
                    $this->line(" - [{$key}]: " . json_encode($value));
                    $found = true;
                }
            }
            if (!$found) {
                $this->warn('Nenhum campo óbvio de data de nascimento encontrado no nível raiz.');
            }
        }
    }
}
