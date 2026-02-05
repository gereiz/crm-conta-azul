<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Models\ContaAzulConnection;
use App\Models\SyncJobLog;
use App\Models\SystemSetting;
use App\Services\ContaAzulApiService;
use App\Services\ContaAzulAuthService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncContaAzulConnections extends Command
{
    protected $signature = 'contaazul:sync-stale';

    protected $description = 'Verifica conexões com última sincronização >24h e sincroniza clientes e faturas para todas as empresas.';

    protected ContaAzulApiService $api;

    protected ContaAzulAuthService $auth;

    public function __construct(ContaAzulApiService $api, ContaAzulAuthService $auth)
    {
        parent::__construct();
        $this->api = $api;
        $this->auth = $auth;
    }

    public function handle()
    {
        $settings = SystemSetting::latest()->first();
        if ($settings && $settings->contaazul_cron_enabled === false) {
            $this->info('Cron de sincronização Conta Azul está desativada nas Configurações do Sistema.');

            return 0;
        }
        $now = Carbon::now();
        $this->info("Iniciando sincronização automática de faturas às {$now->toDateTimeString()}");

        $connections = ContaAzulConnection::active()->orderBy('empresa_nome')->get();
        foreach ($connections as $connection) {
            $this->info("Sincronizando empresa: {$connection->empresa_nome} (ID {$connection->id})");
            try {
                $token = $this->auth->getValidToken($connection) ?? $this->auth->getValidToken($connection, true);
                if (! $token) {
                    $this->warn("Sem token válido para conexão {$connection->id}. Pulando.");

                    continue;
                }

                $log = SyncJobLog::create([
                    'conta_azul_connection_id' => $connection->id,
                    'job_type' => 'invoices',
                    'started_at' => Carbon::now(),
                    'status' => 'success',
                ]);

                $syncedInvoices = $this->api->syncOverdueInvoices($connection);
                $syncedClosed = $this->api->syncRecentlyClosedInvoices($connection);

                $connection->last_sync_at = Carbon::now();
                $connection->save();

                $this->info("Empresa {$connection->empresa_nome}: {$syncedInvoices} abertas/atrasadas e {$syncedClosed} pagas/canceladas atualizadas.");

                $log->update([
                    'finished_at' => Carbon::now(),
                    'items_processed' => (int) ($syncedInvoices + $syncedClosed),
                    'message' => 'Execução automática diária',
                ]);
            } catch (\Exception $e) {
                Log::error("Erro ao sincronizar conexão {$connection->id}: ".$e->getMessage());
                $this->error("Erro ao sincronizar {$connection->empresa_nome}: ".$e->getMessage());
                try {
                    if (isset($log)) {
                        $log->update([
                            'finished_at' => Carbon::now(),
                            'status' => 'error',
                            'message' => $e->getMessage(),
                        ]);
                    }
                } catch (\Throwable $t) {
                }
            }
        }

        $this->info('Sincronização de faturas concluída.');

        return 0;
    }

    protected function syncClients(ContaAzulConnection $connection): int
    {
        $page = 1;
        $size = 100;
        $hasMore = true;
        $syncedCount = 0;

        while ($hasMore) {
            $response = $this->api->getClients($connection, ['page' => $page, 'size' => $size]);
            $clientsData = [];
            if (isset($response['items'])) {
                $clientsData = $response['items'];
            } elseif (is_array($response)) {
                $clientsData = $response;
            }

            if (empty($clientsData)) {
                $hasMore = false;
                break;
            }

            foreach ($clientsData as $caClient) {
                $perfis = array_map('strtolower', $caClient['perfis'] ?? []);
                if (! in_array('cliente', $perfis)) {
                    continue;
                }
                if (empty($caClient['ativo'])) {
                    continue;
                }
                $cpfCnpj = $caClient['documento'] ?? ($caClient['cpf'] ?? ($caClient['cnpj'] ?? null));
                $phone = $caClient['telefone'] ?? ($caClient['telefone_comercial'] ?? null);
                $mobilePhone = $caClient['telefone_celular'] ?? null;
                $city = null;
                $state = null;
                if (! empty($caClient['enderecos']) && is_array($caClient['enderecos'])) {
                    $primaryAddress = $caClient['enderecos'][0];
                    $city = $primaryAddress['cidade'] ?? null;
                    $state = $primaryAddress['estado'] ?? null;
                }
                Cliente::updateOrCreate(
                    ['connection_id' => $connection->id, 'ca_id' => $caClient['id']],
                    [
                        'connection_id' => $connection->id,
                        'name' => $caClient['nome'] ?? 'Sem Nome',
                        'company_name' => $connection->empresa_nome,
                        'email' => $caClient['email'] ?? null,
                        'phone' => $phone,
                        'mobile_phone' => $mobilePhone,
                        'cpf_cnpj' => $cpfCnpj,
                        'person_type' => $caClient['tipo_pessoa'] ?? null,
                        'city' => $city,
                        'state' => $state,
                    ]
                );
                $syncedCount++;
            }

            if (count($clientsData) < $size) {
                $hasMore = false;
            } else {
                $page++;
            }

            if ($page > 500) {
                $hasMore = false;
            }
            sleep(1);
        }

        return $syncedCount;
    }
}
