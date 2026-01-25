<?php

namespace App\Services;

use App\Models\ContaAzulConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ContaAzulAuthService
{
    public function getAuthUrl(ContaAzulConnection $connection): string
    {
        $statePayload = [
            'connection_id' => $connection->id,
            'nonce' => bin2hex(random_bytes(16)),
        ];

        $state = base64_encode(json_encode($statePayload));
        session(['contaazul_state' => $state]);

        $rawScope = (string)config('services.contaazul.scope', 'openid profile email sales customer service product contract');
        $rawScope = trim($rawScope, " \t\n\r\0\x0B\"'");
        
        // Escopos mínimos obrigatórios para o funcionamento do sistema
        $mandatory = ['sales', 'customer'];
        $allowed = ['openid','profile','email','offline_access','sales','customer','service','product','contract'];
        
        $tokens = preg_split('/[,\s]+/', trim($rawScope)) ?: [];
        $tokens = array_merge($tokens, $mandatory); // Garante que os obrigatórios estejam presentes
        $tokens = array_map(fn($s) => strtolower(trim($s)), $tokens);
        
        $filtered = array_values(array_unique(array_intersect($tokens, $allowed)));
        
        if (empty($filtered)) {
            $filtered = ['openid','profile','email','sales','customer'];
        }
        $scope = implode(' ', $filtered);

        // Usar a URL configurada no ambiente (.env) se disponível, ou a do banco como fallback
        $redirectUri = config('services.contaazul.redirect_uri') ?: trim($connection->ca_redirect_uri);

        // Fallback de segurança caso ambos estejam vazios (evita erro)
        if (empty($redirectUri)) {
            $redirectUri = route('contaazul.callback');
        }

        $params = [
            'client_id' => trim($connection->ca_client_id),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'scope' => $scope,
            'prompt' => 'login consent select_account',
            'max_age' => 0,
        ];
        
        $query = http_build_query($params);

        $url = "https://auth.contaazul.com/authorize?{$query}";
        Log::info("ContaAzul OAuth URL (conn {$connection->id}): {$url}");
        return $url;
    }

    public function exchangeCode(ContaAzulConnection $connection, string $code): ?array
    {
        $clientId = trim($connection->ca_client_id);
        $clientSecret = trim($connection->ca_client_secret);
        
        // Usar a URL configurada no ambiente (.env) se disponível, ou a do banco como fallback
        $redirectUri = config('services.contaazul.redirect_uri') ?: trim($connection->ca_redirect_uri);
        if (empty($redirectUri)) {
            $redirectUri = route('contaazul.callback');
        }

        $credentials = base64_encode("{$clientId}:{$clientSecret}");

        $response = Http::withOptions([
            'verify' => false,
        ])->withHeaders([
            'Authorization' => "Basic {$credentials}"
        ])->asForm()->post('https://auth.contaazul.com/oauth2/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);

        if ($response->failed()) {
            Log::error('Erro ao obter token Conta Azul (multi): ' . $response->body());
            return null;
        }

        return $response->json();
    }

    public function refreshToken(ContaAzulConnection $connection): ?string
    {
        try {
            $refreshToken = $connection->refresh_token;
            $clientSecret = $connection->ca_client_secret;
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            Log::error("Erro de descriptografia ao renovar token (conn {$connection->id}): " . $e->getMessage());
            return null;
        }

        if (!$refreshToken) {
            return null;
        }

        $credentials = base64_encode("{$connection->ca_client_id}:{$clientSecret}");

        $response = Http::withOptions([
            'verify' => false,
        ])->withHeaders([
            'Authorization' => "Basic {$credentials}"
        ])->asForm()->post('https://auth.contaazul.com/oauth2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if ($response->failed()) {
            Log::error("Erro ao atualizar token Conta Azul (connection {$connection->id}): " . $response->body());
            return null;
        }

        $data = $response->json();

        $connection->access_token = $data['access_token'] ?? null;
        $connection->refresh_token = $data['refresh_token'] ?? $connection->refresh_token;
        $connection->token_expires_at = isset($data['expires_in'])
            ? Carbon::now()->addSeconds($data['expires_in'])
            : null;
        $connection->save();

        return $connection->access_token;
    }

    public function getValidToken(ContaAzulConnection $connection, bool $forceRefresh = false): ?string
    {
        try {
            $accessToken = $connection->access_token;
            $expiresAt = $connection->token_expires_at;
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            Log::warning("Falha de descriptografia no token da conexão {$connection->id}. Tratando como inválido.");
            $accessToken = null;
            $expiresAt = null;
            // Força refresh ou reconexão
            $forceRefresh = true;
        }

        if (
            !$accessToken ||
            $forceRefresh ||
            ($expiresAt && $expiresAt->lt(Carbon::now()->addMinutes(5)))
        ) {
            return $this->refreshToken($connection);
        }

        return $accessToken;
    }

    public function saveTokens(ContaAzulConnection $connection, array $tokenData): ContaAzulConnection
    {
        $connection->access_token = $tokenData['access_token'] ?? null;
        $connection->refresh_token = $tokenData['refresh_token'] ?? null;
        $connection->token_expires_at = isset($tokenData['expires_in'])
            ? Carbon::now()->addSeconds($tokenData['expires_in'])
            : null;
        $connection->save();

        return $connection;
    }
}
