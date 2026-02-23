<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\MessageStatusLog;
use App\Models\WhatsappMessageLog;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    protected function normalizePhone(?string $raw): ?string
    {
        if (! $raw) return null;
        return \App\Services\PhoneSanitizerService::sanitize($raw);
    }

    protected function normalizeStatus(?string $status): ?string
    {
        if (! $status) return null;
        $s = strtolower($status);
        return match ($s) {
            'pending', 'queueing', 'queued' => 'PENDING',
            'sent', 'submitted' => 'SENT',
            'delivered' => 'DELIVERED',
            'read', 'seen' => 'READ',
            'failed', 'fail' => 'FAILED',
            'error' => 'ERROR',
            default => strtoupper($s),
        };
    }

    public function whatsappStatus(Request $request)
    {
        $provider = strtolower((string) $request->input('provider', $request->header('X-Provider') ?? 'whapi'));
        $settings = \App\Models\SystemSetting::latest()->first();
        $expectedSecret = null;
        if ($provider === 'whapi' && ($settings?->whapi_webhook_enabled ?? false)) {
            $expectedSecret = $settings->whapi_webhook_secret;
        }
        if ($provider === 'evolution' && ($settings?->evolution_webhook_enabled ?? false)) {
            $expectedSecret = $settings->evolution_webhook_secret;
        }
        $incomingSecret = (string) ($request->header('X-Webhook-Secret') ?? $request->input('secret') ?? '');
        if ($expectedSecret) {
            if (! hash_equals($expectedSecret, $incomingSecret)) {
                return response()->json(['success' => false, 'error' => 'Invalid webhook secret'], 401);
            }
        }
        $payload = $request->all();

        $type = strtolower((string) ($payload['type'] ?? $payload['event'] ?? 'status'));
        $messageId = (string) ($payload['message_id'] ?? $payload['id'] ?? ($payload['message']['id'] ?? ''));
        $statusOriginal = (string) ($payload['status'] ?? ($payload['message']['status'] ?? ''));
        $statusNorm = $this->normalizeStatus($statusOriginal);
        $to = $this->normalizePhone($payload['to'] ?? ($payload['message']['to'] ?? ($payload['destination'] ?? null)));
        $from = $this->normalizePhone($payload['from'] ?? ($payload['message']['from'] ?? ($payload['origin'] ?? null)));

        // Whapi: incoming messages are delivered with { event: { type: 'messages', event: 'post' }, messages: [...] }
        $eventType = strtolower((string) ($payload['event']['type'] ?? ''));
        $eventAction = strtolower((string) ($payload['event']['event'] ?? ''));
        $isWhapiIncoming = ($eventType === 'messages' && in_array($eventAction, ['post', 'put'], true) && is_array($payload['messages'] ?? null));

        if ($type === 'incoming' || $isWhapiIncoming) {
            if ($isWhapiIncoming) {
                foreach (($payload['messages'] ?? []) as $msg) {
                    $fromMe = (bool) ($msg['from_me'] ?? $msg['fromMe'] ?? false);
                    if ($fromMe) {
                        continue;
                    }
                    $mId = (string) ($msg['id'] ?? '');
                    $mFrom = $this->normalizePhone($msg['from'] ?? null) ?: (function ($chatId) {
                        if (! is_string($chatId)) {
                            return null;
                        }
                        // Extract numeric from chat_id like "5511999999999@c.us"
                        $num = preg_replace('/\D+/', '', $chatId);
                        return $num ?: null;
                    })($msg['chat_id'] ?? null);

                    \DB::table('incoming_messages')->insert([
                        'numero_origem' => $mFrom,
                        'numero_destino' => null,
                        'provider' => $provider,
                        'provider_message_id' => $mId ?: null,
                        'payload_json' => json_encode($msg),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    if ($mFrom) {
                        $lastSent = WhatsappMessageLog::where('phone_sanitized', $mFrom)
                            ->orderByDesc('sent_at')
                            ->first();
                        if ($lastSent) {
                            $lastSent->responded = true;
                            $lastSent->responded_at = now();
                            $lastSent->save();
                        }
                    }
                }

                return response()->json(['success' => true]);
            }

            \DB::table('incoming_messages')->insert([
                'numero_origem' => $from,
                'numero_destino' => $to,
                'provider' => $provider,
                'provider_message_id' => $messageId ?: null,
                'payload_json' => json_encode($payload),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($from) {
                $lastSent = WhatsappMessageLog::where('phone_sanitized', $from)
                    ->orderByDesc('sent_at')
                    ->first();
                if ($lastSent) {
                    $lastSent->responded = true;
                    $lastSent->responded_at = now();
                    $lastSent->save();
                }
            }

            return response()->json(['success' => true]);
        }

        $log = null;
        if ($messageId) {
            $log = WhatsappMessageLog::where('provider_message_id', $messageId)->first();
        }
        if (! $log && $to) {
            $log = WhatsappMessageLog::where('phone_sanitized', $to)
                ->orderByDesc('sent_at')->first();
        }
        if ($log) {
            MessageStatusLog::create([
                'whatsapp_message_log_id' => $log->id,
                'provider' => $provider,
                'status_original' => $statusOriginal ?: null,
                'status_normalizado' => $statusNorm,
                'payload_json' => $payload,
            ]);
            if ($statusNorm) {
                $log->delivery_status = $statusNorm;
                $log->delivery_status_updated_at = now();
                $log->save();
            }
        }

        return response()->json(['success' => true]);
    }
}
