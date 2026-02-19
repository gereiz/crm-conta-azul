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
            if ($response->status() !== 401) { // Só loga se não for 401 para evitar spam
                Log::warning("Erro na API antiga (Status: {$response->status()}). Tentando API V2...");
            }

            return $this->request($connection, $method, $endpoint, $params, $retry, $attempts, 'https://api-v2.contaazul.com/v1');
        }

        if ($response->status() === 401 && $retry) {
            // Se for 401, tenta renovar token antes de qualquer coisa, mesmo na API antiga
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
            'due_date' => $this->normalizeDate($response['vencimento'] ?? ($response['data_vencimento'] ?? null)),
            'issue_date' => $this->normalizeDate($response['data_emissao'] ?? null),
            // Captura descrição a partir de chaves possíveis retornadas pela API
            'descricao' => $response['descricao'] ?? ($response['historico'] ?? ($response['descricao_parcela'] ?? ($response['observacao'] ?? ($response['mensagem'] ?? ($response['description'] ?? null))))),
        ];

        // Estratégia melhorada para encontrar URL
        if (empty($details['url']) && isset($response['solicitacoes_cobrancas']) && is_array($response['solicitacoes_cobrancas'])) {
            // Tenta encontrar a primeira solicitação válida com URL, preferindo as mais recentes (se ordenado) ou qualquer uma válida
            // Iteramos de trás para frente para pegar a última (geralmente a mais atual)
            $solicitacoes = array_reverse($response['solicitacoes_cobrancas']);
            foreach ($solicitacoes as $solicitacao) {
                if (! empty($solicitacao['url'])) {
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
        // Buscamos faturas até 30 dias no futuro para garantir que automações de "vence hoje" e "pré-vencimento" funcionem
        // Ajustado para 30 dias conforme solicitação do usuário
        $endDate = \Carbon\Carbon::now()->addDays(30)->format('Y-m-d');
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

        // --- OPTIMIZATION START ---
        // 1. Preload Clients to avoid N+1
        $clientMap = \App\Models\Cliente::where('connection_id', $connection->id)
            ->pluck('id', 'ca_id')
            ->toArray();

        // 2. Preload Existing Invoices to avoid unnecessary details fetching
        // Map: ca_id => ['link_boleto', 'status', 'reference_code']
        $existingInvoices = \App\Models\Invoice::where('connection_id', $connection->id)
            ->get(['ca_id', 'link_boleto', 'status', 'reference_code'])
            ->keyBy('ca_id')
            ->toArray();

        // 3. Collect processed IDs for pruning
        $processedCaIds = [];

        foreach ($allInvoices as $item) {
            $caId = $item['id'] ?? null;
            if (! $caId) {
                continue;
            }

            $processedCaIds[] = $caId;
            $clienteCaId = $item['cliente']['id'] ?? null;
            $clienteLocalId = $clienteCaId ? ($clientMap[$clienteCaId] ?? null) : null;

            // Proteção anti-duplicação entre empresas:
            // Se já existe uma fatura com o mesmo CA ID em outra conexão, não regravar aqui.
            try {
                $existsInOtherConn = \App\Models\Invoice::where('ca_id', $caId)
                    ->where('connection_id', '!=', $connection->id)
                    ->exists();
                if ($existsInOtherConn) {
                    \Illuminate\Support\Facades\Log::warning("Dedup: fatura CA {$caId} já existente em outra conexão. Ignorando na conexão {$connection->id}.");

                    continue;
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Falha na verificação de duplicidade de fatura: '.$e->getMessage());
            }

            // Optimization com garantia de atualização de descrição:
            // Busca detalhes se não houver link salvo OU se a listagem não trouxer 'descricao'
            $invoiceDetails = ['url' => null, 'payment_type' => null, 'descricao' => null];
            $existing = $existingInvoices[$caId] ?? null;

            $shouldFetchDetails = true;
            if ($existing && ! empty($existing['link_boleto']) && ! empty(($item['descricao'] ?? null))) {
                $shouldFetchDetails = false;
                $invoiceDetails['url'] = $existing['link_boleto'];
                // Mantemos a possibilidade de atualizar descrição via listagem quando presente
            }

            if ($shouldFetchDetails) {
                $details = $this->getInvoiceDetails($connection, $caId);
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

            $newDescricao = $item['descricao'] ?? ($invoiceDetails['descricao'] ?? null);

            \App\Models\Invoice::updateOrCreate(
                ['connection_id' => $connection->id, 'ca_id' => $caId],
                [
                    'connection_id' => $connection->id,
                    'status' => $item['status'],
                    // Fallback: se vier com link de boleto mas sem tipo, marcar como BOLETO
                    'payment_type' => $invoiceDetails['payment_type'] ?: (!empty($invoiceDetails['url']) ? 'BOLETO' : null),
                    'valor_original' => $valorOriginal,
                    'saldo_devedor' => $saldoDevedor,
                    'descricao' => $newDescricao,
                    'reference_code' => $item['codigo_referencia'] ?? ($existing['reference_code'] ?? null),
                    'data_vencimento' => $this->normalizeDate($item['data_vencimento'] ?? ($item['vencimento'] ?? ($invoiceDetails['due_date'] ?? null))),
                    'data_emissao' => $this->normalizeDate($item['data_emissao'] ?? ($invoiceDetails['issue_date'] ?? null)),
                    'link_boleto' => $invoiceDetails['url'],
                    'cliente_id' => $clienteLocalId,
                    'cliente_ca_id' => $clienteCaId,
                    'cliente_nome' => $item['cliente']['nome'] ?? null,
                ]
            );

            // Se o saldo for 0 ou status fechado, remover para não aparecer como em aberto
            $statusNow = $item['status'] ?? null;
            if ($this->isClosedStatus($statusNow) || $saldoDevedor <= 0) {
                \App\Models\Invoice::where('connection_id', $connection->id)
                    ->where('ca_id', $caId)
                    ->delete();
            }
        }

        // 4. Soft Pruning: remove apenas faturas ABERTAS/EM ATRASO que não vieram na lista atual
        // Protege faturas pagas/baixadas/quitadas.
        if (! empty($processedCaIds)) {
            \App\Models\Invoice::where('connection_id', $connection->id)
                ->whereIn('status', ['OVERDUE', 'ATRASADO', 'PENDING', 'ABERTO'])
                ->whereNotIn('ca_id', $processedCaIds)
                ->delete();
        } else {
            // Se a lista estiver realmente vazia (nenhuma fatura aberta retornada pela API),
            // aplicamos pruning apenas sobre faturas ABERTAS/EM ATRASO desta conexão.
            if (count($allInvoices) === 0) {
                \App\Models\Invoice::where('connection_id', $connection->id)
                    ->whereIn('status', ['OVERDUE', 'ATRASADO', 'PENDING', 'ABERTO'])
                    ->delete();
            }
        }

        return count($allInvoices);
    }

    public function syncRecentlyClosedInvoices(ContaAzulConnection $connection): int
    {
        $startDate = \Carbon\Carbon::now()->subDays(180)->format('Y-m-d');
        $endDate = \Carbon\Carbon::now()->addDay()->format('Y-m-d');
        $size = 500;
        $statuses = ['PAID', 'PAGO', 'RECEIVED', 'RECEBIDO', 'CONCILIADO', 'LIQUIDADO', 'CANCELLED', 'CANCELADO', 'BAIXADO'];
        $updated = 0;

        $clientMap = \App\Models\Cliente::where('connection_id', $connection->id)
            ->pluck('id', 'ca_id')
            ->toArray();

        foreach ($statuses as $status) {
            $hasMore = true;
            $page = 1;
            while ($hasMore) {
                $data = $this->getAllOverdueInvoices($connection, $page, $size, $startDate, $endDate, $status);
                $items = $data['itens'] ?? [];
                if (empty($items)) {
                    $hasMore = false;
                } else {
                    foreach ($items as $item) {
                        $caId = $item['id'] ?? null;
                        if (! $caId) {
                            continue;
                        }
                        $clienteCaId = $item['cliente']['id'] ?? null;
                        $clienteLocalId = $clienteCaId ? ($clientMap[$clienteCaId] ?? null) : null;
                        $valorOriginal = isset($item['total']) ? (float) $item['total'] : 0.0;
                        $valorPago = isset($item['pago']) ? (float) $item['pago'] : null;
                        $naoPago = isset($item['nao_pago']) ? (float) $item['nao_pago'] : null;
                        $saldoDevedor = $naoPago ?? ($valorPago !== null ? max(0.0, $valorOriginal - $valorPago) : 0.0);

                        \App\Models\Invoice::updateOrCreate(
                            ['connection_id' => $connection->id, 'ca_id' => $caId],
                            [
                                'connection_id' => $connection->id,
                                'status' => $item['status'] ?? $status,
                                'valor_original' => $valorOriginal,
                                'saldo_devedor' => $saldoDevedor,
                                'descricao' => $item['descricao'] ?? null,
                                'reference_code' => $item['codigo_referencia'] ?? null,
                                'data_vencimento' => $this->normalizeDate($item['data_vencimento'] ?? ($item['vencimento'] ?? null)),
                                'data_emissao' => $this->normalizeDate($item['data_emissao'] ?? null),
                                'cliente_id' => $clienteLocalId,
                                'cliente_ca_id' => $clienteCaId,
                                'cliente_nome' => $item['cliente']['nome'] ?? null,
                            ]
                        );
                        $updated++;

                        // Se status fechado ou saldo zerado, remover da base para não aparecer nos filtros
                        $statusNow = ($item['status'] ?? $status);
                        if ($this->isClosedStatus($statusNow) || $saldoDevedor <= 0) {
                            \App\Models\Invoice::where('connection_id', $connection->id)
                                ->where('ca_id', $caId)
                                ->delete();
                        }

                        // Se for CANCELADA, tentar localizar uma substituta (novo boleto) com mesma referência/cliente
                        $isCancelled = (($item['status'] ?? $status) === 'CANCELLED') || (($item['status'] ?? $status) === 'CANCELADO');
                        if ($isCancelled && $clienteCaId) {
                            $ref = $item['codigo_referencia'] ?? $this->extractReferenceCode(($item['descricao'] ?? ''));
                            $replacementQuery = \App\Models\Invoice::where('connection_id', $connection->id)
                                ->where('cliente_ca_id', $clienteCaId)
                                ->whereIn('status', ['OVERDUE', 'ATRASADO', 'PENDING', 'ABERTO']);
                            if ($ref) {
                                $replacementQuery->where('reference_code', $ref);
                            } else {
                                // Fallback: tentar por valor original aproximado
                                $replacementQuery->where('valor_original', $valorOriginal);
                            }
                            $replacement = $replacementQuery->orderByDesc('data_emissao')->first();
                            if ($replacement) {
                                // Não precisamos fazer nada extra aqui; a seleção de cobrança usa apenas registros com saldo_devedor>0 e exclui cancelados
                                // Garantimos que a substituta estará presente na base e a cancelada não será cobrada.
                            }
                        }
                    }
                    if (count($items) < $size) {
                        $hasMore = false;
                    } else {
                        $page++;
                    }
                }
            }
        }

        return $updated;
    }

    protected function extractReferenceCode(?string $text): ?string
    {
        if (! $text) {
            return null;
        }
        // Procura sequências numéricas de 3+ dígitos (ex.: "Venda 1172")
        if (preg_match('/(\d{3,})/', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    protected function isClosedStatus(?string $status): bool
    {
        if (! $status) {
            return false;
        }
        $s = strtoupper($status);
        $closed = ['PAID', 'PAGO', 'RECEIVED', 'RECEBIDO', 'CONCILIADO', 'LIQUIDADO', 'CANCELLED', 'CANCELADO', 'BAIXADO'];

        return in_array($s, $closed, true);
    }

    protected function normalizeDate($raw): ?string
    {
        if (empty($raw)) {
            return null;
        }
        // Aceita formatos 'YYYY-MM-DD' ou 'DD/MM/YYYY'
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $raw)) {
            [$d, $m, $y] = explode('/', $raw);
            return sprintf('%04d-%02d-%02d', (int) $y, (int) $m, (int) $d);
        }
        try {
            return \Carbon\Carbon::parse($raw)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }
}
