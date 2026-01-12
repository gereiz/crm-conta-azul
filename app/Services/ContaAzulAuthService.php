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

        $rawScope = config('services.contaazul.scope', 'openid profile email');
        $allowed = ['openid','profile','email','offline_access'];
        $parts = preg_split('/\s+/', trim($rawScope));
        $filtered = array_values(array_unique(array_intersect($parts, $allowed)));
        $scope = implode(' ', $filtered);

        $params = [
            'client_id' => $connection->ca_client_id,
            'redirect_uri' => $connection->ca_redirect_uri,
            'state' => $state,
            'response_type' => 'code',
            'scope' => $scope,
        ];
        if (!empty($connection->email_desenvolvedor)) {
            $params['login_hint'] = $connection->email_desenvolvedor;
        }
        $query = http_build_query($params);

        return "https://auth.contaazul.com/authorize?{$query}";
    }

    public function exchangeCode(ContaAzulConnection $connection, string $code): ?array
    {
        $credentials = base64_encode("{$connection->ca_client_id}:{$connection->ca_client_secret}");

        $response = Http::withHeaders([
            'Authorization' => "Basic {$credentials}"
        ])->asForm()->post('https://auth.contaazul.com/oauth2/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $connection->ca_redirect_uri,
        ]);

        if ($response->failed()) {
            Log::error('Erro ao obter token Conta Azul (multi): ' . $response->body());
            return null;
        }

        return $response->json();
    }

    public function refreshToken(ContaAzulConnection $connection): ?string
    {
        if (!$connection->refresh_token) {
            return null;
        }

        $credentials = base64_encode("{$connection->ca_client_id}:{$connection->ca_client_secret}");

        $response = Http::withHeaders([
            'Authorization' => "Basic {$credentials}"
        ])->asForm()->post('https://auth.contaazul.com/oauth2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
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
        if (
            !$connection->access_token ||
            $forceRefresh ||
            ($connection->token_expires_at && $connection->token_expires_at->lt(Carbon::now()->addMinutes(5)))
        ) {
            return $this->refreshToken($connection);
        }

        return $connection->access_token;
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
