<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\CompanyMessageSetting;
use App\Models\Invoice;
use App\Models\MessageCron;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappNumber;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MessageCronService
{
    protected $providerResolver;

    protected $restrictionService;

    protected int $batchSize = 30;

    protected int $batchDelaySeconds = 300;

    public function __construct(WhatsAppProviderResolver $providerResolver, BillingRestrictionService $restrictionService)
    {
        $this->providerResolver = $providerResolver;
        $this->restrictionService = $restrictionService;
    }

    public function processCron(MessageCron $cron, bool $forceRun = false)
    {
        Log::info("Processando cron: {$cron->name} (ID: {$cron->id}, Tipo: {$cron->type})");

        $batchId = (string) Str::uuid();
        $stats = [
            'sent' => 0,
            'errors' => 0,
            'skipped' => 0,
            'total' => 0,
        ];

        try {
            // Verifica se deve rodar HOJE com base nas regras do próprio CRON
            if (! $forceRun && ! $this->shouldRunToday($cron)) {
                Log::info("Cron {$cron->id} ignorado: Regras de agendamento não satisfeitas para hoje.");
                $stats['skipped'] = 0;

                return $stats;
            }

            if (! empty($cron->connection_id)) {
                $typeEnabled = CompanyMessageSetting::where('conta_azul_connection_id', $cron->connection_id)
                    ->where('message_type', $cron->type)
                    ->value('is_enabled');
                if (! $typeEnabled) {
                    Log::info("Cron {$cron->id} ignorado: tipo {$cron->type} desabilitado para conexão {$cron->connection_id}.");
                    $stats['skipped'] = 0;

                    return $stats;
                }
            }

            switch ($cron->type) {
                case 'billing':
                    $stats = $this->processBilling($cron, $batchId);
                    break;
                case 'due_date':
                    $stats = $this->processDueDate($cron, $batchId);
                    break;
                case 'boleto':
                    $stats = $this->processBoleto($cron, $batchId);
                    break;
                case 'birthday':
                    $stats = $this->processBirthday($cron, $batchId);
                    break;
            }

            $cron->update(['last_run_at' => now()]);

            return $stats;

        } catch (\Exception $e) {
            Log::error("Erro ao processar cron {$cron->id}: ".$e->getMessage());

            return $stats;
        }
    }

    protected function shouldRunToday(MessageCron $cron): bool
    {
        $now = Carbon::now();

        // 1. Verificar Fim de Semana
        if ($cron->exclude_weekends && ($now->isSaturday() || $now->isSunday())) {
            return false;
        }

        // 2. Verificar Tipo de Regra
        switch ($cron->rule_type) {
            case 'daily':
                return true;

            case 'monthly_day':
                if (empty($cron->day_of_month)) {
                    return false;
                }
                // Suporte a array JSON ou valor único antigo (fallback)
                $days = is_array($cron->day_of_month) ? $cron->day_of_month : [(int) $cron->day_of_month];

                return in_array($now->day, $days);

            case 'weekly_day':
                if ($cron->day_of_week === null) {
                    return false;
                }
                $days = is_array($cron->day_of_week) ? $cron->day_of_week : [(int) $cron->day_of_week];

                return in_array($now->dayOfWeek, $days);

            case 'interval_days':
                if (! $cron->last_run_at) {
                    return true;
                } // Nunca rodou, roda hoje
                $daysSinceLastRun = $now->diffInDays($cron->last_run_at->startOfDay());

                return $daysSinceLastRun >= $cron->interval_days;

            default:
                return true; // Se não tiver regra definida, assume diário (comportamento padrão antigo)
        }
    }

    protected function checkCronNumberStatus(MessageCron $cron)
    {
        if (! $cron->whatsapp_number_id) {
            return false;
        }
        $whatsapp = WhatsappNumber::find($cron->whatsapp_number_id);

        $provider = $this->providerResolver->resolve($whatsapp);
        $status = $provider->checkConnection($whatsapp);

        return (bool) ($status['connected'] ?? false);
    }

    protected function applyPerNumberBatchGate(MessageCron $cron, int $total)
    {
        if (! $cron->whatsapp_number_id) {
            return;
        }
        $batches = (int) ceil(max(0, $total) / $this->batchSize);
        $busyKey = 'whatsapp_busy_until_'.$cron->whatsapp_number_id;
        $now = Carbon::now();
        $busyUntil = Cache::get($busyKey);
        if ($busyUntil) {
            try {
                $ts = Carbon::parse($busyUntil);
                if ($ts->gt($now)) {
                    $wait = $ts->diffInSeconds($now);
                    if ($wait > 0) {
                        sleep($wait);
                    }
                }
            } catch (\Exception $e) {
            }
        }
        $extra = max(0, $batches - 1) * $this->batchDelaySeconds;
        if ($extra > 0) {
            $newUntil = $now->copy()->addSeconds($extra);
            Cache::put($busyKey, $newUntil->toIso8601String(), $extra + 600);
        }
    }

    protected function processBilling(MessageCron $cron, string $batchId)
    {
        $daysLate = (int) ($cron->days_after_due ?? 0);
        // Semântica: "maior que X dias de atraso" => vencimento <= hoje - (X + 1) dias
        // Ex.: X=0 => ontem (>=1 dia); X=1 => anteontem (>=2 dias)
        $strictThresholdDays = $daysLate + 1;
        $dueDateLimit = Carbon::now()->subDays($strictThresholdDays)->format('Y-m-d');
        $periodStart = $this->getPeriodStartDate($cron);

        $query = Invoice::where('data_vencimento', '<=', $dueDateLimit);

        if (! empty($cron->connection_id)) {
            $query->where('connection_id', $cron->connection_id);
        }

        if ($periodStart) {
            $query->where('data_vencimento', '>=', $periodStart);
        }
        $query->where(function ($q) {
            $q->whereNull('saldo_devedor')->orWhere('saldo_devedor', '>', 0);
        });
        // Preferir boletos: aceita quando payment_type contém 'BOLETO' OU quando há link_boleto presente
        $query->where(function ($q) {
            $q->where(function ($qq) {
                $qq->whereNotNull('payment_type')
                    ->where('payment_type', 'LIKE', '%BOLETO%');
            })->orWhere(function ($qq) {
                $qq->whereNotNull('link_boleto')->where('link_boleto', '!=', '');
            });
        });

        $invoices = $query->with('cliente')->get();

        // Agrupar por cliente para envio único
        $groups = $invoices->filter(fn ($inv) => $inv->cliente_id && $inv->cliente)->groupBy('cliente_id');

        Log::info('Cron billing - seleção e agrupamento', [
            'cron_id' => $cron->id,
            'connection_id' => $cron->connection_id,
            'days_after_due' => $daysLate,
            'period_start' => $periodStart,
            'invoices_count' => $invoices->count(),
            'groups_count' => $groups->count(),
        ]);

        $stats = ['sent' => 0, 'errors' => 0, 'skipped' => 0, 'total' => $groups->count()];

        $this->applyPerNumberBatchGate($cron, $groups->count());
        // Valida conexão uma vez antes do loop
        $isNumberActive = $this->checkCronNumberStatus($cron);

        $chunks = $groups->chunk($this->batchSize);
        foreach ($chunks as $chunkIndex => $chunk) {
            foreach ($chunk as $clienteId => $clientInvoices) {
                $cliente = $clientInvoices->first()->cliente;
                if (! $isNumberActive) {
                    $phone = $cliente->mobile_phone ?? $cliente->phone;
                    $this->logError($cron, $cliente, $phone, 'Número de envio desconectado/inativo (Cron abortado).', count($clientInvoices));
                    $stats['errors']++;

                    continue;
                }
                $result = $this->sendGroupedMessageForClient($cron, $cliente, $clientInvoices, $batchId);
                if ($result === 'sent') {
                    $stats['sent']++;
                } elseif ($result === 'error') {
                    $stats['errors']++;
                } else {
                    $stats['skipped']++;
                }
            }
            if ($chunkIndex < ($chunks->count() - 1)) {
                sleep($this->batchDelaySeconds);
            }
        }

        return $stats;
    }

    protected function processDueDate(MessageCron $cron, string $batchId)
    {
        $daysBefore = $cron->days_before_due ?? 0;
        $targetDate = Carbon::now()->addDays($daysBefore)->format('Y-m-d');
        $today = Carbon::today()->format('Y-m-d');

        $query = Invoice::whereDate('data_vencimento', $targetDate)
            ->where(function ($q) {
                $q->whereIn('status', ['PENDING', 'ABERTO'])
                    ->orWhereNull('status');
            })
            ->where(function ($q) {
                $q->whereNull('saldo_devedor')->orWhere('saldo_devedor', '>', 0);
            });

        if (! empty($cron->connection_id)) {
            $query->where('connection_id', $cron->connection_id);
        }

        $invoices = $query->with('cliente')->get();

        Log::info('Cron due_date - seleção de faturas', [
            'cron_id' => $cron->id,
            'connection_id' => $cron->connection_id,
            'days_before' => $daysBefore,
            'target_date' => $targetDate,
            'count' => $invoices->count(),
        ]);

        $stats = ['sent' => 0, 'errors' => 0, 'skipped' => 0, 'total' => $invoices->count()];

        $this->applyPerNumberBatchGate($cron, $invoices->count());
        $isNumberActive = $this->checkCronNumberStatus($cron);

        $chunks = $invoices->chunk($this->batchSize);
        foreach ($chunks as $chunkIndex => $chunk) {
            foreach ($chunk as $invoice) {
                if (! $isNumberActive) {
                    $phone = $invoice->cliente->mobile_phone ?? $invoice->cliente->phone;
                    $this->logError($cron, $invoice->cliente, $phone, 'Número de envio desconectado/inativo (Cron abortado).', 1);
                    $stats['errors']++;

                    continue;
                }
                $result = $this->sendMessageForInvoice($cron, $invoice, $batchId);
                if ($result === 'sent') {
                    $stats['sent']++;
                } elseif ($result === 'error') {
                    $stats['errors']++;
                } else {
                    $stats['skipped']++;
                }
            }
            if ($chunkIndex < ($chunks->count() - 1)) {
                sleep($this->batchDelaySeconds);
            }
        }

        return $stats;
    }

    protected function processBoleto(MessageCron $cron, string $batchId)
    {
        $days = (int) ($cron->days_before_due ?? $cron->period_value ?? 0);
        $startDate = Carbon::today()->format('Y-m-d');
        $endDate = Carbon::now()->addDays($days)->format('Y-m-d');

        $query = Invoice::where('status', 'PENDING')
            ->whereDate('data_vencimento', '>=', $startDate)
            ->whereDate('data_vencimento', '<=', $endDate)
            ->whereNotNull('link_boleto')
            ->where('link_boleto', '!=', '');

        if (! empty($cron->connection_id)) {
            $query->where('connection_id', $cron->connection_id);
        }
        $invoices = $query->with('cliente')->get();

        Log::info('Cron boleto - seleção de faturas', [
            'cron_id' => $cron->id,
            'connection_id' => $cron->connection_id,
            'days_before_due' => $days,
            'due_from' => $startDate,
            'due_to' => $endDate,
            'count' => $invoices->count(),
        ]);

        $stats = ['sent' => 0, 'errors' => 0, 'skipped' => 0, 'total' => $invoices->count()];

        $this->applyPerNumberBatchGate($cron, $invoices->count());
        $isNumberActive = $this->checkCronNumberStatus($cron);

        $chunks = $invoices->chunk($this->batchSize);
        foreach ($chunks as $chunkIndex => $chunk) {
            foreach ($chunk as $invoice) {
                if (! $isNumberActive) {
                    $phone = $invoice->cliente->mobile_phone ?? $invoice->cliente->phone;
                    $this->logError($cron, $invoice->cliente, $phone, 'Número de envio desconectado/inativo (Cron abortado).', 1);
                    $stats['errors']++;

                    continue;
                }
                $alreadyEverSent = \App\Models\WhatsappMessageLog::where('message_type', 'boleto')
                    ->whereJsonContains('boleto_ids', $invoice->id)
                    ->exists();
                if ($alreadyEverSent) {
                    \App\Models\WhatsappMessageLog::create([
                        'whatsapp_number_id' => $cron->whatsapp_number_id,
                        'connection_id' => $invoice->connection_id ?? $cron->connection_id,
                        'message_cron_id' => $cron->id,
                        'cliente_id' => $invoice->cliente_id,
                        'client_name' => $invoice->cliente->name ?? $invoice->cliente_nome,
                        'phone_original' => $invoice->cliente->mobile_phone ?? $invoice->cliente->phone,
                        'phone_sanitized' => \App\Services\PhoneSanitizerService::sanitize($invoice->cliente->mobile_phone ?? $invoice->cliente->phone),
                        'message_type' => $cron->type,
                        'provider' => optional($cron->whatsappNumber)->provider,
                        'message_template_id' => $cron->message_template_id,
                        'total_boletos' => 1,
                        'boleto_ids' => [$invoice->id],
                        'status' => 'skipped',
                        'error_message' => 'Já enviado anteriormente',
                        'batch_id' => $batchId,
                        'sent_at' => now(),
                    ]);
                    $stats['skipped']++;

                    continue;
                }
                $result = $this->sendMessageForInvoice($cron, $invoice, $batchId);
                if ($result === 'sent') {
                    $stats['sent']++;
                } elseif ($result === 'error') {
                    $stats['errors']++;
                } else {
                    $stats['skipped']++;
                }
            }
            if ($chunkIndex < ($chunks->count() - 1)) {
                sleep($this->batchDelaySeconds);
            }
        }

        return $stats;
    }

    protected function processBirthday(MessageCron $cron, string $batchId)
    {
        $today = Carbon::now()->format('m-d');

        $query = Cliente::whereRaw("DATE_FORMAT(birthdate, '%m-%d') = ?", [$today]);
        if (! empty($cron->connection_id)) {
            $query->where('connection_id', $cron->connection_id);
        }
        $clients = $query->get();

        $stats = ['sent' => 0, 'errors' => 0, 'skipped' => 0, 'total' => $clients->count()];

        $this->applyPerNumberBatchGate($cron, $clients->count());
        $isNumberActive = $this->checkCronNumberStatus($cron);

        $chunks = $clients->chunk($this->batchSize);
        foreach ($chunks as $chunkIndex => $chunk) {
            foreach ($chunk as $client) {
                if (! $isNumberActive) {
                    $phone = $client->mobile_phone ?? $client->phone;
                    $this->logError($cron, $client, $phone, 'Número de envio desconectado/inativo (Cron abortado).');
                    $stats['errors']++;

                    continue;
                }
                $result = $this->sendMessageForBirthday($cron, $client, $batchId);
                if ($result === 'sent') {
                    $stats['sent']++;
                } elseif ($result === 'error') {
                    $stats['errors']++;
                } else {
                    $stats['skipped']++;
                }
            }
            if ($chunkIndex < ($chunks->count() - 1)) {
                sleep($this->batchDelaySeconds);
            }
        }

        return $stats;
    }

    protected function sendMessageForInvoice(MessageCron $cron, Invoice $invoice, string $batchId)
    {
        if (! $invoice->cliente) {
            return 'skipped';
        }

        $ignoreSentToday = false;
        $connId = $invoice->connection_id ?? $cron->connection_id;
        if ($connId) {
            $ignoreSentToday = (bool) CompanyMessageSetting::where('conta_azul_connection_id', $connId)
                ->where('message_type', 'ignore_sent_today')
                ->value('is_enabled');
        }

        // Sanitização
        $originalPhone = $invoice->cliente->mobile_phone ?? $invoice->cliente->phone;
        $sanitizedPhone = PhoneSanitizerService::sanitize($originalPhone);

        if ($cron->type === 'billing' && ! $ignoreSentToday) {
            $alreadySent = WhatsappMessageLog::where('message_cron_id', $cron->id)
                ->where('cliente_id', $invoice->cliente_id)
                ->whereDate('sent_at', Carbon::today())
                ->exists();
            if ($alreadySent) {
                WhatsappMessageLog::create([
                    'whatsapp_number_id' => $cron->whatsapp_number_id,
                    'connection_id' => $connId,
                    'message_cron_id' => $cron->id,
                    'cliente_id' => $invoice->cliente_id,
                    'client_name' => $invoice->cliente->name,
                    'phone_original' => $originalPhone,
                    'phone_sanitized' => $sanitizedPhone,
                    'message_type' => $cron->type,
                    'provider' => optional($cron->whatsappNumber)->provider,
                    'message_template_id' => $cron->message_template_id,
                    'total_boletos' => 1,
                    'boleto_ids' => [$invoice->id],
                    'status' => 'skipped',
                    'error_message' => 'Já enviado hoje',
                    'batch_id' => $batchId,
                    'sent_at' => now(),
                ]);

                return 'skipped';
            }
        }

        $context = [
            'cliente_nome' => $invoice->cliente->name ?? ($invoice->cliente_nome ?? ''),
            'cliente_ca_id' => $invoice->cliente_ca_id,
            'invoice_ca_id' => $invoice->ca_id,
            'descricao' => $invoice->descricao,
        ];

        // Verifica restrições dinâmicas (Globais e Específicas) cadastradas no banco
        if ($this->restrictionService->isBlocked((int) ($invoice->connection_id ?? $cron->connection_id), $context)) {
            WhatsappMessageLog::create([
                'whatsapp_number_id' => $cron->whatsapp_number_id,
                'connection_id' => $invoice->connection_id,
                'message_cron_id' => $cron->id,
                'cliente_id' => $invoice->cliente_id,
                'client_name' => $invoice->cliente->name,
                'phone_original' => $originalPhone,
                'phone_sanitized' => $sanitizedPhone,
                'message_type' => $cron->type,
                'provider' => optional($cron->whatsappNumber)->provider,
                'message_template_id' => $cron->message_template_id,
                'total_boletos' => 1,
                'boleto_ids' => [$invoice->id],
                'status' => 'skipped',
                'error_message' => 'Bloqueado por regra de restrição',
                'batch_id' => $batchId,
                'sent_at' => now(),
            ]);

            return 'skipped';
        }

        if (! $sanitizedPhone) {
            $this->logError($cron, $invoice->cliente, $originalPhone, 'Cliente sem telefone válido após sanitização.', 1);

            return 'error';
        }

        if (! $cron->messageTemplate) {
            $this->logError($cron, $invoice->cliente, $originalPhone, 'Template de mensagem não encontrado.', 1);

            return 'error';
        }

        // Use the cron's specific WhatsApp number
        if (! $cron->whatsapp_number_id) {
            $this->logError($cron, $invoice->cliente, $originalPhone, 'Cron sem número de WhatsApp vinculado.', 1);

            return 'error';
        }

        $message = $this->replaceVariables($cron->messageTemplate->content, $invoice);
        $message = $this->sanitizeLinks($message);
        if ($this->shouldDisablePreview($cron, $invoice->connection_id ?? $cron->connection_id)) {
            $message = $this->disablePreviewLinks($message);
        } elseif ($this->shouldLimitPreview($cron, $invoice->connection_id ?? $cron->connection_id)) {
            $message = $this->limitPreviewLinks($message);
        }

        $whatsapp = WhatsappNumber::find($cron->whatsapp_number_id);
        $provider = $this->providerResolver->resolve($whatsapp);
        $result = $provider->sendMessage($cron->whatsapp_number_id, $sanitizedPhone, $message);
        $logContent = $message;
        if (($result['success'] ?? false) && isset($result['meta'])) {
            $st = $result['meta']['whapi_status'] ?? ($result['meta']['evolution_status'] ?? null);
            $mid = $result['meta']['message_id'] ?? null;
            if ($st || $mid) {
                $providerName = $result['meta']['provider'] ?? ($whatsapp->provider ?? 'whapi');
                $logContent .= "\n[provider={$providerName}; delivery={$st}; id={$mid}]";
            }
        }

        WhatsappMessageLog::create([
            'whatsapp_number_id' => $cron->whatsapp_number_id,
            'connection_id' => $connId,
            'message_cron_id' => $cron->id,
            'cliente_id' => $invoice->cliente_id,
            'client_name' => $invoice->cliente->name,
            'phone_original' => $originalPhone,
            'phone_sanitized' => $sanitizedPhone,
            'message_type' => $cron->type,
            'provider' => $whatsapp->provider ?? null,
            'message_template_id' => $cron->message_template_id,
            'total_boletos' => 1,
            'boleto_ids' => [$invoice->id],
            'status' => $result['success'] ? 'success' : 'error',
            'error_message' => $result['success'] ? null : ($result['message'] ?? 'Erro desconhecido'),
            'content' => $logContent,
            'batch_id' => $batchId,
            'sent_at' => now(),
        ]);

        return $result['success'] ? 'sent' : 'error';
    }

    protected function sendMessageForBirthday(MessageCron $cron, Cliente $client, string $batchId)
    {
        $originalPhone = $client->mobile_phone ?? $client->phone;
        if (! $originalPhone) {
            return 'skipped';
        }

        $sanitizedPhone = PhoneSanitizerService::sanitize($originalPhone);
        if (! $sanitizedPhone) {
            $this->logError($cron, $client, $originalPhone, 'Cliente sem telefone válido após sanitização.');

            return 'error';
        }

        if (PhoneSanitizerService::isLandline($sanitizedPhone)) {
            $this->logError($cron, $client, $originalPhone, 'Telefone fixo (não suportado).');

            return 'error';
        }

        $ignoreSentToday = false;
        $connId = $cron->connection_id ?? ($client->connection_id ?? null);
        if ($connId) {
            $ignoreSentToday = (bool) CompanyMessageSetting::where('conta_azul_connection_id', $connId)
                ->where('message_type', 'ignore_sent_today')
                ->value('is_enabled');
        }
        // Regra de "Já enviado hoje" não se aplica para aniversários

        if (! $cron->whatsapp_number_id) {
            $this->logError($cron, $client, $originalPhone, 'Cron sem número de WhatsApp vinculado.');

            return 'error';
        }

        $content = $this->replaceVariables($cron->messageTemplate->content, null, $client);

        try {
            $whatsapp = WhatsappNumber::find($cron->whatsapp_number_id);
            $provider = $this->providerResolver->resolve($whatsapp);
            $result = $provider->sendMessage($cron->whatsapp_number_id, $sanitizedPhone, $content);
            $logContent = $content;
            if (($result['success'] ?? false) && isset($result['meta'])) {
                $st = $result['meta']['whapi_status'] ?? ($result['meta']['evolution_status'] ?? null);
                $mid = $result['meta']['message_id'] ?? null;
                if ($st || $mid) {
                    $providerName = $result['meta']['provider'] ?? ($whatsapp->provider ?? 'whapi');
                    $logContent .= "\n[provider={$providerName}; delivery={$st}; id={$mid}]";
                }
            }

            WhatsappMessageLog::create([
                'whatsapp_number_id' => $cron->whatsapp_number_id,
                'connection_id' => $connId,
                'message_cron_id' => $cron->id,
                'cliente_id' => $client->id,
                'client_name' => $client->name,
                'phone_original' => $originalPhone,
                'phone_sanitized' => $sanitizedPhone,
                'message_type' => $cron->type,
                'provider' => $whatsapp->provider ?? null,
                'message_template_id' => $cron->message_template_id,
                'status' => $result['success'] ? 'success' : 'error',
                'error_message' => $result['success'] ? null : ($result['message'] ?? 'Erro desconhecido'),
                'content' => $logContent,
                'batch_id' => $batchId,
                'sent_at' => now(),
            ]);

            return $result['success'] ? 'sent' : 'error';

        } catch (\Exception $e) {
            $this->logError($cron, $client, $originalPhone, $e->getMessage());

            return 'error';
        }
    }

    protected function replaceVariables($content, ?Invoice $invoice = null, ?Cliente $client = null)
    {
        $replacements = [];

        if ($invoice) {
            $lateDays = 0;
            $now = Carbon::now();
            if ($invoice->data_vencimento < $now) {
                $lateDays = $now->diffInDays($invoice->data_vencimento);
            }

            $dueDate = Carbon::parse($invoice->data_vencimento);
            $adjustedDate = $dueDate->copy();
            if ($dueDate->isSaturday()) {
                $adjustedDate->addDays(2);
            }
            if ($dueDate->isSunday()) {
                $adjustedDate->addDays(1);
            }

            $replacements = [
                '@@clientName@@' => $invoice->cliente->name,
                '@@clientCompany@@' => $invoice->cliente->company_name ?? '',
                '@@clientEmail@@' => $invoice->cliente->email ?? '',
                '@@invoiceTotalValue@@' => number_format($invoice->valor_original, 2, ',', '.'),
                '@@invoiceOpenValue@@' => number_format($invoice->saldo_devedor, 2, ',', '.'),
                '@@invoiceLateDays@@' => $lateDays,
                '@@invoiceDueDate@@' => $adjustedDate->format('d/m/Y'),
                '@@invoiceStrictDueDate@@' => $dueDate->format('d/m/Y'),
                '@@invoiceUrl@@' => $invoice->link_boleto ?? '',
                '@@invoicePastDueQuantity@@' => '',
                '@@invoicePastDueDates@@' => '',
            ];
        } elseif ($client) {
            $replacements = [
                '@@clientName@@' => $client->name,
                '@@clientCompany@@' => $client->company_name ?? '',
                '@@clientEmail@@' => $client->email ?? '',
            ];
        }

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    protected function getPeriodStartDate(MessageCron $cron)
    {
        if (! $cron->period_value || ! $cron->period_unit) {
            return null;
        }

        $date = Carbon::now();
        switch ($cron->period_unit) {
            case 'days': $date->subDays($cron->period_value);
                break;
            case 'months': $date->subMonths($cron->period_value);
                break;
            case 'years': $date->subYears($cron->period_value);
                break;
        }

        return $date->format('Y-m-d');
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

    protected function logError($cron, $cliente, $phone, $msg, $invoiceCount = null)
    {
        WhatsappMessageLog::create([
            'whatsapp_number_id' => $cron->whatsapp_number_id,
            'connection_id' => $cron->connection_id,
            'message_cron_id' => $cron->id,
            'cliente_id' => $cliente ? $cliente->id : null,
            'client_name' => $cliente ? $cliente->name : 'Desconhecido',
            'phone_original' => $phone ?? 'N/A',
            'phone_sanitized' => PhoneSanitizerService::sanitize($phone),
            'message_type' => $cron->type,
            'provider' => optional($cron->whatsappNumber)->provider,
            'message_template_id' => $cron->message_template_id,
            'total_boletos' => $invoiceCount ?? 0,
            'status' => 'error',
            'error_message' => $msg,
            'sent_at' => now(),
        ]);
    }

    protected function sendGroupedMessageForClient(MessageCron $cron, Cliente $cliente, $invoices, string $batchId)
    {
        $connIdCtx = (int) (($cliente->connection_id ?? $cron->connection_id));
        $clientBlocked = $this->restrictionService->isBlocked($connIdCtx, [
            'cliente_nome' => $cliente->name ?? '',
            'cliente_ca_id' => $cliente->ca_id ?? null,
            'invoice_ca_id' => null,
            'descricao' => '',
        ]);
        if ($clientBlocked) {
            $originalPhone = $cliente->mobile_phone ?? $cliente->phone;
            $sanitizedPhone = PhoneSanitizerService::sanitize($originalPhone);

            WhatsappMessageLog::create([
                'whatsapp_number_id' => $cron->whatsapp_number_id,
                'connection_id' => $cron->connection_id ?? ($cliente->connection_id ?? null),
                'message_cron_id' => $cron->id,
                'cliente_id' => $cliente->id,
                'client_name' => $cliente->name,
                'phone_original' => $originalPhone,
                'phone_sanitized' => $sanitizedPhone,
                'message_type' => $cron->type,
                'provider' => optional($cron->whatsappNumber)->provider,
                'message_template_id' => $cron->message_template_id,
                'total_boletos' => is_countable($invoices) ? count($invoices) : null,
                'boleto_ids' => collect($invoices)->pluck('id')->toArray(),
                'status' => 'skipped',
                'error_message' => 'Bloqueado por regra de restrição (cliente)',
                'batch_id' => $batchId,
                'sent_at' => now(),
            ]);

            return 'skipped';
        }

        $originalInvoices = collect(is_array($invoices) ? $invoices : (is_countable($invoices) ? $invoices->all() : []));
        $validInvoices = $originalInvoices->reject(function ($invoice) use ($cron, $cliente) {
            $context = [
                'cliente_nome' => $cliente->name ?? ($invoice->cliente_nome ?? ''),
                'cliente_ca_id' => $cliente->ca_id ?? $invoice->cliente_ca_id,
                'invoice_ca_id' => $invoice->ca_id,
                'descricao' => $invoice->descricao,
            ];

            return $this->restrictionService->isBlocked((int) ($invoice->connection_id ?? $cron->connection_id), $context);
        });
        $blockedInvoices = $originalInvoices->filter(function ($invoice) use ($cron, $cliente) {
            $context = [
                'cliente_nome' => $cliente->name ?? ($invoice->cliente_nome ?? ''),
                'cliente_ca_id' => $cliente->ca_id ?? $invoice->cliente_ca_id,
                'invoice_ca_id' => $invoice->ca_id,
                'descricao' => $invoice->descricao,
            ];

            return $this->restrictionService->isBlocked((int) ($invoice->connection_id ?? $cron->connection_id), $context);
        });
        Log::info('Cron billing - restrições aplicadas no agrupamento', [
            'cron_id' => $cron->id,
            'cliente_id' => $cliente->id,
            'total_invoices' => $originalInvoices->count(),
            'blocked_count' => $blockedInvoices->count(),
            'blocked_ids' => $blockedInvoices->pluck('id')->toArray(),
        ]);
        if ($validInvoices->isEmpty()) {
            $originalPhone = $cliente->mobile_phone ?? $cliente->phone;
            $sanitizedPhone = PhoneSanitizerService::sanitize($originalPhone);
            WhatsappMessageLog::create([
                'whatsapp_number_id' => $cron->whatsapp_number_id,
                'connection_id' => $cron->connection_id ?? ($cliente->connection_id ?? null),
                'message_cron_id' => $cron->id,
                'cliente_id' => $cliente->id,
                'client_name' => $cliente->name,
                'phone_original' => $originalPhone,
                'phone_sanitized' => $sanitizedPhone,
                'message_type' => $cron->type,
                'provider' => optional($cron->whatsappNumber)->provider,
                'message_template_id' => $cron->message_template_id,
                'total_boletos' => is_countable($invoices) ? count($invoices) : null,
                'boleto_ids' => collect($invoices)->pluck('id')->toArray(),
                'status' => 'skipped',
                'error_message' => 'Bloqueado por regra de restrição (todas as faturas)',
                'batch_id' => $batchId,
                'sent_at' => now(),
            ]);

            return 'skipped';
        }
        $invoices = $validInvoices->all();

        $originalPhone = $cliente->mobile_phone ?? $cliente->phone;
        if (! $originalPhone) {
            $this->logError($cron, $cliente, null, 'Cliente sem telefone cadastrado.', is_countable($invoices) ? count($invoices) : null);

            return 'error';
        }

        $sanitizedPhone = PhoneSanitizerService::sanitize($originalPhone);
        if (! $sanitizedPhone) {
            $this->logError($cron, $cliente, $originalPhone, 'Cliente sem telefone válido após sanitização.', is_countable($invoices) ? count($invoices) : null);

            return 'error';
        }

        if (PhoneSanitizerService::isLandline($sanitizedPhone)) {
            $this->logError($cron, $cliente, $originalPhone, 'Telefone fixo (não suportado).', is_countable($invoices) ? count($invoices) : null);

            return 'error';
        }

        $connId = $cron->connection_id ?? ($cliente->connection_id ?? null);
        $ignoreSentToday = false;
        if ($connId) {
            $ignoreSentToday = (bool) CompanyMessageSetting::where('conta_azul_connection_id', $connId)
                ->where('message_type', 'ignore_sent_today')
                ->value('is_enabled');
        }
        if ($cron->type === 'billing' && ! $ignoreSentToday) {
            $alreadySent = WhatsappMessageLog::where('message_cron_id', $cron->id)
                ->where('cliente_id', $cliente->id)
                ->whereDate('sent_at', Carbon::today())
                ->exists();
            if ($alreadySent) {
                WhatsappMessageLog::create([
                    'whatsapp_number_id' => $cron->whatsapp_number_id,
                    'connection_id' => $connId,
                    'message_cron_id' => $cron->id,
                    'cliente_id' => $cliente->id,
                    'client_name' => $cliente->name,
                    'phone_original' => $originalPhone,
                    'phone_sanitized' => $sanitizedPhone,
                    'message_type' => $cron->type,
                    'provider' => optional($cron->whatsappNumber)->provider,
                    'message_template_id' => $cron->message_template_id,
                    'total_boletos' => is_countable($invoices) ? count($invoices) : null,
                    'boleto_ids' => collect($invoices)->pluck('id')->toArray(),
                    'status' => 'skipped',
                    'error_message' => 'Já enviado hoje',
                    'batch_id' => $batchId,
                    'sent_at' => now(),
                ]);

                return 'skipped';
            }
        }

        $context = [
            'cliente_nome' => $cliente->name ?? '',
            'cliente_ca_id' => $cliente->ca_id ?? null,
            'invoice_ca_id' => null,
            'descricao' => 'GroupedBilling',
        ];
        if ($connId && $this->restrictionService->isBlocked((int) $connId, $context)) {
            WhatsappMessageLog::create([
                'whatsapp_number_id' => $cron->whatsapp_number_id,
                'connection_id' => $connId,
                'message_cron_id' => $cron->id,
                'cliente_id' => $cliente->id,
                'client_name' => $cliente->name,
                'phone_original' => $originalPhone,
                'phone_sanitized' => $sanitizedPhone,
                'message_type' => $cron->type,
                'provider' => optional($cron->whatsappNumber)->provider,
                'message_template_id' => $cron->message_template_id,
                'total_boletos' => is_countable($invoices) ? count($invoices) : null,
                'boleto_ids' => collect($invoices)->pluck('id')->toArray(),
                'status' => 'skipped',
                'error_message' => 'Bloqueado por regra de restrição',
                'batch_id' => $batchId,
                'sent_at' => now(),
            ]);

            return 'skipped';
        }

        if (! $cron->messageTemplate) {
            $this->logError($cron, $cliente, $originalPhone, 'Template de mensagem não encontrado.', is_countable($invoices) ? count($invoices) : null);

            return 'error';
        }

        if (! $cron->whatsapp_number_id) {
            $this->logError($cron, $cliente, $originalPhone, 'Cron sem número de WhatsApp vinculado.', is_countable($invoices) ? count($invoices) : null);

            return 'error';
        }

        $dates = collect($invoices)->map(function ($inv) {
            return Carbon::parse($inv->data_vencimento)->format('d/m/Y');
        })->values()->all();
        $earliest = collect($invoices)->min(fn ($inv) => Carbon::parse($inv->data_vencimento));
        $adjustedEarliest = $earliest ? (clone $earliest) : null;
        if ($adjustedEarliest) {
            if ($adjustedEarliest->isSaturday()) {
                $adjustedEarliest->addDays(2);
            }
            if ($adjustedEarliest->isSunday()) {
                $adjustedEarliest->addDays(1);
            }
        }

        $totalValue = collect($invoices)->sum(function ($inv) {
            return (float) ($inv->saldo_devedor ?? $inv->nao_pago ?? 0);
        });
        $firstUrl = collect($invoices)->first(function ($inv) {
            return ! empty($inv->link_boleto);
        });
        $allUrls = collect($invoices)->pluck('link_boleto')->filter()->implode("\n");
        $pairs = collect($invoices)->map(function ($inv) {
            $due = Carbon::parse($inv->data_vencimento)->format('d/m/Y');
            $url = trim($inv->link_boleto ?? '');

            if ($url !== '') {
                $display = "\n{$url}";
            } else {
                $display = 'boleto não disponível';
                Log::warning("Boleto não disponível para fatura ID {$inv->id} (CA ID: {$inv->ca_id}) do cliente {$inv->cliente->name}. Motivo: URL vazia no banco de dados.");
            }

            return $due.' - '.$display;
        })->implode("\n");

        $content = $cron->messageTemplate->content;
        $content = $this->replaceVariables($content, null, $cliente);
        $content = str_replace([
            '@@invoicePastDueQuantity@@',
            '@@invoicePastDueDates@@',
            '@@invoiceTotalValue@@',
            '@@invoiceBoletoUrl@@',
            '@@invoiceUrl@@',
            '@@invoiceBoletoUrls@@',
            '@@invoicePastDuePairs@@',
            '@@invoiceDueDate@@',
            '@@invoiceStrictDueDate@@',
            '@@invoiceOpenValue@@',
            '@@invoiceLateDays@@',
            '@@invoiceTotalOpenQuantity@@',
            '@@invoiceTotalOpenValue@@',
        ], [
            (string) count($dates),
            implode(', ', $dates),
            number_format($totalValue, 2, ',', '.'),
            $firstUrl ? ($firstUrl->link_boleto ?? '') : '',
            $firstUrl ? ($firstUrl->link_boleto ?? '') : '',
            $allUrls,
            $pairs,
            $adjustedEarliest ? $adjustedEarliest->format('d/m/Y') : '',
            $earliest ? $earliest->format('d/m/Y') : '',
            number_format($totalValue, 2, ',', '.'),
            $earliest ? Carbon::now()->diffInDays($earliest) : 0,
            (string) count($dates), // Em automações de billing, todas as faturas selecionadas são as "em aberto" do contexto
            number_format($totalValue, 2, ',', '.'),
        ], $content);

        $content = $this->sanitizeLinks($content);
        if ($this->shouldDisablePreview($cron, $cron->connection_id ?? ($cliente->connection_id ?? null))) {
            $content = $this->disablePreviewLinks($content);
        } elseif ($this->shouldLimitPreview($cron, $cron->connection_id ?? ($cliente->connection_id ?? null))) {
            $content = $this->limitPreviewLinks($content);
        }
        $whatsapp = WhatsappNumber::find($cron->whatsapp_number_id);
        $provider = $this->providerResolver->resolve($whatsapp);
        $result = $provider->sendMessage($cron->whatsapp_number_id, $sanitizedPhone, $content);

        WhatsappMessageLog::create([
            'whatsapp_number_id' => $cron->whatsapp_number_id,
            'connection_id' => $connId,
            'message_cron_id' => $cron->id,
            'cliente_id' => $cliente->id,
            'client_name' => $cliente->name,
            'phone_original' => $originalPhone,
            'phone_sanitized' => $sanitizedPhone,
            'message_type' => $cron->type,
            'provider' => $whatsapp->provider ?? null,
            'message_template_id' => $cron->message_template_id,
            'total_boletos' => is_countable($invoices) ? count($invoices) : null,
            'boleto_ids' => collect($invoices)->pluck('id')->toArray(),
            'status' => $result['success'] ? 'success' : 'error',
            'error_message' => $result['success'] ? null : ($result['message'] ?? 'Erro desconhecido'),
            'content' => $content,
            'batch_id' => $batchId,
            'sent_at' => now(),
        ]);

        return $result['success'] ? 'sent' : 'error';
    }

    protected function isUrlReachable(string $url): bool
    {
        try {
            $response = Http::withOptions([
                'verify' => false,
            ])->timeout(10)->get($url);

            if ($response->ok()) {
                return true;
            }
            // Considera redirecionamentos e 3xx como válidos para preview
            if ($response->status() >= 300 && $response->status() < 400) {
                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::warning("Falha ao verificar link do boleto ({$url}): ".$e->getMessage());

            return false;
        }
    }

    protected function shouldLimitPreview(MessageCron $cron, ?int $connectionId): bool
    {
        if ($cron->limit_link_preview) {
            return true;
        }
        if ($connectionId) {
            return (bool) CompanyMessageSetting::where('conta_azul_connection_id', $connectionId)
                ->where('message_type', 'limit_link_preview')
                ->value('is_enabled');
        }

        return false;
    }

    protected function limitPreviewLinks(string $text): string
    {
        return preg_replace('/\bhxxps:\/\//i', 'https://', $text);
    }

    protected function shouldDisablePreview(MessageCron $cron, ?int $connectionId): bool
    {
        if ($cron->disable_link_preview) {
            return true;
        }
        if ($connectionId) {
            return (bool) CompanyMessageSetting::where('conta_azul_connection_id', $connectionId)
                ->where('message_type', 'disable_link_preview')
                ->value('is_enabled');
        }

        return false;
    }

    protected function disablePreviewLinks(string $text): string
    {
        return preg_replace('/\bhxxps:\/\//i', 'https://', $text);
    }
}
