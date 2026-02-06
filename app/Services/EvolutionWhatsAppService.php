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
                    if ($lastResponse->successful()) {
                        return $lastResponse;
                    }
                    if (in_array($lastResponse->status(), [401, 404])) {
                        continue;
                    }
                } catch (\Exception $e) {
                    $lastResponse = null;
                }
            }
        }
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

        try {
            $response = $this->tryRequest('POST', "/message/sendText/{$instance}", $token, [
                'number' => $to,
                'text' => $message,
                'linkPreview' => false,
            ]);

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

        try {
            $response = $this->tryRequest('GET', "/instance/connectionState/{$instance}", $token);

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
            if (in_array($digits[0], $oneDigit, true) || in_array(substr($digits, 0, 2), $twoDigit, true)) {
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
}
