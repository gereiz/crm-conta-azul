<?php

namespace App\Services;

use App\Models\WhatsappNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhapiService
{
    protected $baseUrl = 'https://gate.whapi.cloud';

    public function sendMessage($whatsappId, $to, $message)
    {
        $whatsapp = WhatsappNumber::find($whatsappId);

        if (!$whatsapp || $whatsapp->status !== 'active') {
            Log::error("Tentativa de envio com WhatsApp inválido ou inativo. ID: {$whatsappId}");
            return ['success' => false, 'message' => 'WhatsApp não configurado ou inativo.'];
        }

        // Formatar número para padrão internacional (apenas números)
        $to = preg_replace('/\D/', '', $to);

        $endpoint = "{$this->baseUrl}/messages/text";
        
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$whatsapp->whapi_key}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post($endpoint, [
            'to' => $to,
            'body' => $message,
            'typing_time' => 0,
        ]);

        if ($response->successful()) {
            return ['success' => true, 'data' => $response->json()];
        } else {
            $errorBody = $response->json();
            $errorMessage = $errorBody['error']['message'] ?? $response->body();

            Log::error("Erro Whapi: " . $response->body());

            if ($response->status() === 401 && str_contains($errorMessage, 'need channel authorization')) {
                return ['success' => false, 'message' => 'WhatsApp desconectado. Necessário ler o QR Code no painel da Whapi.'];
            }

            return ['success' => false, 'message' => 'Erro ao enviar mensagem via Whapi: ' . $errorMessage];
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
            Log::error("Erro ao verificar saúde Whapi: " . $e->getMessage());
            return ['status' => 'down', 'error' => $e->getMessage()];
        }
    }
}
