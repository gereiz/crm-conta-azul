<?php

namespace App\Services;

use App\Models\ContaAzulConnection;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
class ContaAzulAuthService
{
    public function decodeState(?string $state): ?array
    {
        if (! is_string($state) || trim($state) === '') {
            return null;
        }

        $decoded = base64_decode($state, true);
        if ($decoded === false) {
            return null;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload) ? $payload : null;
    }

    public function getSavedState(?int $connectionId = null): ?string
    {
        $keys = [];
        if ($connectionId) {
            $keys[] = 'contaazul_state_'.$connectionId;
        }
        $keys[] = 'contaazul_state';

        foreach ($keys as $key) {
            $value = session($key);
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    public function clearOAuthSession(?int $connectionId = null): void
    {
        $keys = ['contaazul_state', 'contaazul_redirect_uri'];

        if ($connectionId) {
            $keys[] = 'contaazul_state_'.$connectionId;
            $keys[] = 'contaazul_redirect_uri_'.$connectionId;
        }

        session()->forget($keys);
    }

    protected function resolveRedirectUri(?ContaAzulConnection $connection = null): string
    {
        $configUri = trim((string) config('services.contaazul.redirect_uri'));
        $connectionUri = trim((string) ($connection->ca_redirect_uri ?? ''));

        $routeUri = '';
        try {
            $routeUri = trim((string) route('contaazul.callback'));
        } catch (\Throwable $e) {
            $routeUri = '';
        }

        $sessionUri = '';
        $sessionKeys = [];
        if ($connection?->id) {
            $sessionKeys[] = 'contaazul_redirect_uri_'.$connection->id;
        }
        $sessionKeys[] = 'contaazul_redirect_uri';

        foreach ($sessionKeys as $sessionKey) {
            $value = trim((string) session($sessionKey, ''));
            if ($value !== '') {
                $sessionUri = $value;
                break;
            }
        }

        foreach ([$configUri, $connectionUri, $routeUri, $sessionUri] as $candidate) {
            if ($candidate === '') {
                continue;
            }

            return $candidate;
        }

        return $configUri ?: ($connectionUri ?: ($routeUri ?: $sessionUri));
    }

    public function getAuthUrl(ContaAzulConnection $connection): string
    {
        $statePayload = [
            'connection_id' => $connection->id,
            'nonce' => bin2hex(random_bytes(16)),
        ];

        $state = base64_encode(json_encode($statePayload));
        // Use session array key to allow multiple connection attempts or specific connection state
        session(['contaazul_state_'.$connection->id => $state]);
        // Fallback for generic controller if needed, but we should prefer specific
        session(['contaazul_state' => $state]);

        $scope = trim((string) config('services.contaazul.scope', 'openid profile aws.cognito.signin.user.admin'));
        if ($scope === '') {
            $scope = 'openid profile aws.cognito.signin.user.admin';
        }

        $redirectUri = $this->resolveRedirectUri($connection);
        session(['contaazul_redirect_uri_'.$connection->id => $redirectUri]);
        session(['contaazul_redirect_uri' => $redirectUri]);
        if ($connection->ca_redirect_uri !== $redirectUri) {
            $connection->forceFill(['ca_redirect_uri' => $redirectUri])->save();
        }

        $params = [
            'client_id' => trim($connection->ca_client_id),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'scope' => $scope,
        ];

        $query = http_build_query($params);

        $url = "https://auth.contaazul.com/login?{$query}";
        Log::info("ContaAzul OAuth URL (conn {$connection->id}): {$url}", [
            'resolved_redirect_uri' => $redirectUri,
            'config_redirect_uri' => trim((string) config('services.contaazul.redirect_uri')),
            'connection_redirect_uri' => trim((string) ($connection->ca_redirect_uri ?? '')),
        ]);

        return $url;
    }

    public function exchangeCode(ContaAzulConnection $connection, string $code): ?array
    {
        $clientId = trim($connection->ca_client_id);

        try {
            // Tenta obter o segredo do banco. Se a APP_KEY mudou, isso vai lançar exceção.
            // Para novas conexões, o segredo pode vir vazio se foi salvo incorretamente antes.
            $clientSecret = $connection->ca_client_secret;
            if (empty($clientSecret)) {
                throw new \Illuminate\Contracts\Encryption\DecryptException('Client Secret vazio no banco.');
            }
            $clientSecret = trim($clientSecret);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            Log::warning("Erro de descriptografia ou secret vazio na conexão {$connection->id}. Tentando fallback do .env.");

            // Fallback: tenta pegar do .env
            $clientSecret = config('services.contaazul.client_secret');

            if (empty($clientSecret)) {
                // Última tentativa: se o usuário acabou de preencher o formulário de conexão,
                // o request original pode ter o client_secret. Mas aqui estamos no serviço.
                // Se falhar aqui, é fatal.
                Log::error("Client Secret não encontrado no .env e falha de descriptografia no banco para conexão {$connection->id}.");

                return ['error' => 'decrypt_error'];
            }

            // Opcional: Atualizar o banco com o valor do .env para corrigir o registro corrompido
            try {
                // IMPORTANTE: Ao salvar aqui, o Eloquent usará a APP_KEY atual para criptografar.
                // Isso "conserta" o registro para o futuro.
                $connection->ca_client_secret = $clientSecret;
                $connection->save();
                Log::info("Client Secret da conexão {$connection->id} corrigido automaticamente usando valor do .env.");
            } catch (\Exception $saveError) {
                Log::error('Falha ao tentar corrigir Client Secret no banco: '.$saveError->getMessage());
            }
        }

        $redirectUri = $this->resolveRedirectUri($connection);

        $credentials = base64_encode("{$clientId}:{$clientSecret}");

        $response = Http::withOptions([
            'verify' => false,
        ])->withHeaders([
            'Authorization' => "Basic {$credentials}",
        ])->asForm()->post('https://auth.contaazul.com/oauth2/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);

        if ($response->failed()) {
            Log::error('Erro ao obter token Conta Azul (multi): '.$response->body());

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
            Log::warning("Erro de descriptografia ao renovar token (conn {$connection->id}). Tentando recuperar.");

            // Tenta recuperar Client Secret do .env se falhar
            $clientSecret = config('services.contaazul.client_secret');

            // Se o refresh token estiver corrompido, não há o que fazer além de retornar null
            // O getValidToken vai lidar com isso forçando nova autenticação
            if (empty($clientSecret)) {
                return null;
            }

            // Se conseguimos o secret, mas o refresh token falhou, retornamos null
            try {
                $refreshToken = $connection->refresh_token;
            } catch (\Exception $ex) {
                return null;
            }
        }

        if (! $refreshToken) {
            return null;
        }

        $credentials = base64_encode("{$connection->ca_client_id}:{$clientSecret}");

        $response = Http::withOptions([
            'verify' => false,
        ])->withHeaders([
            'Authorization' => "Basic {$credentials}",
        ])->asForm()->post('https://auth.contaazul.com/oauth2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if ($response->failed()) {
            Log::error("Erro ao atualizar token Conta Azul (connection {$connection->id}): ".$response->body());

            return null;
        }

        $data = $response->json();
        $this->persistTokens($connection, [
            'access_token' => $data['access_token'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_in' => $data['expires_in'] ?? null,
        ], preserveExistingRefreshToken: true);

        return $data['access_token'] ?? null;
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
            ! $accessToken ||
            $forceRefresh ||
            ($expiresAt && $expiresAt->lt(Carbon::now()->addMinutes(5)))
        ) {
            return $this->refreshToken($connection);
        }

        return $accessToken;
    }

    public function saveTokens(ContaAzulConnection $connection, array $tokenData): ContaAzulConnection
    {
        $this->persistTokens($connection, $tokenData);

        return $connection;
    }

    protected function persistTokens(ContaAzulConnection $connection, array $tokenData, bool $preserveExistingRefreshToken = false): void
    {
        $accessToken = $tokenData['access_token'] ?? null;
        $refreshToken = $tokenData['refresh_token'] ?? null;
        $expiresAt = isset($tokenData['expires_in'])
            ? Carbon::now()->addSeconds((int) $tokenData['expires_in'])
            : null;

        $updates = [
            'access_token' => $accessToken !== null ? Crypt::encryptString((string) $accessToken) : null,
            'token_expires_at' => $expiresAt,
            'updated_at' => now(),
        ];

        if ($refreshToken !== null || ! $preserveExistingRefreshToken) {
            $updates['refresh_token'] = $refreshToken !== null
                ? Crypt::encryptString((string) $refreshToken)
                : null;
        }

        DB::table('conta_azul_connections')
            ->where('id', $connection->id)
            ->update($updates);

        $connection->forceFill([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken !== null
                ? $refreshToken
                : ($preserveExistingRefreshToken ? null : null),
            'token_expires_at' => $expiresAt,
        ]);
    }
}
