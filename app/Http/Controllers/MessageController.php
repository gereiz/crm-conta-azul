<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\CompanyMessageSetting;
use App\Models\Invoice;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappNumber;
use App\Services\BillingRestrictionService;
use App\Services\PhoneSanitizerService;
use App\Services\WhatsAppProviderResolver;
use App\Services\HumanizedWhatsAppOrchestrator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MessageController extends Controller
{
    protected $providerResolver;
    protected $orchestrator;

    protected $restrictionService;

    public function __construct(WhatsAppProviderResolver $providerResolver, BillingRestrictionService $restrictionService, HumanizedWhatsAppOrchestrator $orchestrator)
    {
        $this->providerResolver = $providerResolver;
        $this->restrictionService = $restrictionService;
        $this->orchestrator = $orchestrator;
    }

    public function send(Request $request)
    {
        $request->validate([
            'whatsapp_id' => 'required|exists:whatsapp_numbers,id',
            'to' => 'required|string',
            'message' => 'required|string',
            'connection_id' => 'nullable|exists:conta_azul_connections,id',
            'cliente_nome' => 'nullable|string',
            'cliente_ca_id' => 'nullable|string',
            'invoice_ca_id' => 'nullable|string',
            'descricao' => 'nullable|string',
        ]);

        // Validação estrita do número remetente
        $whatsappNumber = WhatsappNumber::find($request->whatsapp_id);
        $provider = $this->providerResolver->resolve($whatsappNumber);
        $connectionStatus = $provider->checkConnection($whatsappNumber);

        if (! $connectionStatus['connected']) {
            // Retorna erro específico para abrir o modal no frontend
            return redirect()->back()->with('whatsapp_error', [
                'type' => 'unavailable',
                'title' => 'Número de envio indisponível',
                'message' => 'O número selecionado não está conectado. Detalhe: '.($connectionStatus['error'] ?? 'Erro desconhecido.'),
                'number_id' => $whatsappNumber->id,
                'available_numbers' => WhatsappNumber::where('status', 'active')->where('id', '!=', $whatsappNumber->id)->get(['id', 'description', 'phone', 'ddi', 'ddd']),
            ]);
        }

        if ($request->filled('connection_id')) {
            $blocked = $this->restrictionService->isBlocked((int) $request->connection_id, [
                'cliente_nome' => $request->input('cliente_nome'),
                'cliente_ca_id' => $request->input('cliente_ca_id'),
                'invoice_ca_id' => $request->input('invoice_ca_id'),
                'descricao' => $request->input('descricao'),
            ]);
            if ($blocked) {
                return redirect()->back()->with('error', 'Envio bloqueado por regra de restrição.');
            }
        }

        // Regra de "já enviado hoje" (manual), se possível identificar cliente e tipo
        $messageType = $request->input('message_type') ?: ($request->input('type') ?: null);
        $clienteId = null;
        if ($request->filled('cliente_ca_id')) {
            $c = Cliente::where('ca_id', $request->input('cliente_ca_id'))->first();
            $clienteId = $c?->id;
        } elseif ($request->filled('invoice_ca_id')) {
            $inv = Invoice::where('ca_id', $request->input('invoice_ca_id'))->first();
            $clienteId = $inv?->cliente_id;
        }
        if ($messageType && $clienteId) {
            $sentToday = \App\Models\WhatsappMessageLog::where('cliente_id', $clienteId)
                ->where('message_type', $messageType)
                ->where('status', 'success')
                ->whereDate('sent_at', \Carbon\Carbon::today())
                ->exists();
            if ($sentToday) {
                return redirect()->back()->with('error', 'Já enviado hoje para este cliente e tipo.');
            }
        }

        // Sanitização do telefone
        $originalPhone = $request->to;
        // Se cliente identificado e marcado como internacional, não sanitiza (mantém DDI digitado)
        $isInternational = $cliente?->is_international ?? false;
        $sanitizedPhone = $isInternational
            ? preg_replace('/\D/', '', $originalPhone)
            : PhoneSanitizerService::sanitize($originalPhone);

        if (! $sanitizedPhone) {
            return redirect()->back()->with('error', 'Número de telefone inválido após sanitização.');
        }

        // Substituição de variáveis e agrupamento (manual)
        $messageContent = $request->message;
        $invoices = collect();
        $cliente = null;

        try {
            // Determinar cliente (por CA ID ou a partir da fatura)
            $clienteCaId = $request->input('cliente_ca_id');
            if (! $clienteCaId && $request->filled('invoice_ca_id')) {
                $inv = Invoice::where('ca_id', $request->input('invoice_ca_id'))->first();
                if ($inv) {
                    $clienteCaId = $inv->cliente_ca_id;
                }
            }

            if ($clienteCaId) {
                $cliente = Cliente::where('ca_id', $clienteCaId)->first();
            }
            // Buscar faturas do cliente para agregação (apenas ATRASADAS e não pagas)
            if ($clienteCaId) {
                $today = Carbon::today()->format('Y-m-d');
                $excludeStatuses = [
                    'PAID', 'PAGO', 'RECEIVED', 'RECEBIDO', 'CONCILIADO',
                    'LIQUIDADO', 'CANCELLED', 'CANCELADO', 'BAIXADO',
                ];
                $query = Invoice::where('cliente_ca_id', $clienteCaId)
                    ->where('saldo_devedor', '>', 0)
                    ->where('data_vencimento', '<', $today)
                    ->whereNotIn('status', $excludeStatuses)
                    ->orderBy('data_vencimento', 'asc');
                if ($request->filled('connection_id')) {
                    $query->where('connection_id', $request->input('connection_id'));
                }
                $invoices = $query->get();
            } elseif ($request->filled('invoice_ca_id')) {
                $today = Carbon::today()->format('Y-m-d');
                $excludeStatuses = [
                    'PAID', 'PAGO', 'RECEIVED', 'RECEBIDO', 'CONCILIADO',
                    'LIQUIDADO', 'CANCELLED', 'CANCELADO', 'BAIXADO',
                ];
                $inv = Invoice::where('ca_id', $request->input('invoice_ca_id'))
                    ->where('saldo_devedor', '>', 0)
                    ->where('data_vencimento', '<', $today)
                    ->whereNotIn('status', $excludeStatuses)
                    ->first();
                if ($inv) {
                    $invoices = collect([$inv]);
                }
            }
            if ($invoices->count() > 0) {
                $dates = $invoices->map(function ($inv) {
                    $date = $inv->data_vencimento instanceof \Carbon\Carbon ? $inv->data_vencimento : Carbon::parse($inv->data_vencimento);

                    return $date->format('d/m/Y');
                })->implode(', ');
                $earliest = $invoices->min(function ($inv) {
                    return $inv->data_vencimento instanceof \Carbon\Carbon ? $inv->data_vencimento : Carbon::parse($inv->data_vencimento);
                });
                $adjustedEarliest = $earliest ? $earliest->copy() : null;
                if ($adjustedEarliest) {
                    if ($adjustedEarliest->isSaturday()) {
                        $adjustedEarliest->addDays(2);
                    }
                    if ($adjustedEarliest->isSunday()) {
                        $adjustedEarliest->addDays(1);
                    }
                }
                $totalValue = $invoices->sum(function ($inv) {
                    return (float) ($inv->saldo_devedor ?? $inv->valor_original ?? 0);
                });
                $firstUrl = $invoices->firstWhere('link_boleto', '!=', null);
                $allUrls = $invoices->pluck('link_boleto')->filter()->implode("\n");
                $pairs = $invoices->map(function ($inv) {
                    $date = $inv->data_vencimento instanceof \Carbon\Carbon ? $inv->data_vencimento : Carbon::parse($inv->data_vencimento);
                    $due = $date->format('d/m/Y');
                    $url = trim($inv->link_boleto ?? '');
                    $display = $url !== '' ? "\n{$url}" : 'boleto não disponível';

                    return $due.' - '.$display;
                })->implode("\n");

                $totalOpenValue = 0;
                $totalOpenQuantity = 0;

                // Se houver cliente, busca todas as faturas em aberto (vencidas ou não) para calcular o total geral
                if ($cliente) {
                    $allOpenInvoices = Invoice::where('cliente_id', $cliente->id)
                        ->where(function ($q) {
                            $q->where('status', 'OPEN')->orWhereNull('status');
                        })
                        ->where(function ($q) {
                            $q->where('saldo_devedor', '>', 0)->orWhereNull('saldo_devedor');
                        })
                        ->get();

                    $totalOpenQuantity = $allOpenInvoices->count();
                    $totalOpenValue = $allOpenInvoices->sum(function ($inv) {
                        return (float) ($inv->saldo_devedor ?? $inv->valor_original ?? 0);
                    });
                } else {
                    // Fallback se não tiver cliente identificado (apenas as faturas do contexto atual)
                    $totalOpenQuantity = $invoices->count();
                    $totalOpenValue = $totalValue;
                }

                $replacements = [
                    '@@clientName@@' => $cliente?->name ?? ($request->input('cliente_nome') ?? 'Cliente'),
                    '@@clientCompany@@' => $cliente?->company_name ?? '',
                    '@@clientEmail@@' => $cliente?->email ?? '',
                    '@@invoicePastDueQuantity@@' => (string) $invoices->count(),
                    '@@invoicePastDueDates@@' => $dates,
                    '@@invoiceTotalValue@@' => number_format($totalValue, 2, ',', '.'),
                    '@@invoiceBoletoUrl@@' => $firstUrl ? ($firstUrl->link_boleto ?? '') : '',
                    '@@invoiceBoletoUrls@@' => $allUrls,
                    '@@invoicePastDuePairs@@' => $pairs,
                    '@@invoiceDueDate@@' => $adjustedEarliest ? $adjustedEarliest->format('d/m/Y') : '',
                    '@@invoiceStrictDueDate@@' => $earliest ? $earliest->format('d/m/Y') : '',
                    '@@invoiceOpenValue@@' => number_format($totalValue, 2, ',', '.'),
                    '@@invoiceLateDays@@' => $earliest ? Carbon::now()->diffInDays($earliest) : 0,
                    '@@invoiceTotalOpenQuantity@@' => (string) $totalOpenQuantity,
                    '@@invoiceTotalOpenValue@@' => number_format($totalOpenValue, 2, ',', '.'),
                ];
                foreach ($replacements as $key => $value) {
                    $messageContent = str_replace($key, (string) $value, $messageContent);
                }
            }
        } catch (\Throwable $e) {
            // Silencioso: em caso de erro nas substituições, segue com o conteúdo original
        }

        // Sanitização e controle de preview (manual)
        $messageContent = $this->sanitizeLinks($messageContent);
        $connId = $request->input('connection_id');
        if ($this->shouldDisablePreview($connId)) {
            $messageContent = $this->disablePreviewLinks($messageContent);
        } elseif ($this->shouldLimitPreview($connId)) {
            $messageContent = $this->limitPreviewLinks($messageContent);
        }

        $result = $this->orchestrator->sendOne(
            (int) $request->whatsapp_id,
            $sanitizedPhone,
            $messageContent,
            ['batch_id' => (string) \Illuminate\Support\Str::uuid()]
        );

        $logContent = $messageContent;
        if (($result['success'] ?? false) && isset($result['meta'])) {
            $st = $result['meta']['whapi_status'] ?? ($result['meta']['evolution_status'] ?? null);
            $mid = $result['meta']['message_id'] ?? null;
            if ($st || $mid) {
                $providerName = $result['meta']['provider'] ?? ($whatsappNumber->provider ?? 'whapi');
                $logContent .= "\n[provider={$providerName}; delivery={$st}; id={$mid}]";
            }
        }

        // Registrar Log Detalhado
        WhatsappMessageLog::create([
            'whatsapp_number_id' => $whatsappNumber->id,
            'connection_id' => $connId,
            'user_id' => Auth::id(),
            'cliente_id' => $cliente?->id,
            'client_name' => $cliente?->name ?? $request->input('cliente_nome') ?? 'Manual',
            'phone_original' => $originalPhone,
            'phone_sanitized' => $sanitizedPhone,
            'message_type' => 'manual',
            'provider' => $whatsappNumber->provider ?? null,
            'total_boletos' => $invoices->count(),
            'boleto_ids' => $invoices->pluck('id')->toArray(),
            'status' => ($result['queued'] ?? false) ? 'skipped' : ($result['success'] ? 'success' : 'error'),
            'error_message' => ($result['queued'] ?? false) ? ($result['message'] ?? 'Enfileirado') : ($result['success'] ? null : ($result['message'] ?? 'Erro desconhecido')),
            'content' => $logContent,
            'batch_id' => $result['meta']['batch_id'] ?? null,
            'provider_message_id' => $result['meta']['message_id'] ?? null,
            'delivery_status' => isset($result['meta']) ? $this->normalizeStatus($result['meta']['whapi_status'] ?? ($result['meta']['evolution_status'] ?? null)) : null,
            'delivery_status_updated_at' => isset($result['meta']) && (($result['meta']['whapi_status'] ?? null) || ($result['meta']['evolution_status'] ?? null)) ? now() : null,
            'sent_at' => now(),
        ]);

        if (($result['queued'] ?? false)) {
            return redirect()->back()->with('success', $result['message'] ?? 'Mensagem enfileirada para envio em janela segura.');
        } elseif ($result['success']) {
            return redirect()->back()->with('success', 'Mensagem enviada com sucesso!');
        } else {
            return redirect()->back()->with('error', $result['message']);
        }
    }

    protected function sanitizeLinks(string $text): string
    {
        $text = str_replace('`', '', $text);
        // Remove pontuação final comum após URLs
        $text = preg_replace('/(https?:\/\/\S+?)[\)\]\.,;\'"](?=\s|$)/i', '$1', $text);
        // Envolve qualquer URL com quebra de linha antes e depois
        $text = preg_replace('/\s*(https?:\/\/\S+)\s*/i', "\n$1\n", $text);
        // Compacta múltiplas quebras de linha
        $text = preg_replace("/\n{2,}/", "\n", $text);

        return trim($text);
    }

    protected function shouldLimitPreview(?int $connectionId): bool
    {
        if (! $connectionId) {
            return false;
        }

        return (bool) CompanyMessageSetting::where('conta_azul_connection_id', $connectionId)
            ->where('message_type', 'limit_link_preview')
            ->value('is_enabled');
    }

    protected function shouldDisablePreview(?int $connectionId): bool
    {
        if (! $connectionId) {
            return false;
        }

        return (bool) CompanyMessageSetting::where('conta_azul_connection_id', $connectionId)
            ->where('message_type', 'disable_link_preview')
            ->value('is_enabled');
    }

    protected function limitPreviewLinks(string $text): string
    {
        return preg_replace('/\bhxxps:\/\//i', 'https://', $text);
    }

    protected function disablePreviewLinks(string $text): string
    {
        return preg_replace('/\bhxxps:\/\//i', 'https://', $text);
    }

    protected function normalizeStatus(?string $status): ?string
    {
        if (! $status) return null;
        $s = strtolower($status);
        return match ($s) {
            'pending', 'queueing', 'queued', 'submit', 'submitted' => 'PENDING',
            'sent' => 'SENT',
            'delivered' => 'DELIVERED',
            'read', 'seen' => 'READ',
            'failed', 'fail' => 'FAILED',
            'error' => 'ERROR',
            default => strtoupper($s),
        };
    }
}
