<?php

namespace App\Services;

use App\Models\ContaAzulConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ContaAzulApiService
{
    protected string $baseUrl = 'https://api-v2.contaazul.com/v1';

    protected ContaAzulAuthService $auth;

    use \Illuminate\Support\Traits\Macroable;

    public function __construct(ContaAzulAuthService $auth)
    {
        $this->auth = $auth;
    }

    public function request(ContaAzulConnection $connection, string $method, string $endpoint, array $params = [], bool $retry = true, int $attempts = 0)
    {
        $accessToken = $this->auth->getValidToken($connection);
        if (! $accessToken) {
            Log::warning("Sem token válido para conexão {$connection->id}");

            return null;
        }

        try {
            $response = Http::withToken($accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->timeout(120)
                ->{$method}("{$this->baseUrl}/{$endpoint}", $params);
        } catch (\Exception $e) {
            if ($retry && $attempts < 2) {
                sleep(2);

                return $this->request($connection, $method, $endpoint, $params, $retry, $attempts + 1);
            }
            Log::error("Erro na requisição Conta Azul [{$endpoint}] conex {$connection->id}: ".$e->getMessage());

            return null;
        }

        if ($response->status() === 401 && $retry) {
            $newToken = $this->auth->getValidToken($connection, true);
            if ($newToken) {
                return $this->request($connection, $method, $endpoint, $params, false, $attempts);
            }
        }

        if ($response->failed()) {
            Log::error("Erro na requisição Conta Azul [{$endpoint}] conex {$connection->id}: ".$response->body());
            if ($response->status() === 401) {
                throw new \Exception("Sessão expirada na conexão {$connection->id}. Reautorize a Conta Azul.");
            }

            return null;
        }

        return $response->json();
    }

    public function getClients(ContaAzulConnection $connection, array $params = [])
    {
        $apiParams = [
            'pagina' => $params['page'] ?? 1,
            'tamanho_pagina' => $params['size'] ?? 20,
        ];
        if (! empty($params['search'])) {
            $apiParams['pesquisa'] = $params['search'];
            $apiParams['q'] = $params['search'];
            $apiParams['nome'] = $params['search'];
        }

        return $this->request($connection, 'get', 'pessoas', $apiParams);
    }

    public function getAllOverdueInvoices(ContaAzulConnection $connection, int $page = 1, int $size = 1000, ?string $startDate = null, ?string $endDate = null)
    {
        $dataVencimentoDe = $startDate ?? \Carbon\Carbon::now()->subYear()->format('Y-m-d');
        $dataVencimentoAte = $endDate ?? \Carbon\Carbon::now()->subDay()->format('Y-m-d');
        $apiParams = [
            'pagina' => $page,
            'tamanho_pagina' => $size,
            'data_vencimento_de' => $dataVencimentoDe,
            'data_vencimento_ate' => $dataVencimentoAte,
            'status' => 'ATRASADO',
        ];

        return $this->request($connection, 'get', 'financeiro/eventos-financeiros/contas-a-receber/buscar', $apiParams);
    }

    public function getInvoiceDetails(ContaAzulConnection $connection, string $cobrancaId): ?array
    {
        $endpoint = "financeiro/eventos-financeiros/parcelas/{$cobrancaId}";
        $response = $this->request($connection, 'get', $endpoint);
        if (! $response) {
            return null;
        }
        $details = [
            'url' => $response['url'] ?? null,
            'payment_type' => $response['metodo_pagamento'] ?? null,
        ];
        if (empty($details['url']) && isset($response['solicitacoes_cobrancas']) && is_array($response['solicitacoes_cobrancas'])) {
            $ultima = end($response['solicitacoes_cobrancas']);
            if (isset($ultima['url'])) {
                $details['url'] = $ultima['url'];
            }
        }

        return $details;
    }

    public function getOverdueTotals(ContaAzulConnection $connection, ?string $startDate = null, ?string $endDate = null): array
    {
        $dataVencimentoDe = $startDate ?? \Carbon\Carbon::now()->subYear()->format('Y-m-d');
        $dataVencimentoAte = $endDate ?? \Carbon\Carbon::now()->subDay()->format('Y-m-d');
        $apiParams = [
            'pagina' => 1,
            'tamanho_pagina' => 1,
            'data_vencimento_de' => $dataVencimentoDe,
            'data_vencimento_ate' => $dataVencimentoAte,
            'status' => 'ATRASADO',
        ];
        $response = $this->request($connection, 'get', 'financeiro/eventos-financeiros/contas-a-receber/buscar', $apiParams);
        if (! $response) {
            return ['count' => 0, 'value' => 0.0];
        }
        $count = $response['itens_totais'] ?? 0;
        $value = $response['totais']['vencido']['valor'] ?? 0.0;
        return ['count' => (int) $count, 'value' => (float) $value];
    }

    public function syncOverdueInvoices(ContaAzulConnection $connection): int
    {
        $startDate = \Carbon\Carbon::now()->subYears(5)->format('Y-m-d');
        $endDate = \Carbon\Carbon::now()->subDay()->format('Y-m-d');
        $page = 1;
        $size = 1000;
        $hasMore = true;
        $allInvoices = [];

        while ($hasMore) {
            $data = $this->getAllOverdueInvoices($connection, $page, $size, $startDate, $endDate);
            if (empty($data['itens'])) {
                $hasMore = false;
            } else {
                $allInvoices = array_merge($allInvoices, $data['itens']);
                if (count($data['itens']) < $size) {
                    $hasMore = false;
                } else {
                    $page++;
                }
            }
        }

        \App\Models\Invoice::where('connection_id', $connection->id)->delete();

        foreach ($allInvoices as $item) {
            $clienteCaId = $item['cliente']['id'] ?? null;
            $clienteLocal = null;
            if ($clienteCaId) {
                $clienteLocal = \App\Models\Cliente::where('connection_id', $connection->id)->where('ca_id', $clienteCaId)->first();
            }
            $invoiceDetails = ['url' => null, 'payment_type' => null];
            if (isset($item['id'])) {
                $details = $this->getInvoiceDetails($connection, $item['id']);
                if ($details) {
                    $invoiceDetails = $details;
                }
                usleep(200000);
            }
            $valorOriginal = isset($item['total']) ? (float) $item['total'] : 0.0;
            $valorPago = isset($item['pago']) ? (float) $item['pago'] : null;
            $naoPago = isset($item['nao_pago']) ? (float) $item['nao_pago'] : null;
            $saldoDevedor = $naoPago ?? ($valorPago !== null ? max(0.0, $valorOriginal - $valorPago) : $valorOriginal);
            \App\Models\Invoice::updateOrCreate(
                ['connection_id' => $connection->id, 'ca_id' => $item['id']],
                [
                    'connection_id' => $connection->id,
                    'status' => $item['status'],
                    'payment_type' => $invoiceDetails['payment_type'],
                    'valor_original' => $valorOriginal,
                    'saldo_devedor' => $saldoDevedor,
                    'descricao' => $item['descricao'] ?? null,
                    'data_vencimento' => $item['data_vencimento'] ?? null,
                    'data_emissao' => $item['data_emissao'] ?? null,
                    'link_boleto' => $invoiceDetails['url'],
                    'cliente_id' => $clienteLocal ? $clienteLocal->id : null,
                    'cliente_ca_id' => $clienteCaId,
                    'cliente_nome' => $item['cliente']['nome'] ?? null,
                ]
            );
        }

        return count($allInvoices);
    }
}
