<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\ContaAzulConnection;
use App\Models\ContaAzulToken;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ContaAzulService
{
    protected $baseUrl = 'https://api-v2.contaazul.com/v1';

    protected $clientId;

    protected $clientSecret;

    protected $redirectUri;

    protected $scope;

    public function __construct()
    {
        $this->clientId = trim(config('services.contaazul.client_id'));
        $this->clientSecret = trim(config('services.contaazul.client_secret'));
        $this->redirectUri = trim(config('services.contaazul.redirect_uri'));
        $rawScope = trim(config('services.contaazul.scope')) ?: 'openid profile email';
        $allowed = ['openid', 'profile', 'email', 'offline_access'];
        $parts = preg_split('/\s+/', trim($rawScope));
        $filtered = array_values(array_unique(array_intersect($parts, $allowed)));
        $this->scope = implode(' ', $filtered);
    }

    public function getAuthUrl()
    {
        if (empty($this->clientId) || empty($this->redirectUri)) {
            throw new \Exception('As credenciais do Conta Azul (CLIENT_ID ou REDIRECT_URI) não estão configuradas no arquivo .env.');
        }

        $state = bin2hex(random_bytes(16));
        session(['contaazul_state' => $state]);

        $query = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'scope' => $this->scope,
        ]);

        return "https://auth.contaazul.com/authorize?{$query}";
    }

    public function getToken($code)
    {
        $credentials = base64_encode("{$this->clientId}:{$this->clientSecret}");

        $response = Http::withHeaders([
            'Authorization' => "Basic {$credentials}",
        ])->asForm()->post('https://auth.contaazul.com/oauth2/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
        ]);

        if ($response->failed()) {
            Log::error('Erro ao obter token Conta Azul: '.$response->body());
            Log::error('Dados enviados: code='.substr($code, 0, 10).'... redirect_uri='.$this->redirectUri);

            return null;
        }

        $data = $response->json();
        Log::info('Token Conta Azul obtido com sucesso. Access Token prefix: '.substr($data['access_token'] ?? 'N/A', 0, 10).'...');

        return $data;
    }

    public function refreshToken($refreshToken)
    {
        $credentials = base64_encode("{$this->clientId}:{$this->clientSecret}");

        // Atualizado para auth.contaazul.com para manter consistência com getToken
        $response = Http::withHeaders([
            'Authorization' => "Basic {$credentials}",
        ])->asForm()->post('https://auth.contaazul.com/oauth2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if ($response->failed()) {
            Log::error('Erro ao atualizar token Conta Azul: '.$response->body());

            return null;
        }

        return $response->json();
    }

    public function saveToken(array $tokenData)
    {
        // Assume single token for now or latest user token
        // Se for multi-tenant, buscar pelo user_id ou tenant_id

        $token = ContaAzulToken::latest()->first(); // Pega o último token globalmente por enquanto, ou cria um novo

        if (! $token) {
            $token = new ContaAzulToken;
        }

        $token->access_token = $tokenData['access_token'];
        $token->refresh_token = $tokenData['refresh_token'];
        $token->expires_in = $tokenData['expires_in'];
        $token->expires_at = Carbon::now()->addSeconds($tokenData['expires_in']);
        $token->user_id = Auth::id(); // Opcional, quem salvou

        $token->save();

        return $token;
    }

    public function getValidToken($forceRefresh = false)
    {
        $token = ContaAzulToken::latest()->first();

        if (! $token) {
            return null;
        }

        // Se expirou, vai expirar em 5 minutos, ou forçado
        if ($forceRefresh || $token->expires_at->lt(Carbon::now()->addMinutes(5))) {
            Log::info('Atualizando token Conta Azul (Forçado: '.($forceRefresh ? 'Sim' : 'Não').')...');
            $newData = $this->refreshToken($token->refresh_token);

            if ($newData && isset($newData['access_token'])) {
                return $this->saveToken($newData)->access_token;
            } else {
                Log::error('Falha ao atualizar token Conta Azul. Necessário reconectar.');
                // Remove o token inválido para que o sistema saiba que está desconectado
                $token->delete();

                return null;
            }
        }

        return $token->access_token;
    }

    public function getClients($params = [])
    {
        // Mapeia parâmetros de paginação se necessário
        // A API v2 usa 'pagina' e 'tamanho_pagina'
        $apiParams = [
            'pagina' => $params['page'] ?? 1,
            'tamanho_pagina' => $params['size'] ?? 20,
        ];

        if (! empty($params['search'])) {
            $apiParams['pesquisa'] = $params['search'];
            // Tentativa com outros parâmetros comuns caso 'pesquisa' não funcione
            $apiParams['q'] = $params['search'];
            $apiParams['nome'] = $params['search'];
        }

        $response = $this->request('get', 'pessoas', $apiParams);
        Log::info('Conta Azul Response (getClients):', ['params' => $apiParams, 'response' => $response]);

        return $response;
    }

    public function getCobrancas($params = [])
    {
        $apiParams = [
            'pagina' => $params['page'] ?? 1,
            'tamanho_pagina' => $params['size'] ?? 100,
        ];

        // Endpoint baseado na documentação: financeiro/eventos-financeiros/contas-a-receber/cobranca
        $response = $this->request('get', 'financeiro/eventos-financeiros/contas-a-receber/cobranca', $apiParams);

        // Se falhar com o endpoint longo, tenta o curto 'cobrancas' como fallback
        if (! $response) {
            $response = $this->request('get', 'cobrancas', $apiParams);
        }

        return $response;
    }

    public function getCustomerInvoices($clienteId, $status = 'ATRASADO')
    {
        // Calcula datas para o filtro: 1 ano atrás até ontem
        $dataVencimentoDe = Carbon::now()->subYear()->format('Y-m-d');
        $dataVencimentoAte = Carbon::now()->subDay()->format('Y-m-d');

        $apiParams = [
            'pagina' => 1,
            'tamanho_pagina' => 1000,
            'data_vencimento_de' => $dataVencimentoDe,
            'data_vencimento_ate' => $dataVencimentoAte,
            'status' => $status,
            'cliente_id' => $clienteId,
        ];

        // Endpoint específico de busca
        $endpoint = 'financeiro/eventos-financeiros/contas-a-receber/buscar';

        $response = $this->request('get', $endpoint, $apiParams);

        Log::info('Conta Azul Response (getCustomerInvoices):', ['params' => $apiParams, 'response' => $response]);

        // Filtrar manualmente por cliente, já que a API parece ignorar o filtro cliente_id neste endpoint
        if (isset($response['itens']) && is_array($response['itens'])) {
            $filteredItems = array_filter($response['itens'], function ($item) use ($clienteId) {
                return isset($item['cliente']['id']) && $item['cliente']['id'] === $clienteId;
            });

            // Reindexar array
            $response['itens'] = array_values($filteredItems);
            $response['itens_totais'] = count($response['itens']);
        }

        return $response;
    }

    public function getAllOverdueInvoices($page = 1, $size = 1000, $startDate = null, $endDate = null)
    {
        $dataVencimentoDe = $startDate ?? Carbon::now()->subYear()->format('Y-m-d');
        $dataVencimentoAte = $endDate ?? Carbon::now()->subDay()->format('Y-m-d');

        $apiParams = [
            'pagina' => $page,
            'tamanho_pagina' => $size,
            'data_vencimento_de' => $dataVencimentoDe,
            'data_vencimento_ate' => $dataVencimentoAte,
            'status' => 'ATRASADO',
        ];

        $endpoint = 'financeiro/eventos-financeiros/contas-a-receber/buscar';

        return $this->request('get', $endpoint, $apiParams);
    }

    public function getInvoiceDetails($cobrancaId)
    {
        $endpoint = "financeiro/eventos-financeiros/parcelas/{$cobrancaId}";
        $response = $this->request('get', $endpoint);

        // Log::info('Conta Azul Response (getInvoiceDetails):', ['cobrancaId' => $cobrancaId, 'response' => $response]);

        $details = [
            'url' => null,
            'payment_type' => $response['metodo_pagamento'] ?? null,
        ];

        // Tenta pegar URL direta (caso exista em algumas versões)
        if (isset($response['url'])) {
            $details['url'] = $response['url'];
        }

        // Tenta pegar de solicitacoes_cobrancas (estrutura comum para boletos)
        if (empty($details['url']) && isset($response['solicitacoes_cobrancas']) && is_array($response['solicitacoes_cobrancas'])) {
            // Pega o último gerado ou o primeiro da lista? Geralmente o último é o mais recente.
            // O array parece vir indexado, vamos pegar o último elemento para garantir que é o mais atual
            // ou verificar status. Mas pegar o último deve bastar.
            $ultimaSolicitacao = end($response['solicitacoes_cobrancas']);
            if (isset($ultimaSolicitacao['url'])) {
                $details['url'] = $ultimaSolicitacao['url'];
            }
        }

        return $details;
    }

    public function syncOverdueInvoices()
    {
        // Estratégia: Obter todas as faturas em atraso da API e atualizar a base local.
        // Como o status pode mudar para PAGO, o ideal é limpar a tabela de faturas (ou marcar como resolvidas)
        // antes de inserir as novas, para garantir que o que está no banco é o retrato fiel do "Atrasado".
        // Por segurança, vamos usar updateOrCreate e depois remover as que não vieram na lista (se quisermos manter histórico, a lógica seria outra).
        // Mas para "Faturas em Atraso", Truncate + Insert é mais limpo se a tabela for só para isso.
        // Se a tabela for 'invoices' geral, não podemos truncar.
        // Assumindo que a tabela 'invoices' é para cache de faturas em atraso conforme o contexto atual.

        // Vamos buscar TODAS as páginas de faturas em atraso
        // Expandindo o range para 5 anos para garantir que pegamos faturas antigas
        $startDate = Carbon::now()->subYears(5)->format('Y-m-d');
        $endDate = Carbon::now()->subDay()->format('Y-m-d');

        $page = 1;
        $size = 1000;
        $hasMore = true;
        $allInvoices = [];

        while ($hasMore) {
            $data = $this->getAllOverdueInvoices($page, $size, $startDate, $endDate);

            if (empty($data['itens'])) {
                $hasMore = false;
            } else {
                $allInvoices = array_merge($allInvoices, $data['itens']);
                // Verifica se tem mais páginas
                // A API não retorna 'totalPages', então dependemos se o retorno for menor que o size
                if (count($data['itens']) < $size) {
                    $hasMore = false;
                } else {
                    $page++;
                }
            }
        }

        // Agora sincroniza com o banco
        // Se a tabela for EXCLUSIVA para atrasados, podemos truncar.
        // Vamos assumir que sim por enquanto, ou deletar apenas as que não estão na lista.
        $defaultConnection = ContaAzulConnection::orderBy('empresa_nome')->first();
        if ($defaultConnection) {
            Invoice::where('connection_id', $defaultConnection->id)->delete();
        } else {
            Invoice::whereNull('connection_id')->delete();
        }

        foreach ($allInvoices as $item) {
            // Tenta encontrar o cliente local
            $clienteCaId = $item['cliente']['id'] ?? null;
            $clienteLocal = null;

            if ($clienteCaId) {
                if ($defaultConnection) {
                    $clienteLocal = Cliente::where('connection_id', $defaultConnection->id)->where('ca_id', $clienteCaId)->first();
                } else {
                    $clienteLocal = Cliente::whereNull('connection_id')->where('ca_id', $clienteCaId)->first();
                }
            }

            // Buscar detalhes da fatura (boleto URL e tipo de pagamento)
            $invoiceDetails = ['url' => null, 'payment_type' => null];
            if (isset($item['id'])) {
                $invoiceDetails = $this->getInvoiceDetails($item['id']);
                // Pequeno delay para evitar rate limit agressivo se houver muitas faturas
                usleep(200000); // 0.2s
            }

            Invoice::updateOrCreate(
                [
                    'connection_id' => $defaultConnection?->id,
                    'ca_id' => $item['id'],
                ],
                [
                    'connection_id' => $defaultConnection?->id,
                    'status' => $item['status'], // OVERDUE
                    'payment_type' => $invoiceDetails['payment_type'],
                    'valor_original' => $item['total'] ?? 0,
                    'saldo_devedor' => $item['nao_pago'] ?? 0, // Campo correto para saldo devedor
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

    public function getOverdueInvoicesTotal()
    {
        $dataVencimentoDe = Carbon::now()->subYear()->format('Y-m-d');
        $dataVencimentoAte = Carbon::now()->subDay()->format('Y-m-d');

        $apiParams = [
            'pagina' => 1,
            'tamanho_pagina' => 1, // Não precisamos dos itens, só dos totais
            'data_vencimento_de' => $dataVencimentoDe,
            'data_vencimento_ate' => $dataVencimentoAte,
            'status' => 'ATRASADO',
        ];

        $endpoint = 'financeiro/eventos-financeiros/contas-a-receber/buscar';

        $response = $this->request('get', $endpoint, $apiParams);

        if (isset($response['totais']['vencido']['valor'])) {
            return [
                'count' => $response['itens_totais'] ?? 0,
                'value' => $response['totais']['vencido']['valor'],
            ];
        }

        return ['count' => 0, 'value' => 0.0];
    }

    // Método genérico para requisições com retry automático no 401
    public function request($method, $endpoint, $params = [], $retry = true, $attempts = 0)
    {
        $accessToken = $this->getValidToken();

        if (! $accessToken) {
            Log::warning('Tentativa de requisição sem token válido.');

            return null;
        }

        try {
            $response = Http::withToken($accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->timeout(120) // Aumenta timeout para 120 segundos
                ->$method("{$this->baseUrl}/{$endpoint}", $params);
        } catch (\Exception $e) {
            if ($retry && $attempts < 2) {
                Log::warning('Erro na requisição (Tentativa '.($attempts + 1).'): '.$e->getMessage().'. Tentando novamente...');
                sleep(2);

                return $this->request($method, $endpoint, $params, $retry, $attempts + 1);
            }
            Log::error("Erro fatal na requisição Conta Azul [{$endpoint}]: ".$e->getMessage());

            return null;
        }

        // Se der 401 (Unauthorized), tenta renovar o token e refazer a requisição
        if ($response->status() === 401 && $retry) {
            Log::warning("Token expirado ou inválido (401) na requisição [{$endpoint}]. Tentando renovar...");

            // Força renovação do token
            $newToken = $this->getValidToken(true);

            if ($newToken) {
                // Tenta novamente com o novo token
                return $this->request($method, $endpoint, $params, false, $attempts);
            }
        }

        if ($response->failed()) {
            Log::error("Erro na requisição Conta Azul [{$endpoint}]: ".$response->body());
            // Se ainda der 401, lança exceção para o controller saber que precisa reconectar
            if ($response->status() === 401) {
                throw new \Exception('Sessão expirada. Por favor, reconecte com a Conta Azul.');
            }

            return null; // Ou throw exception dependendo da estratégia
        }

        return $response->json();
    }
}
