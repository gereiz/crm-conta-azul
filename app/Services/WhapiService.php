<?php

namespace App\Services;

use App\Models\WhatsappNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhapiService
{
    protected $baseUrl = 'https://gate.whapi.cloud';

    public function getConnectionStatus(WhatsappNumber $whatsapp)
    {
        if (! $whatsapp || $whatsapp->status !== 'active') {
            return ['connected' => false, 'error' => 'Número inativo no sistema ou não encontrado.'];
        }

        try {
            // Tenta endpoint de configurações do canal (mais robusto e disponível para todos os tipos)
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$whatsapp->whapi_key}",
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

        // Normalização BR (somente dígitos, sem '+', prefixa 55 se ausente, remove zeros iniciais)
        $to = preg_replace('/\D/', '', $to ?? '');
        $to = ltrim($to, '0');

        // Se o número tiver 10 ou 11 dígitos, assumimos que é BR sem DDI
        if (in_array(strlen($to), [10, 11])) {
            $to = '55'.$to;
        }
        // Se já tiver 12 ou 13 dígitos e começar com 55, mantemos (já tem DDI)
        // Números internacionais devem vir com DDI completo, então confiamos se não cair na regra acima

        $endpoint = "{$this->baseUrl}/messages/text";

        // Tenta validar o número na Whapi para obter o ID correto (corrige 9º dígito em regiões específicas)
        $validId = $this->validateNumber($whatsapp, $to);
        if ($validId) {
            $to = $validId;
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$whatsapp->whapi_key}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post($endpoint, [
            'to' => $to,
            'body' => $message,
            'typing_time' => 0,
            'no_link_preview' => true, // Parâmetro correto conforme documentação Whapi
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
        // Documentação: https://whapi.readme.io/reference/checkhealth
        $endpoint = "{$this->baseUrl}/health";

        try {
            // Tenta obter o primeiro WhatsApp ativo para usar o token (embora /health geralmente seja público ou requeira qualquer token válido)
            // A documentação não especifica auth para health, mas geralmente é bom ter.
            // No entanto, se não houver WhatsApp, podemos tentar sem auth ou retornar erro.
            // Vamos assumir que precisamos de um token se houver um configurado.

            $whatsapp = WhatsappNumber::where('status', 'active')->first();

            $request = Http::withHeaders([
                'Accept' => 'application/json',
            ]);

            if ($whatsapp) {
                $request->withToken($whatsapp->whapi_key);
            }

            $response = $request->get($endpoint);

            if ($response->successful()) {
                $data = $response->json();
                // Verifica se o status é operacional.
                // A doc diz que retorna "status": "operational" (ou algo similar, vamos assumir sucesso do request como operacional por enquanto,
                // ou verificar o campo 'status' se presente na resposta real).
                // Exemplo doc: {"status": "operational", ...} (hipotético, ajustar conforme retorno real se necessário)

                // Se a resposta for 200 OK, assumimos operacional
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
                'Authorization' => "Bearer {$whatsapp->whapi_key}",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post("{$this->baseUrl}/contacts", [
                'blocking' => 'wait',
                'contacts' => [$number],
                'force_check' => true,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                // Retorna o wa_id correto se o número for válido
                if (! empty($data['contacts'][0]['status']) && $data['contacts'][0]['status'] === 'valid') {
                    return $data['contacts'][0]['wa_id']; // Ex: 553399657810@s.whatsapp.net
                }
            }
        } catch (\Exception $e) {
            Log::warning('Erro ao validar número na Whapi: '.$e->getMessage());
        }

        return null;
    }
}
