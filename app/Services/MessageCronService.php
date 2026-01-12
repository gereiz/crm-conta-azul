<?php

namespace App\Services;

use App\Models\MessageCron;
use App\Models\MessageCronLog;
use App\Models\CompanyMessageSetting;
use App\Models\CompanyCronRule;
use App\Models\Invoice;
use App\Models\Cliente;
use App\Models\WhatsappNumber;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class MessageCronService
{
    protected $whapiService;
    protected $restrictionService;

    public function __construct(WhapiService $whapiService, BillingRestrictionService $restrictionService)
    {
        $this->whapiService = $whapiService;
        $this->restrictionService = $restrictionService;
    }

    public function processCron(MessageCron $cron)
    {
        Log::info("Processando cron: {$cron->name} (ID: {$cron->id}, Tipo: {$cron->type})");

        $stats = [
            'sent' => 0,
            'errors' => 0,
            'skipped' => 0,
            'total' => 0
        ];

        try {
            if (!empty($cron->connection_id)) {
                $typeEnabled = CompanyMessageSetting::where('conta_azul_connection_id', $cron->connection_id)
                    ->where('message_type', $cron->type)
                    ->value('is_enabled');
                if (!$typeEnabled) {
                    Log::info("Cron {$cron->id} ignorado: tipo {$cron->type} desabilitado para conexão {$cron->connection_id}.");
                    $stats['skipped'] = 0;
                    return $stats;
                }
                $rule = CompanyCronRule::where('conta_azul_connection_id', $cron->connection_id)
                    ->where('message_type', $cron->type)
                    ->where('is_active', true)
                    ->first();
                if ($rule && !$this->passesCompanyRule($rule)) {
                    Log::info("Cron {$cron->id} ignorado: regra da empresa não permite execução hoje.");
                    $stats['skipped'] = 0;
                    return $stats;
                }
            }
            switch ($cron->type) {
                case 'billing':
                    $stats = $this->processBilling($cron);
                    break;
                case 'due_date':
                    $stats = $this->processDueDate($cron);
                    break;
                case 'boleto':
                    $stats = $this->processBoleto($cron);
                    break;
                case 'birthday':
                    $stats = $this->processBirthday($cron);
                    break;
            }

            $cron->update(['last_run_at' => now()]);
            
            return $stats;

        } catch (\Exception $e) {
            Log::error("Erro ao processar cron {$cron->id}: " . $e->getMessage());
            return $stats;
        }
    }

    protected function passesCompanyRule(CompanyCronRule $rule): bool
    {
        $now = Carbon::now();
        if ($rule->exclude_weekends && ($now->isSaturday() || $now->isSunday())) {
            return false;
        }
        if ($rule->rule_type === 'monthly_day') {
            if (!$rule->day_of_month) return false;
            return $now->day === (int) $rule->day_of_month;
        }
        if ($rule->rule_type === 'weekly_day') {
            if ($rule->day_of_week === null) return false;
            return $now->dayOfWeek === (int) $rule->day_of_week;
        }
        if ($rule->rule_type === 'interval_days') {
            // Sem estado de última execução a nível de empresa, não bloqueamos
            return true;
        }
        return true;
    }

    protected function processBilling(MessageCron $cron)
    {
        $daysLate = $cron->days_after_due ?? 0;
        $dueDateLimit = Carbon::now()->subDays($daysLate)->format('Y-m-d');
        $periodStart = $this->getPeriodStartDate($cron);

        $query = Invoice::where('status', 'OPEN')
            ->where('data_vencimento', '<', $dueDateLimit);
        
        if (!empty($cron->connection_id)) {
            $query->where('connection_id', $cron->connection_id);
        }
        
        if ($periodStart) {
            $query->where('data_vencimento', '>=', $periodStart);
        }

        $invoices = $query->with('cliente')->get();
        
        $stats = ['sent' => 0, 'errors' => 0, 'skipped' => 0, 'total' => $invoices->count()];

        foreach ($invoices as $invoice) {
            $result = $this->sendMessageForInvoice($cron, $invoice);
            if ($result === 'sent') $stats['sent']++;
            elseif ($result === 'error') $stats['errors']++;
            else $stats['skipped']++;
        }

        return $stats;
    }

    protected function processDueDate(MessageCron $cron)
    {
        $daysBefore = $cron->days_before_due ?? 0;
        $targetDate = Carbon::now()->addDays($daysBefore)->format('Y-m-d');

        $query = Invoice::where('status', 'OPEN')
            ->whereDate('data_vencimento', $targetDate)
            ;
        
        if (!empty($cron->connection_id)) {
            $query->where('connection_id', $cron->connection_id);
        }
        
        $invoices = $query->with('cliente')->get();

        $stats = ['sent' => 0, 'errors' => 0, 'skipped' => 0, 'total' => $invoices->count()];

        foreach ($invoices as $invoice) {
            $result = $this->sendMessageForInvoice($cron, $invoice);
            if ($result === 'sent') $stats['sent']++;
            elseif ($result === 'error') $stats['errors']++;
            else $stats['skipped']++;
        }

        return $stats;
    }

    protected function processBoleto(MessageCron $cron)
    {
        $today = Carbon::now()->format('Y-m-d');
        
        $query = Invoice::whereDate('data_emissao', $today);
        if (!empty($cron->connection_id)) {
            $query->where('connection_id', $cron->connection_id);
        }
        $invoices = $query->with('cliente')->get();

        $stats = ['sent' => 0, 'errors' => 0, 'skipped' => 0, 'total' => $invoices->count()];

        foreach ($invoices as $invoice) {
            $result = $this->sendMessageForInvoice($cron, $invoice);
            if ($result === 'sent') $stats['sent']++;
            elseif ($result === 'error') $stats['errors']++;
            else $stats['skipped']++;
        }

        return $stats;
    }

    protected function processBirthday(MessageCron $cron)
    {
        $today = Carbon::now()->format('m-d');
        
        $query = Cliente::whereRaw("DATE_FORMAT(birthdate, '%m-%d') = ?", [$today]);
        if (!empty($cron->connection_id)) {
            $query->where('connection_id', $cron->connection_id);
        }
        $clients = $query->get();

        $stats = ['sent' => 0, 'errors' => 0, 'skipped' => 0, 'total' => $clients->count()];

        foreach ($clients as $client) {
            $result = $this->sendMessageForBirthday($cron, $client);
            if ($result === 'sent') $stats['sent']++;
            elseif ($result === 'error') $stats['errors']++;
            else $stats['skipped']++;
        }

        return $stats;
    }

    protected function sendMessageForInvoice(MessageCron $cron, Invoice $invoice)
    {
        if (!$invoice->cliente) return 'skipped';

        $alreadySent = MessageCronLog::where('message_cron_id', $cron->id)
            ->where('cliente_id', $invoice->cliente_id)
            ->whereDate('sent_at', Carbon::today())
            ->exists();

        if ($alreadySent) return 'skipped';

        $context = [
            'cliente_nome' => $invoice->cliente->name ?? ($invoice->cliente_nome ?? ''),
            'cliente_ca_id' => $invoice->cliente_ca_id,
            'invoice_ca_id' => $invoice->ca_id,
            'descricao' => $invoice->descricao,
        ];
        if ($invoice->connection_id && $this->restrictionService->isBlocked((int) $invoice->connection_id, $context)) {
            MessageCronLog::create([
                'message_cron_id' => $cron->id,
                'cliente_id' => $invoice->cliente_id,
                'client_name' => $invoice->cliente->name,
                'phone' => $invoice->cliente->mobile_phone ?? $invoice->cliente->phone ?? 'N/A',
                'status' => 'skipped',
                'error_message' => 'Bloqueado por regra de restrição',
                'sent_at' => now(),
            ]);
            return 'skipped';
        }

        $phone = $invoice->cliente->mobile_phone ?? $invoice->cliente->phone;
        if (!$phone) {
             $this->logError($cron, $invoice->cliente, null, "Cliente sem telefone cadastrado.");
             return 'error';
        }

        if (!$cron->messageTemplate) {
            $this->logError($cron, $invoice->cliente, $phone, "Template de mensagem não encontrado.");
            return 'error';
        }

        // Use the cron's specific WhatsApp number
        if (!$cron->whatsapp_number_id) {
             $this->logError($cron, $invoice->cliente, $phone, "Cron sem número de WhatsApp vinculado.");
             return 'error';
        }

        $message = $this->replaceVariables($cron->messageTemplate->content, $invoice);

        $result = $this->whapiService->sendMessage($cron->whatsapp_number_id, $phone, $message);

        MessageCronLog::create([
            'message_cron_id' => $cron->id,
            'cliente_id' => $invoice->cliente_id,
            'client_name' => $invoice->cliente->name,
            'phone' => $phone,
            'status' => $result['success'] ? 'success' : 'error',
            'error_message' => $result['success'] ? null : ($result['message'] ?? 'Erro desconhecido'),
            'sent_at' => now(),
        ]);

        return $result['success'] ? 'sent' : 'error';
    }

    protected function sendMessageForBirthday(MessageCron $cron, Cliente $client)
    {
        if (!$client->mobile_phone) return 'skipped';

        $alreadySent = MessageCronLog::where('message_cron_id', $cron->id)
            ->where('cliente_id', $client->id)
            ->whereDate('sent_at', Carbon::today())
            ->exists();

        if ($alreadySent) return 'skipped';

        if (!$cron->whatsapp_number_id) {
             $this->logError($cron, $client, $client->mobile_phone, "Cron sem número de WhatsApp vinculado.");
            return 'error';
        }

        $content = $this->replaceVariables($cron->messageTemplate->content, null, $client);
        
        try {
            $result = $this->whapiService->sendMessage($cron->whatsapp_number_id, $client->mobile_phone, $content);

            MessageCronLog::create([
                'message_cron_id' => $cron->id,
                'cliente_id' => $client->id,
                'client_name' => $client->name,
                'phone' => $client->mobile_phone,
                'status' => $result['success'] ? 'success' : 'error',
                'error_message' => $result['success'] ? null : ($result['message'] ?? 'Erro desconhecido'),
                'sent_at' => now(),
            ]);

            return $result['success'] ? 'sent' : 'error';

        } catch (\Exception $e) {
            $this->logError($cron, $client, $client->mobile_phone, $e->getMessage());
            return 'error';
        }
    }

    protected function replaceVariables($content, Invoice $invoice = null, Cliente $client = null)
    {
        $replacements = [];

        if ($invoice) {
            $lateDays = 0;
            $now = Carbon::now();
            if ($invoice->data_vencimento < $now && $invoice->status === 'OPEN') {
                $lateDays = $now->diffInDays($invoice->data_vencimento);
            }
            
            $dueDate = Carbon::parse($invoice->data_vencimento);
            $adjustedDate = $dueDate->copy();
            if ($dueDate->isSaturday()) $adjustedDate->addDays(2);
            if ($dueDate->isSunday()) $adjustedDate->addDays(1);

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
        if (!$cron->period_value || !$cron->period_unit) return null;

        $date = Carbon::now();
        switch ($cron->period_unit) {
            case 'days': $date->subDays($cron->period_value); break;
            case 'months': $date->subMonths($cron->period_value); break;
            case 'years': $date->subYears($cron->period_value); break;
        }
        return $date->format('Y-m-d');
    }

    protected function logError($cron, $cliente, $phone, $msg)
    {
        MessageCronLog::create([
            'message_cron_id' => $cron->id,
            'cliente_id' => $cliente ? $cliente->id : null,
            'client_name' => $cliente ? $cliente->name : 'Desconhecido',
            'phone' => $phone ?? 'N/A',
            'status' => 'error',
            'error_message' => $msg,
            'sent_at' => now(),
        ]);
    }
}
