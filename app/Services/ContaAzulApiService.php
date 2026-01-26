<?php

namespace App\Services;

use App\Models\ContaAzulConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ContaAzulApiService
{
    protected string $baseUrl = 'https://api.contaazul.com/v1';

    protected ContaAzulAuthService $auth;

    use \Illuminate\Support\Traits\Macroable;

    public function __construct(ContaAzulAuthService $auth)
    {
        $this->auth = $auth;
    }

    public function request(ContaAzulConnection $connection, string $method, string $endpoint, array $params = [], bool $retry = true, int $attempts = 0, ?string $customBaseUrl = null)
    {
        $urlToUse = $customBaseUrl ?? $this->baseUrl;
        $accessToken = $this->auth->getValidToken($connection);
        if (! $accessToken) {
            Log::warning("Sem token válido para conexão {$connection->id}");

            return null;
        }

        try {
            $response = Http::withToken($accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->timeout(120)
                ->{$method}("{$urlToUse}/{$endpoint}", $params);
        } catch (\Exception $e) {
            // Fallback: Se falhar na URL antiga, tenta a nova V2
            if ($urlToUse === $this->baseUrl) {
                Log::warning("Falha de conexão na API antiga ({$e->getMessage()}). Tentando API V2...");
                return $this->request($connection, $method, $endpoint, $params, $retry, $attempts, 'https://api-v2.contaazul.com/v1');
            }

            if ($retry && $attempts < 2) {
                sleep(2);

                return $this->request($connection, $method, $endpoint, $params, $retry, $attempts + 1, $customBaseUrl);
            }
            Log::error("Erro na requisição Conta Azul [{$endpoint}] conex {$connection->id}: ".$e->getMessage());

            return null;
        }

        // Fallback: Se a API antiga retornar erro (401, 404, etc), tenta a V2 antes de desistir
        if ($response->failed() && $urlToUse === $this->baseUrl) {
            Log::warning("Erro na API antiga (Status: {$response->status()}). Tentando API V2...");
            return $this->request($connection, $method, $endpoint, $params, $retry, $attempts, 'https://api-v2.contaazul.com/v1');
        }

        if ($response->status() === 401 && $retry) {
            $newToken = $this->auth->getValidToken($connection, true);
            if ($newToken) {
                return $this->request($connection, $method, $endpoint, $params, false, $attempts, $customBaseUrl);
            }
        }

        if ($response->status() === 429 && $retry && $attempts < 5) {
            $waitTime = 5 * ($attempts + 1); // 5s, 10s, 15s...
            Log::warning("Rate limit Conta Azul ({$endpoint}). Aguardando {$waitTime}s para tentar novamente (Tentativa {$attempts}/5).");
            sleep($waitTime);
            return $this->request($connection, $method, $endpoint, $params, $retry, $attempts + 1, $customBaseUrl);
        }

        if ($response->status() === 401) {
            // Se for erro de token inválido, pode ser que o token da V2 precise de uma URL base diferente
            // ou que o escopo 'sales' esteja faltando mesmo.
            // Log detalhado para debug
            Log::warning("Token rejeitado (401) na URL: {$urlToUse}/{$endpoint}");
            
            // Retorna null para sinalizar falha sem quebrar a execução (SettingsController trata isso)
            return null;
        }

        if ($response->failed()) {
            Log::error("Erro na requisição Conta Azul [{$endpoint}] conex {$connection->id}: ".$response->body());

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

    public function getAllOverdueInvoices(ContaAzulConnection $connection, int $page = 1, int $size = 1000, ?string $startDate = null, ?string $endDate = null, ?string $status = 'OVERDUE')
    {
        // Se as datas não forem passadas, assumimos um intervalo padrão para faturas em aberto
        $dataVencimentoDe = $startDate ?? \Carbon\Carbon::now()->subYears(5)->format('Y-m-d');
        // Para permitir automações de vencimento futuro, não devemos limitar a data final a "ontem".
        // Devemos buscar até o futuro (ex: +30 dias ou +1 ano) para capturar faturas que AINDA vão vencer.
        // O endpoint 'contas-a-receber/buscar' suporta filtro por status 'ABERTO' (que inclui atrasado e a vencer).
        $dataVencimentoAte = $endDate ?? \Carbon\Carbon::now()->addMonths(12)->format('Y-m-d');
        
        $apiParams = [
            'pagina' => $page,
            'tamanho_pagina' => $size,
            'data_vencimento_de' => $dataVencimentoDe,
            'data_vencimento_ate' => $dataVencimentoAte,
        ];

        // Adiciona status apenas se fornecido
        if ($status) {
            $apiParams['status'] = $status;
        }

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
        
        // Estratégia melhorada para encontrar URL
        if (empty($details['url']) && isset($response['solicitacoes_cobrancas']) && is_array($response['solicitacoes_cobrancas'])) {
            // Tenta encontrar a primeira solicitação válida com URL, preferindo as mais recentes (se ordenado) ou qualquer uma válida
            // Iteramos de trás para frente para pegar a última (geralmente a mais atual)
            $solicitacoes = array_reverse($response['solicitacoes_cobrancas']);
            foreach ($solicitacoes as $solicitacao) {
                if (!empty($solicitacao['url'])) {
                    $details['url'] = $solicitacao['url'];
                    break;
                }
            }
        }
        
        // Fallback: Se ainda não tem URL, tenta construir manualmente se houver token ou ID conhecido
        // (Isso depende de como a CA expõe links públicos, às vezes não expõe sem solicitação)

        return $details;
    }

    public function getOverdueTotals(ContaAzulConnection $connection, ?string $startDate = null, ?string $endDate = null): array
    {
        $dataVencimentoDe = $startDate ?? \Carbon\Carbon::now()->subYear()->format('Y-m-d');
        $dataVencimentoAte = $endDate ?? \Carbon\Carbon::now()->addMonths(6)->format('Y-m-d');
        $apiParams = [
            'pagina' => 1,
            'tamanho_pagina' => 1,
            'data_vencimento_de' => $dataVencimentoDe,
            'data_vencimento_ate' => $dataVencimentoAte,
            'status' => 'OVERDUE',
        ];
        $response = $this->request($connection, 'get', 'financeiro/eventos-financeiros/contas-a-receber/buscar', $apiParams);
        if (! $response) {
            return ['count' => 0, 'value' => 0.0];
        }
        $count = $response['itens_totais'] ?? 0;
        // Se mudarmos para ABERTO, precisamos ver se a resposta tem total separado.
        // A API retorna totais agrupados geralmente. Vamos tentar pegar o valor total geral se disponível
        // ou manter 'vencido' se a API separar.
        // Assumindo que queremos o total de tudo que está aberto:
        $value = $response['totais']['valor'] ?? ($response['totais']['vencido']['valor'] ?? 0.0);
        return ['count' => (int) $count, 'value' => (float) $value];
    }

    public function syncOverdueInvoices(ContaAzulConnection $connection): int
    {
        $startDate = \Carbon\Carbon::now()->subYears(5)->format('Y-m-d');
        // Buscamos faturas até 12 meses no futuro para garantir que automações de "vence hoje" e "pré-vencimento" funcionem
        $endDate = \Carbon\Carbon::now()->addMonths(12)->format('Y-m-d');
        $size = 1000;
        $allInvoices = [];

        // Buscamos OVERDUE (vencidos) e PENDING (futuros/a vencer) separadamente para evitar erro de status inválido
        $statuses = ['OVERDUE', 'PENDING'];

        foreach ($statuses as $status) {
            $hasMore = true;
            $page = 1;
            while ($hasMore) {
                $data = $this->getAllOverdueInvoices($connection, $page, $size, $startDate, $endDate, $status);
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
                // Ajustando delay para respeitar limite de 10 req/s (100ms), mas com margem de segurança (150ms)
                usleep(150000);
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
