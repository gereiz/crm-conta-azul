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
        $to = $this->normalizeDestination($to);

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
}
