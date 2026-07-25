<?php

namespace App\Services;

use App\Contracts\WhatsAppProviderInterface;
use App\Models\SystemSetting;
use App\Models\WhatsappNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EvolutionWhatsAppService implements WhatsAppProviderInterface
{
    protected string $baseUrl = 'https://n8n-evolution-api.inugyp.easypanel.host';
    protected array $baseUrls = [];

    public function __construct()
    {
        try {
            $settings = SystemSetting::latest()->first();
            if ($settings && $settings->evolution_api_base_url) {
                $this->baseUrl = rtrim($settings->evolution_api_base_url, '/');
            }
        } catch (\Throwable $e) {
            // Ignora erros de conexão durante comandos artisan/migrações
        }
        $this->baseUrls = array_unique([
            $this->baseUrl,
            rtrim($this->baseUrl, '/').'/api',
            'https://n8n-evolution-api.inugyp.easypanel.host',
        ]);
    }

    protected function tryRequest(string $method, string $path, string $token, array $payload = null)
    {
        // #region debug-point A:try-request-entry
        $traceId = substr(bin2hex(random_bytes(8)), 0, 12);
        $this->reportDebug('A', 'app/Services/EvolutionWhatsAppService.php:tryRequest:entry', '[DEBUG] Evolution tryRequest entry', [
            'traceId' => $traceId,
            'method' => $method,
            'path' => $path,
            'base_url_count' => count($this->baseUrls),
            'has_payload' => $payload !== null,
            'token_hash' => substr(sha1((string) $token), 0, 12),
        ]);
        // #endregion
        $lastResponse = null;
        foreach ($this->baseUrls as $base) {
            $url = rtrim($base, '/').'/'.ltrim($path, '/');
            $headersList = [
                ['apikey' => $token, 'Accept' => 'application/json', 'Content-Type' => 'application/json'],
                ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json', 'Content-Type' => 'application/json'],
            ];
            foreach ($headersList as $headers) {
                try {
                    $req = Http::withHeaders($headers)->timeout(10);
                    $lastResponse = $method === 'GET' ? $req->get($url) : $req->post($url, $payload ?? []);
                    // #region debug-point B:try-request-response
                    $this->reportDebug('B', 'app/Services/EvolutionWhatsAppService.php:tryRequest:response', '[DEBUG] Evolution tryRequest response', [
                        'traceId' => $traceId,
                        'url' => $url,
                        'auth_mode' => array_key_exists('apikey', $headers) ? 'apikey' : 'bearer',
                        'response_is_null' => $lastResponse === null,
                        'status' => $lastResponse?->status(),
                        'successful' => $lastResponse?->successful(),
                        'body_preview' => $lastResponse ? mb_substr($lastResponse->body(), 0, 300) : null,
                    ]);
                    // #endregion
                    if ($lastResponse->successful()) {
                        return $lastResponse;
                    }
                    if (in_array($lastResponse->status(), [401, 404])) {
                        continue;
                    }
                } catch (\Exception $e) {
                    $lastResponse = null;
                    // #region debug-point C:try-request-exception
                    $this->reportDebug('C', 'app/Services/EvolutionWhatsAppService.php:tryRequest:exception', '[DEBUG] Evolution tryRequest exception', [
                        'traceId' => $traceId,
                        'url' => $url,
                        'auth_mode' => array_key_exists('apikey', $headers) ? 'apikey' : 'bearer',
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                    ]);
                    // #endregion
                }
            }
        }
        // #region debug-point B:try-request-exhausted
        $this->reportDebug('B', 'app/Services/EvolutionWhatsAppService.php:tryRequest:exhausted', '[DEBUG] Evolution tryRequest exhausted', [
            'traceId' => $traceId,
            'last_response_is_null' => $lastResponse === null,
            'last_status' => $lastResponse?->status(),
            'last_body_preview' => $lastResponse ? mb_substr($lastResponse->body(), 0, 300) : null,
        ]);
        // #endregion
        return $lastResponse;
    }

    public function sendMessage($whatsappId, $to, $message)
    {
        $whatsapp = WhatsappNumber::find($whatsappId);
        if (! $whatsapp || $whatsapp->status !== 'active') {
            return ['success' => false, 'message' => 'WhatsApp não configurado ou inativo.'];
        }
        if ($whatsapp->provider !== 'evolution') {
            return ['success' => false, 'message' => 'Número não configurado para Evolution API.'];
        }

        $to = preg_replace('/\D/', '', $to ?? '');
        $to = ltrim($to, '0');
        $to = $this->normalizeDestination($to);

        $instance = $whatsapp->provider_instance;
        $token = $whatsapp->provider_token;
        if (! $instance || ! $token) {
            return ['success' => false, 'message' => 'Instância ou token ausente para Evolution API.'];
        }

        // #region debug-point D:send-message-entry
        $caller = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6))
            ->map(fn ($frame) => ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? ''))
            ->filter()
            ->values()
            ->all();
        $sendTraceId = substr(bin2hex(random_bytes(8)), 0, 12);
        $this->reportDebug('D', 'app/Services/EvolutionWhatsAppService.php:sendMessage:entry', '[DEBUG] Evolution sendMessage entry', [
            'traceId' => $sendTraceId,
            'whatsapp_id' => $whatsapp->id,
            'provider_instance' => $instance,
            'to' => $to,
            'message_length' => mb_strlen((string) $message),
            'caller_stack' => $caller,
        ]);
        // #endregion

        try {
            $response = $this->tryRequest('POST', "/message/sendText/{$instance}", $token, [
                'number' => $to,
                'text' => $message,
                'linkPreview' => false,
            ]);

            // #region debug-point D:send-message-response
            $this->reportDebug('D', 'app/Services/EvolutionWhatsAppService.php:sendMessage:response', '[DEBUG] Evolution sendMessage response received', [
                'traceId' => $sendTraceId,
                'response_is_null' => $response === null,
                'status' => $response?->status(),
                'successful' => $response?->successful(),
                'body_preview' => $response ? mb_substr($response->body(), 0, 300) : null,
            ]);
            // #endregion

            if (! $response) {
                Log::warning("Evolution send failed (ID: {$whatsapp->id}): sem resposta HTTP da API.");

                return ['success' => false, 'message' => 'Sem resposta da Evolution API. Verifique a URL base, DNS, SSL e conectividade da VPS.'];
            }

            if ($response->successful()) {
                $data = $response->json();
                $status = $data['status'] ?? 'queued';
                $messageId = $data['messageId'] ?? ($data['queueId'] ?? null);

                return [
                    'success' => true,
                    'meta' => [
                        'provider' => 'evolution',
                        'evolution_status' => $status,
                        'message_id' => $messageId,
                    ],
                ];
            }

            $status = $response->status();
            $body = $response->json();
            $msg = $body['message'] ?? ($body['error'] ?? $response->body());

            Log::warning("Evolution send failed (ID: {$whatsapp->id}): {$status} - {$msg}");

            return ['success' => false, 'message' => $msg ?: "Erro na Evolution API ({$status})"];
        } catch (\Exception $e) {
            Log::error("Erro ao enviar mensagem Evolution (ID: {$whatsapp->id}): ".$e->getMessage());

            return ['success' => false, 'message' => 'Erro de comunicação: '.$e->getMessage()];
        }
    }

    public function checkConnection(WhatsappNumber $whatsapp)
    {
        if (! $whatsapp || $whatsapp->status !== 'active') {
            return ['connected' => false, 'error' => 'Número inativo no sistema ou não encontrado.'];
        }
        if ($whatsapp->provider !== 'evolution') {
            return ['connected' => false, 'error' => 'Número não configurado para Evolution API.'];
        }
        $instance = $whatsapp->provider_instance;
        $token = $whatsapp->provider_token;
        if (! $instance || ! $token) {
            return ['connected' => false, 'error' => 'Instância ou token ausente para Evolution API.'];
        }

        // #region debug-point E:check-connection-entry
        $checkTraceId = substr(bin2hex(random_bytes(8)), 0, 12);
        $this->reportDebug('E', 'app/Services/EvolutionWhatsAppService.php:checkConnection:entry', '[DEBUG] Evolution checkConnection entry', [
            'traceId' => $checkTraceId,
            'whatsapp_id' => $whatsapp->id,
            'provider_instance' => $instance,
        ]);
        // #endregion

        try {
            $response = $this->tryRequest('GET', "/instance/connectionState/{$instance}", $token);

            // #region debug-point E:check-connection-response
            $this->reportDebug('E', 'app/Services/EvolutionWhatsAppService.php:checkConnection:response', '[DEBUG] Evolution checkConnection response received', [
                'traceId' => $checkTraceId,
                'response_is_null' => $response === null,
                'status' => $response?->status(),
                'successful' => $response?->successful(),
                'body_preview' => $response ? mb_substr($response->body(), 0, 300) : null,
            ]);
            // #endregion

            if (! $response) {
                Log::warning("Evolution connection check failed (ID: {$whatsapp->id}): sem resposta HTTP da API.");

                return ['connected' => false, 'error' => 'Sem resposta da Evolution API. Verifique a URL base, DNS, SSL e conectividade da VPS.'];
            }

            if ($response->successful()) {
                $data = $response->json();
                $state = $data['connection_status'] ?? ($data['state'] ?? ($data['status'] ?? ($data['instance']['state'] ?? null)));
                $connected = in_array(strtolower((string) $state), ['open', 'connected', 'online']);

                return ['connected' => $connected, 'error' => null];
            }

            $status = $response->status();
            $body = $response->json();
            $msg = $body['message'] ?? ($body['error'] ?? $response->body());

            Log::warning("Evolution connection check failed (ID: {$whatsapp->id}): {$status} - {$msg}");

            if ($status === 401) {
                return ['connected' => false, 'error' => 'Não autorizado (401). Token inválido ou sessão expirada.'];
            }
            if ($status === 404) {
                return ['connected' => false, 'error' => 'Instância não encontrada (404).'];
            }

            return ['connected' => false, 'error' => "Erro na Evolution API ({$status}): ".$msg];
        } catch (\Exception $e) {
            Log::error("Erro ao verificar conexão Evolution (ID: {$whatsapp->id}): ".$e->getMessage());

            return ['connected' => false, 'error' => 'Erro de comunicação: '.$e->getMessage()];
        }
    }

    protected function normalizeDestination(string $digits): string
    {
        if (empty($digits)) {
            return '55';
        }
        $len = strlen($digits);
        if ($len >= 12) {
            return $digits;
        }
        if ($len === 11 && ! str_starts_with($digits, '55')) {
            $oneDigit = ['1','7'];
            $twoDigit = [
                '20','27',
                '30','31','32','33','34','36','39',
                '40','41','43','44','45','46','47','48','49',
                '51','52','53','54','56','57','58',
                '60','61','62','63','64','65','66',
                '81','82','84','86',
                '90','91','92','93','94','95','98','99',
            ];
            $threeDigit = [
                '212','213','216','218','220','221','222','223','224','225','226','227','228','229','230','231','232','233','234','235','236','237','238','239','240','241','242','243','244','245','248','249','250','251','252','253','254','255','256','257','258','260','261','262','263','264','265','266','267','268','269',
                '350','351','352','353','354','355','356','357','358','359',
                '380','381','382','385','386','387',
                '420','421','423',
                '965','966','967','968','970','971','972','973','974','975','976','977',
                '880','852','853','886'
            ];
            if (in_array($digits[0], $oneDigit, true) || in_array(substr($digits, 0, 2), $twoDigit, true) || in_array(substr($digits, 0, 3), $threeDigit, true)) {
                return $digits;
            }
        }
        if (! str_starts_with($digits, '55')) {
            return '55'.$digits;
        }
        return $digits;
    }

    public function getQrCode(WhatsappNumber $whatsapp)
    {
        if (! $whatsapp || $whatsapp->status !== 'active') {
            return ['success' => false, 'error' => 'Número inativo ou não encontrado.'];
        }
        if ($whatsapp->provider !== 'evolution') {
            return ['success' => false, 'error' => 'Número não configurado para Evolution API.'];
        }
        $instance = $whatsapp->provider_instance;
        $token = $whatsapp->provider_token;
        if (! $instance || ! $token) {
            return ['success' => false, 'error' => 'Instância ou token ausente.'];
        }
        $paths = [
            "/instance/connect/{$instance}",
            "/instance/qrCode/{$instance}",
            "/instance/qr/{$instance}",
        ];
        foreach ($paths as $p) {
            $response = $this->tryRequest('GET', $p, $token);
            if ($response && $response->successful()) {
                $json = $response->json();
                $qr = $json['qrCode'] ?? ($json['qrcode'] ?? ($json['base64'] ?? ($json['data']['qrCode'] ?? ($json['data']['qrcode'] ?? ($json['data']['base64'] ?? ($json['instance']['qrCode'] ?? ($json['instance']['qrcode'] ?? null)))))));
                if (is_string($qr) && strlen($qr) > 50) {
                    $prefix = str_starts_with($qr, 'data:') ? '' : 'data:image/png;base64,';
                    return ['success' => true, 'data_url' => $prefix.$qr];
                }
                if (isset($json['qrcode'])) {
                    return ['success' => true, 'qr_text' => $json['qrcode']];
                }
            }
        }
        return ['success' => false, 'error' => 'QR não disponível.'];
    }

    protected function reportDebug(string $hypothesisId, string $location, string $msg, array $data = []): void
    {
        try {
            $envPath = base_path('.dbg/evolution-null-response.env');
            $debugUrl = 'http://127.0.0.1:7777/event';
            $sessionId = 'evolution-null-response';

            if (is_file($envPath)) {
                $envContent = (string) file_get_contents($envPath);
                if (preg_match('/^DEBUG_SERVER_URL=(.+)$/m', $envContent, $match)) {
                    $debugUrl = trim($match[1]);
                }
                if (preg_match('/^DEBUG_SESSION_ID=(.+)$/m', $envContent, $match)) {
                    $sessionId = trim($match[1]);
                }
            }

            Http::timeout(2)->asJson()->post($debugUrl, [
                'sessionId' => $sessionId,
                'runId' => 'pre-fix',
                'hypothesisId' => $hypothesisId,
                'location' => $location,
                'msg' => $msg,
                'data' => $data,
                'ts' => (int) round(microtime(true) * 1000),
            ]);
        } catch (\Throwable $e) {
            // Ignora falhas do coletor para não interferir no fluxo normal.
        }
    }
}
