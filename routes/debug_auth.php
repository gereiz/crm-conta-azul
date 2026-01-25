<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use App\Services\ContaAzulService;
use App\Models\ContaAzulToken;
use App\Models\ContaAzulConnection;
use App\Services\ContaAzulAuthService;
use App\Services\ContaAzulApiService;

Route::get('/debug-credentials', function () {
    $clientId = trim(config('services.contaazul.client_id'));
    $clientSecret = trim(config('services.contaazul.client_secret'));
    $redirectUri = trim(config('services.contaazul.redirect_uri'));

    echo "<h1>Diagnóstico de Credenciais e Banco de Dados</h1>";

    // 1. Teste de Banco de Dados
    echo "<h2>1. Teste de Conexão com Banco de Dados</h2>";
    try {
        DB::connection()->getPdo();
        echo "<p style='color:green'>✅ Conexão com Banco de Dados (MySQL) OK!</p>";
    } catch (\Exception $e) {
        echo "<p style='color:red'>❌ Erro ao conectar no Banco de Dados: " . $e->getMessage() . "</p>";
        echo "<p><strong>Solução:</strong> Verifique se o MySQL do Laragon está rodando (botão 'Start All' ou 'Database').</p>";
    }

    // 2. Teste de Credenciais Conta Azul
    echo "<h2>2. Teste de Credenciais Conta Azul</h2>";
    
    // Debug Visual das Credenciais
    $mask = function($str) {
        if (strlen($str) < 8) return "******";
        return substr($str, 0, 4) . "..." . substr($str, -4) . " (Tamanho: " . strlen($str) . ")";
    };
    
    echo "<div style='background:#f0f0f0; padding:10px; border:1px solid #ccc; margin-bottom:10px;'>";
    echo "<strong>Credenciais Carregadas pelo Laravel:</strong><br>";
    echo "Client ID: " . $mask($clientId) . "<br>";
    echo "Client Secret: " . $mask($clientSecret) . "<br>";
    echo "Redirect URI: " . htmlspecialchars($redirectUri) . "<br>";
    echo "</div>";

    echo "<p>Tentando trocar um código falso ('teste123') usando suas credenciais...</p>";

    // Teste A: Via Body (Padrão)
    $responseBody = Http::asForm()->post('https://api.contaazul.com/oauth2/token', [
        'grant_type' => 'authorization_code',
        'code' => 'teste_codigo_falso',
        'redirect_uri' => $redirectUri,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
    ]);

    echo "<h3>Teste A (Credenciais no Corpo):</h3>";
    dump_response($responseBody);

    // Teste B: Via Header (Basic Auth)
    $credentials = base64_encode("{$clientId}:{$clientSecret}");
    $responseHeader = Http::withHeaders([
        'Authorization' => "Basic {$credentials}"
    ])->asForm()->post('https://auth.contaazul.com/oauth2/token', [
        'grant_type' => 'authorization_code',
        'code' => 'teste_codigo_falso',
        'redirect_uri' => $redirectUri,
    ]);

    echo "<h3>Teste B (Basic Auth Header):</h3>";
    dump_response($responseHeader);
});

Route::get('/debug-clientes', function () {
    try {
        $service = app()->make(ContaAzulService::class);
        $result = $service->getClients([
            'page' => request()->input('page', 1),
            'size' => request()->input('size', 20),
            'search' => request()->input('search')
        ]);
        return response()->json($result);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});

Route::get('/debug-clientes-local', function () {
    $size = (int) request()->input('size', 20);
    $search = request()->input('search');
    $query = \App\Models\Cliente::query();
    if ($search) {
        $query->where(function($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%")
              ->orWhere('cpf_cnpj', 'like', "%{$search}%")
              ->orWhere('company_name', 'like', "%{$search}%");
        });
    }
    $clientes = $query->orderBy('name')->paginate($size)->withQueryString();
    return response()->json($clientes);
});

Route::get('/debug-clientes-direct', function () {
    $token = request()->input('token');
    if (!$token) {
        return response()->json(['error' => 'Informe ?token=...'], 400);
    }
    $params = [
        'pagina' => request()->input('page', 1),
        'tamanho_pagina' => request()->input('size', 20),
    ];
    if (request()->filled('search')) {
        $params['pesquisa'] = request()->input('search');
    }
    $endpoint = request()->input('endpoint', 'pessoas');
    try {
        $resp = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/json'])
            ->timeout(60)
            ->get("https://api.contaazul.com/v1/{$endpoint}", $params);
        return response()->json([
            'status' => $resp->status(),
            'data' => $resp->json(),
            'error' => $resp->failed() ? $resp->body() : null,
        ], $resp->status());
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});

Route::get('/debug-token', function () {
    $t = ContaAzulToken::latest()->first();
    if (!$t) return response()->json(['token' => null]);
    $data = [
        'access_token_prefix' => substr($t->access_token, 0, 10),
        'expires_in' => $t->expires_in,
        'expires_at' => $t->expires_at,
        'refresh_token_prefix' => substr($t->refresh_token, 0, 10),
    ];
    if (request()->boolean('full')) {
        $data['access_token'] = $t->access_token;
    }
    return response()->json($data);
});

Route::match(['get','post'],'/debug-seed-connection-from-env', function () {
    $clientId = trim(config('services.contaazul.client_id'));
    $clientSecret = trim(config('services.contaazul.client_secret'));
    $redirectUri = trim(config('services.contaazul.redirect_uri'));
    if (!$clientId || !$clientSecret || !$redirectUri) {
        return response()->json(['error' => 'Credenciais .env ausentes'], 400);
    }
    $conn = ContaAzulConnection::firstOrCreate(
        ['ca_client_id' => $clientId, 'ca_redirect_uri' => $redirectUri],
        [
            'empresa_nome' => env('APP_NAME', 'Empresa'),
            'email_desenvolvedor' => null,
            'ca_client_secret' => $clientSecret,
            'is_active' => true,
        ]
    );
    return response()->json(['connection_id' => $conn->id]);
});

Route::get('/debug-connections', function () {
    return response()->json(ContaAzulConnection::orderBy('empresa_nome')->get());
});

Route::get('/debug-auth-url/{id}', function ($id) {
    $conn = ContaAzulConnection::findOrFail($id);
    $auth = app()->make(ContaAzulAuthService::class);
    $url = $auth->getAuthUrl($conn);
    return response()->json([
        'connection_id' => $conn->id,
        'empresa_nome' => $conn->empresa_nome,
        'redirect_uri' => $conn->ca_redirect_uri,
        'client_id_prefix' => substr($conn->ca_client_id ?? '', 0, 6),
        'auth_url' => $url,
    ]);
});

Route::get('/debug-connections/{id}/clients', function ($id) {
    $conn = ContaAzulConnection::findOrFail($id);
    $api = app()->make(ContaAzulApiService::class);
    $data = $api->getClients($conn, [
        'page' => request()->input('page', 1),
        'size' => request()->input('size', 10),
        'search' => request()->input('search'),
    ]);
    return response()->json($data);
});
if (!function_exists('dump_response')) {
    function dump_response($response) {
        $json = $response->json();
        $status = $response->status();
        
        echo "Status: <strong>$status</strong><br>";
        echo "Resposta: <pre>" . json_encode($json, JSON_PRETTY_PRINT) . "</pre>";

        if (isset($json['error']) && $json['error'] === 'invalid_grant') {
            echo "<p style='color:green'>✅ <strong>SUCESSO:</strong> O Conta Azul rejeitou o código (esperado), mas ACEITOU suas credenciais!</p>";
        } elseif (isset($json['code']) && $json['code'] === 'invalid_client') {
            echo "<p style='color:red'>❌ <strong>FALHA:</strong> O Conta Azul rejeitou suas credenciais (Client ID ou Secret incorretos).</p>";
        } else {
            echo "<p style='color:orange'>⚠️ Resultado inconclusivo.</p>";
        }
    }
}
