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
        $incomingSecret = (string) (
            $request->header('X-Webhook-Secret')
            ?? $request->header('X-Callback-Token')
            ?? $request->route('secret')
            ?? $request->query('secret')
            ?? $request->input('secret')
            ?? ''
        );
        $evt = $request->input('event');
        $eventTypeStr = is_array($evt) ? (($evt['type'] ?? '').'.'.($evt['event'] ?? '')) : (string) ($evt ?? '');
        if ($expectedSecret) {
            if (! hash_equals($expectedSecret, $incomingSecret)) {
                $raw = $request->all();
                $candidate = null;
                try {
                    if (isset($raw['message']['chat_id'])) {
                        $candidate = preg_replace('/\D+/', '', (string) $raw['message']['chat_id']);
                    } elseif (isset($raw['messages'][0]['chat_id'])) {
                        $candidate = preg_replace('/\D+/', '', (string) $raw['messages'][0]['chat_id']);
                    } elseif (isset($raw['messages'][0]['from'])) {
                        $candidate = preg_replace('/\D+/', '', (string) $raw['messages'][0]['from']);
                    } elseif (isset($raw['from'])) {
                        $candidate = preg_replace('/\D+/', '', (string) $raw['from']);
                    } elseif (isset($raw['to'])) {
                        $candidate = preg_replace('/\D+/', '', (string) $raw['to']);
                    }
                } catch (\Throwable $e) {
                    $candidate = null;
                }
                \App\Models\WebhookEventLog::create([
                    'provider' => $provider,
                    'event_type' => $eventTypeStr,
                    'from_me' => false,
                    'phone' => $candidate,
                    'reason' => 'secret_invalido',
                    'payload_json' => $raw,
                ]);
                return response()->json(['success' => false, 'error' => 'Invalid webhook secret'], 401);
            }
        }
        $payload = $request->all();

        // 'event' pode ser um array (Whapi). Evita conversão de array para string.
        $type = strtolower((string) ($payload['type'] ?? ($payload['event']['type'] ?? 'status')));
        $messageId = (string) ($payload['message_id'] ?? $payload['id'] ?? ($payload['message']['id'] ?? ''));
        $statusOriginal = (string) ($payload['status'] ?? ($payload['message']['status'] ?? ''));
        $statusNorm = $this->normalizeStatus($statusOriginal);
        $to = $this->normalizePhone($payload['to'] ?? ($payload['message']['to'] ?? ($payload['destination'] ?? null)));
        $from = $this->normalizePhone($payload['from'] ?? ($payload['message']['from'] ?? ($payload['origin'] ?? null)));

        // Whapi: incoming messages are delivered with { event: { type: 'messages', event: 'post' }, messages: [...] }
        $eventType = strtolower((string) ($payload['event']['type'] ?? ''));
        $eventAction = strtolower((string) ($payload['event']['event'] ?? ''));
        $isWhapiIncoming = ($eventType === 'messages' && in_array($eventAction, ['post', 'put'], true) && isset($payload['messages'])) || isset($payload['message']);
        $isWhapiStatuses = ($eventType === 'statuses' && in_array($eventAction, ['post', 'put'], true) && isset($payload['messages'])) || isset($payload['statuses']);

        if ($type === 'incoming' || $isWhapiIncoming || $isWhapiStatuses) {
            if ($isWhapiIncoming) {
                $incomingMsgs = $payload['messages'] ?? ($payload['message'] ?? []);
                if (! is_array($incomingMsgs)) {
                    $incomingMsgs = [$incomingMsgs];
                }
                foreach ($incomingMsgs as $msg) {
                    $fromMe = (bool) ($msg['from_me'] ?? $msg['fromMe'] ?? false);
                    $mId = (string) ($msg['id'] ?? '');
                    $chatIdRaw = $msg['chat_id'] ?? null;
                    $toRaw = (function ($chatId) {
                        if (! is_string($chatId)) return null;
                        $num = preg_replace('/\D+/', '', $chatId);
                        return $num ?: null;
                    })($chatIdRaw);
                    $toSanitized = $this->normalizePhone($toRaw);
                    $mFromRaw = $msg['from'] ?? null;
                    $mFrom = $this->normalizePhone(is_string($mFromRaw) ? $mFromRaw : null);

                    // Se for mensagem nossa (from_me=true) e houver status, atualiza delivery_status
                    if ($fromMe && isset($msg['status'])) {
                        $statusNormMsg = $this->normalizeStatus($msg['status']);
                        $target = null;
                        if (!empty($msg['message_id'])) {
                            $target = WhatsappMessageLog::where('provider_message_id', $msg['message_id'])->first();
                        }
                        if (!$target && $toSanitized) {
                            $target = WhatsappMessageLog::where('phone_sanitized', $toSanitized)->orderByDesc('sent_at')->first();
                        }
                        if ($target && $statusNormMsg) {
                            $target->delivery_status = $statusNormMsg;
                            $target->delivery_status_updated_at = isset($msg['timestamp']) ? \Carbon\Carbon::createFromTimestamp((int) $msg['timestamp']) : now();
                            $target->save();
                            \App\Models\WebhookEventLog::create([
                                'provider' => $provider,
                                'event_type' => 'messages.post',
                                'from_me' => true,
                                'phone' => $toSanitized,
                                'chat_id' => $chatIdRaw,
                                'provider_message_id' => $mId ?: null,
                                'matched_log_id' => $target->id,
                                'reason' => 'status_atualizado:'.$statusNormMsg,
                                'payload_json' => $msg,
                            ]);
                        } else {
                            \App\Models\WebhookEventLog::create([
                                'provider' => $provider,
                                'event_type' => 'messages.post',
                                'from_me' => true,
                                'phone' => $toSanitized,
                                'chat_id' => $chatIdRaw,
                                'provider_message_id' => $mId ?: null,
                                'reason' => 'sem_match_para_status',
                                'payload_json' => $msg,
                            ]);
                        }
                        continue;
                    }

                    // Mensagem recebida do cliente (from_me=false) => marca respondida
                    \DB::table('incoming_messages')->insert([
                        'numero_origem' => $mFrom ?: $toSanitized,
                        'numero_destino' => $toSanitized,
                        'provider' => $provider,
                        'provider_message_id' => $mId ?: null,
                        'payload_json' => json_encode($msg),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $matchPhone = $mFrom ?: $toSanitized;
                    if ($matchPhone) {
                        $ts = isset($msg['timestamp']) ? \Carbon\Carbon::createFromTimestamp((int) $msg['timestamp']) : now();
                        $lastSent = WhatsappMessageLog::where('phone_sanitized', $matchPhone)->orderByDesc('sent_at')->first();
                        if (! $lastSent) {
                            $lastSent = WhatsappMessageLog::whereRaw('REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone_original,""), " ", ""), "-", ""), "(", ""), ")", ""), ".", ""), "+", "") = ?', [$matchPhone])
                                ->orderByDesc('sent_at')
                                ->first();
                        }
                        if ($lastSent) {
                            $lastSent->responded = true;
                            $lastSent->responded_at = $ts;
                            $lastSent->save();
                            \App\Models\WebhookEventLog::create([
                                'provider' => $provider,
                                'event_type' => 'messages.post',
                                'from_me' => false,
                                'phone' => $matchPhone,
                                'chat_id' => $chatIdRaw,
                                'provider_message_id' => $mId ?: null,
                                'matched_log_id' => $lastSent->id,
                                'reason' => 'respondida_ok',
                                'payload_json' => $msg,
                            ]);
                        } else {
                            // Atualiza o mais recente do dia para esse telefone
                            $updated = WhatsappMessageLog::where('phone_sanitized', $matchPhone)
                                ->whereDate('sent_at', $ts->toDateString())
                                ->orderByDesc('sent_at')
                                ->limit(1)
                                ->update(['responded' => true, 'responded_at' => $ts]);
                            \App\Models\WebhookEventLog::create([
                                'provider' => $provider,
                                'event_type' => 'messages.post',
                                'from_me' => false,
                                'phone' => $matchPhone,
                                'chat_id' => $chatIdRaw,
                                'provider_message_id' => $mId ?: null,
                                'reason' => $updated ? 'respondida_fallback_dia' : 'sem_match_para_resposta',
                                'payload_json' => $msg,
                            ]);
                        }
                    } else {
                        \App\Models\WebhookEventLog::create([
                            'provider' => $provider,
                            'event_type' => 'messages.post',
                            'from_me' => false,
                            'phone' => null,
                            'chat_id' => $chatIdRaw,
                            'provider_message_id' => $mId ?: null,
                            'reason' => 'telefone_invalido_ou_ausente',
                            'payload_json' => $msg,
                        ]);
                    }
                }

                return response()->json(['success' => true]);
            } elseif ($isWhapiStatuses) {
                $statusMsgs = $payload['messages'] ?? ($payload['statuses'] ?? []);
                if (! is_array($statusMsgs)) {
                    $statusMsgs = [$statusMsgs];
                }
                foreach ($statusMsgs as $msg) {
                    $fromMeRaw = $msg['from_me'] ?? $msg['fromMe'] ?? null;
                    $fromMe = is_null($fromMeRaw) ? true : (bool) $fromMeRaw;
                    if (! $fromMe) {
                        // Ignora status de mensagens que não são deste remetente
                        continue;
                    }
                    $mId = (string) ($msg['id'] ?? ($msg['message_id'] ?? ($msg['message']['id'] ?? '')));
                    $chatIdRaw = $msg['chat_id'] ?? null;
                    $toRaw = (function ($chatId) {
                        if (! is_string($chatId)) return null;
                        $num = preg_replace('/\D+/', '', $chatId);
                        return $num ?: null;
                    })($chatIdRaw);
                    $toSanitized = $this->normalizePhone($toRaw);
                    $statusNormMsg = $this->normalizeStatus($msg['status'] ?? ($msg['message']['status'] ?? null));
                    if (! $statusNormMsg) {
                        continue;
                    }
                    $target = null;
                    if (! empty($mId)) {
                        $target = WhatsappMessageLog::where('provider_message_id', $mId)->first();
                    }
                    if (! $target && $toSanitized) {
                        $target = WhatsappMessageLog::where('phone_sanitized', $toSanitized)->orderByDesc('sent_at')->first();
                    }
                    if ($target) {
                        $target->delivery_status = $statusNormMsg;
                        $target->delivery_status_updated_at = isset($msg['timestamp']) ? \Carbon\Carbon::createFromTimestamp((int) $msg['timestamp']) : now();
                        $target->save();
                        \App\Models\WebhookEventLog::create([
                            'provider' => $provider,
                            'event_type' => 'statuses.post',
                            'from_me' => true,
                            'phone' => $toSanitized,
                            'chat_id' => $chatIdRaw,
                            'provider_message_id' => $mId ?: null,
                            'matched_log_id' => $target->id,
                            'reason' => 'status_atualizado:'.$statusNormMsg,
                            'payload_json' => $msg,
                        ]);
                    } else {
                        \App\Models\WebhookEventLog::create([
                            'provider' => $provider,
                            'event_type' => 'statuses.post',
                            'from_me' => true,
                            'phone' => $toSanitized,
                            'chat_id' => $chatIdRaw,
                            'provider_message_id' => $mId ?: null,
                            'reason' => 'sem_match_para_status',
                            'payload_json' => $msg,
                        ]);
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
            \App\Models\WebhookEventLog::create([
                'provider' => $provider,
                'event_type' => $eventTypeStr ?: 'status',
                'from_me' => true,
                'phone' => $to,
                'chat_id' => null,
                'provider_message_id' => $messageId ?: null,
                'matched_log_id' => $log->id,
                'reason' => $statusNorm ? ('status_atualizado:'.$statusNorm) : 'status_event_sem_normalizar',
                'payload_json' => $payload,
            ]);
        } else {
            \App\Models\WebhookEventLog::create([
                'provider' => $provider,
                'event_type' => $eventTypeStr ?: 'status',
                'from_me' => true,
                'phone' => $to,
                'chat_id' => null,
                'provider_message_id' => $messageId ?: null,
                'reason' => 'sem_match_para_status_event',
                'payload_json' => $payload,
            ]);
        }

        return response()->json(['success' => true]);
    }
}
