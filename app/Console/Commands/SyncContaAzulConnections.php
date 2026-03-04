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
    protected $signature = 'contaazul:sync-stale {--target=all}';

    protected $description = 'Sincroniza clientes e/ou faturas da Conta Azul para todas as empresas ativas.';

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
        return \App\Services\CronMutexService::run('cron:global', 600, 1800, function () {
            $settings = SystemSetting::latest()->first();
            if ($settings && $settings->contaazul_cron_enabled === false) {
                $this->info('Cron de sincronização Conta Azul está desativada nas Configurações do Sistema.');

                return 0;
            }
            $now = Carbon::now();
            $this->info("Iniciando sincronização automática às {$now->toDateTimeString()}");
            $target = strtolower((string) $this->option('target'));
            if (! in_array($target, ['all', 'clients', 'invoices'], true)) {
                $target = 'all';
            }

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
                        'job_type' => $target,
                        'started_at' => Carbon::now(),
                        'status' => 'success',
                    ]);

                    if ($target === 'all' || $target === 'clients') {
                        $this->info('Executando sincronização de clientes (janela agendada).');
                        try {
                            $synced = $this->syncClients($connection);
                            // Fallback: se após a sincronização a empresa ficou sem clientes, tenta uma vez novamente
                            $clientesCount = Cliente::where('connection_id', $connection->id)->count();
                            if ($clientesCount === 0) {
                                $this->warn("Nenhum cliente retornado para {$connection->empresa_nome}. Tentando novamente...");
                                sleep(2);
                                $syncedRetry = $this->syncClients($connection);
                                $clientesCount = Cliente::where('connection_id', $connection->id)->count();
                                if ($clientesCount === 0) {
                                    $this->error("Fallback falhou: ainda sem clientes após segunda tentativa ({$connection->empresa_nome}).");
                                } else {
                                    $this->info("Fallback bem-sucedido: {$clientesCount} clientes após segunda tentativa ({$connection->empresa_nome}).");
                                }
                            }
                        } catch (\Exception $e) {
                            $this->warn('Falha ao sincronizar clientes nesta conexão: '.$e->getMessage());
                        }
                    }
                    $syncedInvoices = 0;
                    $syncedClosed = 0;
                    if ($target === 'all' || $target === 'invoices') {
                        $syncedInvoices = $this->api->syncOverdueInvoices($connection);
                        $syncedClosed = $this->api->syncRecentlyClosedInvoices($connection);
                    }

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
        });
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
                $existing = Cliente::where('connection_id', $connection->id)->where('ca_id', $caClient['id'])->first();
                $currentPhone = $existing?->phone;
                $currentMobile = $existing?->mobile_phone;
                $hasPlusCurrent = is_string($currentPhone) && preg_match('/^\s*\+/', $currentPhone);
                $hasPlusMobile = is_string($currentMobile) && preg_match('/^\s*\+/', $currentMobile);
                $sanitizedCurrent = \App\Services\PhoneSanitizerService::sanitize($currentPhone ?? '');
                $sanitizedMobile = \App\Services\PhoneSanitizerService::sanitize($currentMobile ?? '');
                // Política: nunca sobrescrever telefones locais; apenas preencher se local estiver vazio

                $data = [
                    'connection_id' => $connection->id,
                    'name' => $caClient['nome'] ?? 'Sem Nome',
                    'company_name' => $connection->empresa_nome,
                    'email' => $caClient['email'] ?? null,
                    'cpf_cnpj' => $cpfCnpj,
                    'person_type' => $caClient['tipo_pessoa'] ?? null,
                    'city' => $city,
                    'state' => $state,
                ];
                // Regra de não sobrescrever telefones locais quando CA está vazio
                $hasDigits = function ($v) {
                    return is_string($v) && preg_match('/\d+/', trim($v));
                };
                if (empty($currentPhone) && $hasDigits($phone)) {
                    $data['phone'] = $phone;
                }
                if (empty($currentMobile) && $hasDigits($mobilePhone)) {
                    $data['mobile_phone'] = $mobilePhone;
                }
                Cliente::updateOrCreate(
                    ['connection_id' => $connection->id, 'ca_id' => $caClient['id']],
                    $data
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
