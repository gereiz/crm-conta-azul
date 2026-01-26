<?php

namespace App\Services;

use App\Models\FutureMessageSchedule;
use App\Models\MessageCron;
use App\Models\Invoice;
use App\Models\Cliente;
use App\Models\CompanyCronRule;
use App\Models\WhatsappMessageLog;
use App\Models\ContaAzulConnection;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class FutureMessageService
{
    protected $restrictionService;

    public function __construct(BillingRestrictionService $restrictionService)
    {
        $this->restrictionService = $restrictionService;
    }

    public function calculateForConnection(ContaAzulConnection $connection)
    {
        // Limpa agendamentos futuros pendentes para esta conexão (para recalcular)
        // Mantemos os que já foram processados/enviados hoje se quisermos histórico, 
        // mas a ideia é ter um "snapshot" do que vai acontecer.
        // Vamos limpar tudo que é >= Hoje e que ainda não foi enviado.
        FutureMessageSchedule::where('connection_id', $connection->id)
            ->whereDate('scheduled_send_date', '>=', Carbon::today())
            ->delete();

        $crons = MessageCron::where('connection_id', $connection->id)
            ->where('is_active', true)
            ->get();

        foreach ($crons as $cron) {
            $this->calculateForCron($cron);
        }
    }

    public function calculateForCron(MessageCron $cron)
    {
        // Calcula para hoje e próximos dias (ex: 3 dias de visibilidade)
        $horizon = 3; 
        
        for ($i = 0; $i <= $horizon; $i++) {
            $targetDate = Carbon::today()->addDays($i);
            
            // Verifica se a regra da empresa permite envio neste dia
            if (!$this->isDayAllowed($cron, $targetDate)) {
                continue;
            }

            switch ($cron->type) {
                case 'boleto': // Emissão
                    $this->scheduleEmission($cron, $targetDate);
                    break;
                case 'due_date': // Vencimento
                    $this->scheduleDueDate($cron, $targetDate);
                    break;
                case 'birthday': // Aniversário
                    $this->scheduleBirthday($cron, $targetDate);
                    break;
                case 'billing': // Cobrança
                    $this->scheduleBilling($cron, $targetDate);
                    break;
            }
        }
    }

    protected function isDayAllowed(MessageCron $cron, Carbon $date)
    {
        // Verifica regras globais ou da empresa
        $rule = CompanyCronRule::where('conta_azul_connection_id', $cron->connection_id)
            ->where('message_type', $cron->type)
            ->where('is_active', true)
            ->first();

        if (!$rule) {
            $rule = CompanyCronRule::whereNull('conta_azul_connection_id')
                ->where('message_type', $cron->type)
                ->where('is_active', true)
                ->first();
        }

        if (!$rule) return true; // Sem regra, permite tudo (ou default?) Assumindo true.

        if ($rule->exclude_weekends && ($date->isSaturday() || $date->isSunday())) {
            return false;
        }

        if ($rule->rule_type === 'monthly_day') {
            return $date->day === (int) $rule->day_of_month;
        }

        if ($rule->rule_type === 'weekly_day') {
            return $date->dayOfWeek === (int) $rule->day_of_week;
        }

        // Interval days é mais complexo pois depende da última execução. 
        // Para "Envios Futuros" é difícil prever sem estado. 
        // Vamos assumir true para fins de visualização ou ignorar.
        
        return true;
    }

    protected function createSchedule($cron, $cliente, $invoice, $eventDate, $sendDate, $blockReason = null)
    {
        // Verifica duplicidade no agendamento
        $exists = FutureMessageSchedule::where('connection_id', $cron->connection_id)
            ->where('message_type', $cron->type)
            ->where('cliente_id', $cliente->id)
            ->whereDate('scheduled_send_date', $sendDate)
            ->when($invoice, fn($q) => $q->where('invoice_id', $invoice->id))
            ->exists();

        if ($exists) return;

        // Verifica restrições
        $status = 'pending';
        if (!$blockReason) {
            $isBlocked = $this->restrictionService->isBlocked($cron->connection_id, [
                'cliente_nome' => $cliente->name,
                'cliente_ca_id' => $cliente->ca_id,
                'invoice_ca_id' => $invoice?->ca_id,
                'descricao' => $invoice?->descricao
            ]);
            
            if ($isBlocked) {
                $status = 'blocked';
                $blockReason = 'Restrição de envio configurada';
            }
        } else {
            $status = 'blocked'; // Ou ignored?
        }
        
        // Verifica se já foi enviado HOJE (se sendDate for hoje)
        if ($sendDate->isToday()) {
             $alreadySent = WhatsappMessageLog::where('message_cron_id', $cron->id)
                ->where('cliente_id', $cliente->id)
                ->whereDate('sent_at', Carbon::today())
                ->exists();
             if ($alreadySent) {
                 $status = 'sent';
                 $blockReason = 'Já enviado hoje';
             }
        }

        FutureMessageSchedule::create([
            'connection_id' => $cron->connection_id,
            'cliente_id' => $cliente->id,
            'message_type' => $cron->type,
            'invoice_id' => $invoice?->id,
            'event_date' => $eventDate,
            'scheduled_send_date' => $sendDate,
            'status' => $status,
            'block_reason' => $blockReason,
        ]);
    }

    // --- Lógica Específica por Tipo ---

    protected function scheduleEmission(MessageCron $cron, Carbon $targetDate)
    {
        // NOVA REGRA: Emissão = Faturas com vencimento futuro (> hoje) E com link_boleto existente.
        // Independentemente da data de emissão real, o gatilho é a disponibilidade do boleto para faturas futuras.
        
        // Intervalo: De amanhã até X dias no futuro (baseado na config do cron ou fixo?)
        // O usuário disse: "Faturas com vencimento futuro e com link de boleto".
        // Vamos considerar "Futuro" como > Hoje.
        
        $today = Carbon::today()->format('Y-m-d');
        
        // Busca faturas com vencimento > hoje e com link_boleto preenchido
        $query = Invoice::where('connection_id', $cron->connection_id)
            ->where('data_vencimento', '>', $today)
            ->whereNotNull('link_boleto')
            ->where('link_boleto', '!=', '')
            ->where('status', '!=', 'PAID') // Garantir que não está paga
            ->where('status', '!=', 'BAIXADO');

        // Se o cron tiver configuração de dias (period_value), usamos como filtro de vencimento?
        // Ex: Vencimento nos próximos 30 dias.
        // Se period_value for definido, usamos. Se não, pegamos todas futuras (cuidado com volume).
        // Vamos limitar a 30 dias por segurança/padrão se não houver config.
        $days = (int) ($cron->period_value ?? 30);
        $limitDate = Carbon::today()->addDays($days)->format('Y-m-d');
        
        $query->where('data_vencimento', '<=', $limitDate);

        $invoices = $query->get();

        foreach ($invoices as $invoice) {
            if (!$invoice->cliente) continue;
            
            // Verifica se já enviou mensagem de EMISSÃO para esta fatura
            $alreadySent = WhatsappMessageLog::where('message_cron_id', $cron->id)
                ->where('invoice_id', $invoice->id)
                ->exists();

            if ($alreadySent) continue;
            
            // Agenda para o targetDate (que é hoje na iteração 0, amanhã na 1...)
            // Mas a lógica de loop do Service calcula para [Hoje, Hoje+1, Hoje+2].
            // Se a fatura já está pronta HOJE, ela deve aparecer no schedule de HOJE.
            // Se estamos calculando schedule futuro (amanhã), ela também estaria pronta amanhã.
            // Para evitar duplicidade visual no grid (aparecer em 25/01, 26/01...),
            // devemos agendar para a "data de execução" mais próxima, que é o targetDate atual.
            // Se o usuário filtrar "Amanhã", ele verá que ela será enviada amanhã (se não for enviada hoje).
            
            $this->createSchedule($cron, $invoice->cliente, $invoice, $invoice->data_vencimento, $targetDate);
        }
    }

    protected function scheduleDueDate(MessageCron $cron, Carbon $targetDate)
    {
        // NOVA REGRA: Vencimento = Faturas com vencimento futuro (> hoje) E SEM link_boleto.
        // Isso serve como aviso de vencimento / lembrete de pagamento, mesmo sem o boleto gerado ainda na API
        // (ou boleto que não é gerado via ContaAzul, apenas registrado).
        
        $today = Carbon::today()->format('Y-m-d');
        
        $query = Invoice::where('connection_id', $cron->connection_id)
            ->where('data_vencimento', '>', $today)
            ->where(function($q) {
                $q->whereNull('link_boleto')->orWhere('link_boleto', '');
            })
            ->where('status', '!=', 'PAID')
            ->where('status', '!=', 'BAIXADO');

        // Limite de dias (ex: vence nos próximos 5 dias)
        // Usamos days_before_due para definir "quão perto" do vencimento enviamos.
        // Se days_before_due = 3, enviamos quando faltam 3 dias.
        // Então Data Vencimento = targetDate + 3.
        
        $days = $cron->days_before_due ?? 3;
        $targetDueDate = $targetDate->copy()->addDays($days)->format('Y-m-d');
        
        // Aqui a lógica é pontual: Enviamos no dia X antes do vencimento.
        $query->whereDate('data_vencimento', $targetDueDate);

        $invoices = $query->get();

        foreach ($invoices as $invoice) {
            if (!$invoice->cliente) continue;
            
            // Verifica se já enviou HOJE (ou neste ciclo)
            // Para due_date, podemos enviar lembretes em dias diferentes (ex: 5 dias antes, 1 dia antes).
            // Mas para o MESMO cron id, enviamos uma vez para aquela data alvo.
            
            $this->createSchedule($cron, $invoice->cliente, $invoice, $invoice->data_vencimento, $targetDate);
        }
    }

    protected function scheduleBirthday(MessageCron $cron, Carbon $targetDate)
    {
        // Aniversário = targetDate
        $dayMonth = $targetDate->format('m-d');
        
        $clientes = Cliente::where('connection_id', $cron->connection_id)
            ->whereRaw("DATE_FORMAT(birthdate, '%m-%d') = ?", [$dayMonth])
            ->get();

        foreach ($clientes as $cliente) {
            $this->createSchedule($cron, $cliente, null, $targetDate, $targetDate);
        }
    }

    protected function scheduleBilling(MessageCron $cron, Carbon $targetDate)
    {
        // Regra complexa de cobrança (dias após vencimento)
        $daysLate = $cron->days_after_due ?? 0;
        $dueDateLimit = $targetDate->copy()->subDays($daysLate)->format('Y-m-d');
        
        // Billing service original também usa periodStart (period_value/unit) para limitar quão antigo buscar
        $periodStart = null;
        if ($cron->period_value && $cron->period_unit) {
             $d = $targetDate->copy();
             switch ($cron->period_unit) {
                case 'days': $d->subDays($cron->period_value); break;
                case 'months': $d->subMonths($cron->period_value); break;
                case 'years': $d->subYears($cron->period_value); break;
             }
             $periodStart = $d->format('Y-m-d');
        }

        $query = Invoice::where('connection_id', $cron->connection_id)
            ->where('data_vencimento', '<', $dueDateLimit)
            ->where(function ($q) {
                $q->whereNull('saldo_devedor')->orWhere('saldo_devedor', '>', 0);
            });
            
        if ($periodStart) {
            $query->where('data_vencimento', '>=', $periodStart);
        }

        $invoices = $query->get();
        
        // Agrupamento por cliente?
        // O MessageCronService agrupa por cliente. 
        // Na tela de envios futuros, mostramos por cliente.
        // Se um cliente tem 3 faturas atrasadas, ele recebe 1 mensagem.
        // Devemos criar 1 schedule com "vários invoices" ou 1 schedule principal?
        // Tabela tem `invoice_id` singular.
        // Vamos criar um schedule por cliente, e invoice_id pode ser null ou o primeiro.
        // Ou criamos multiplos schedules e o frontend agrupa?
        // O envio real agrupa.
        
        $grouped = $invoices->groupBy('cliente_id');
        
        foreach ($grouped as $clientId => $clientInvoices) {
            $cliente = $clientInvoices->first()->cliente;
            if (!$cliente) continue;

            $firstInvoice = $clientInvoices->first();
            
            // Check duplicidade (já enviado hoje)
            $this->createSchedule($cron, $cliente, $firstInvoice, $targetDate, $targetDate);
        }
    }
}
