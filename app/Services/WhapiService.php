<?php

namespace App\Services;

use App\Contracts\WhatsAppProviderInterface;
use App\Models\WhatsappNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhapiService implements WhatsAppProviderInterface
{
    protected $baseUrl = 'https://gate.whapi.cloud';

    public function getConnectionStatus(WhatsappNumber $whatsapp)
    {
        if (! $whatsapp || $whatsapp->status !== 'active') {
            return ['connected' => false, 'error' => 'Número inativo no sistema ou não encontrado.'];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.($whatsapp->provider_token ?? $whatsapp->whapi_key),
                'Accept' => 'application/json',
            ])->timeout(10)->get("{$this->baseUrl}/settings");

            if ($response->successful()) {
                return ['connected' => true, 'error' => null];
            }

            $status = $response->status();
            $body = $response->json();
            $msg = $body['error']['message'] ?? $response->body();

            Log::warning("Whapi Connection Check Failed (ID: {$whatsapp->id}): Status {$status} - {$msg}");

            if ($status === 401) {
                return ['connected' => false, 'error' => 'Não autorizado (401). Token inválido ou sessão expirada.'];
            }

            if ($status === 404) {
                return ['connected' => false, 'error' => 'Recurso não encontrado (404). Verifique se o token pertence a um canal válido.'];
            }

            return ['connected' => false, 'error' => "Erro na API Whapi ({$status}): ".Str::limit($msg, 100)];

        } catch (\Exception $e) {
            Log::error("Erro ao verificar conexão Whapi (ID: {$whatsapp->id}): ".$e->getMessage());

            return ['connected' => false, 'error' => 'Erro de comunicação: '.$e->getMessage()];
        }
    }

    public function checkConnection(WhatsappNumber $whatsapp)
    {
        return $this->getConnectionStatus($whatsapp);
    }

    public function isConnected(WhatsappNumber $whatsapp)
    {
        $status = $this->getConnectionStatus($whatsapp);

        return $status['connected'];
    }

    public function sendMessage($whatsappId, $to, $message)
    {
        $whatsapp = WhatsappNumber::find($whatsappId);

        if (! $whatsapp || $whatsapp->status !== 'active') {
            Log::error("Tentativa de envio com WhatsApp inválido ou inativo. ID: {$whatsappId}");

            return ['success' => false, 'message' => 'WhatsApp não configurado ou inativo.'];
        }

        $to = preg_replace('/\D/', '', $to ?? '');
        $to = ltrim($to, '0');

        if (in_array(strlen($to), [10, 11])) {
            $to = '55'.$to;
        }

        $endpoint = "{$this->baseUrl}/messages/text";

        $validId = $this->validateNumber($whatsapp, $to);
        if ($validId) {
            $to = $validId;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.($whatsapp->provider_token ?? $whatsapp->whapi_key),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post($endpoint, [
            'to' => $to,
            'body' => $message,
            'typing_time' => 0,
            'no_link_preview' => true,
        ]);

        if ($response->successful()) {
            $json = $response->json();
            $msg = null;
            if (isset($json['messages']) && is_array($json['messages']) && count($json['messages']) > 0) {
                $msg = $json['messages'][0];
            }
            $meta = [
                'whapi_status' => $msg['status'] ?? ($json['status'] ?? null),
                'message_id' => $msg['id'] ?? null,
                'chat_id' => $msg['chat_id'] ?? null,
            ];

            return ['success' => true, 'data' => $json, 'meta' => $meta];
        } else {
            $errorBody = $response->json();
            $errorMessage = $errorBody['error']['message'] ?? $response->body();

            Log::error('Erro Whapi: '.$response->body());

            if ($response->status() === 401 && str_contains($errorMessage, 'need channel authorization')) {
                return ['success' => false, 'message' => 'WhatsApp desconectado. Necessário ler o QR Code no painel da Whapi.'];
            }

            return ['success' => false, 'message' => 'Erro ao enviar mensagem via Whapi: '.$errorMessage];
        }
    }

    public function checkHealth()
    {
        $endpoint = "{$this->baseUrl}/health";

        try {
            $whatsapp = WhatsappNumber::where('status', 'active')->first();

            $request = Http::withHeaders([
                'Accept' => 'application/json',
            ]);

            if ($whatsapp) {
                $request->withToken($whatsapp->provider_token ?? $whatsapp->whapi_key);
            }

            $response = $request->get($endpoint);

            if ($response->successful()) {
                $data = $response->json();
                return ['status' => 'operational', 'details' => $data];
            }

            return ['status' => 'down', 'error' => $response->body()];
        } catch (\Exception $e) {
            Log::error('Erro ao verificar saúde Whapi: '.$e->getMessage());

            return ['status' => 'down', 'error' => $e->getMessage()];
        }
    }

    protected function validateNumber($whatsapp, $number)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.($whatsapp->provider_token ?? $whatsapp->whapi_key),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post("{$this->baseUrl}/contacts", [
                'blocking' => 'wait',
                'contacts' => [$number],
                'force_check' => true,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (! empty($data['contacts'][0]['status']) && $data['contacts'][0]['status'] === 'valid') {
                    return $data['contacts'][0]['wa_id'];
                }
            }
        } catch (\Exception $e) {
            Log::warning('Erro ao validar número na Whapi: '.$e->getMessage());
        }

        return null;
    }
}
