<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\CompanyCronRule;
use App\Models\ContaAzulConnection;
use App\Models\FutureMessageSchedule;
use App\Models\Invoice;
use App\Models\MessageCron;
use App\Models\WhatsappMessageLog;
use Carbon\Carbon;

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
            ->whereNotIn('status', ['sent', 'error']) // Preserva histórico de execução de hoje
            ->delete();

        // Busca crons específicos da conexão OU globais
        $crons = MessageCron::where(function ($q) use ($connection) {
            $q->where('connection_id', $connection->id)
                ->orWhereNull('connection_id');
        })
            ->where('is_active', true)
            ->get();

        foreach ($crons as $cron) {
            // Se for cron global, precisamos garantir que ele se aplica a esta conexão?
            // Crons globais se aplicam a todas as conexões ativas.
            // Passamos um objeto cron "temporário" com o ID da conexão injetado para o contexto?
            // Não, o método calculateForCron usa $cron->connection_id para queries.
            // Se $cron->connection_id for null, as queries dentro de calculateForCron vão falhar
            // ou buscar invoices sem connection_id (o que não existe).

            // Solução: Clonar o cron e injetar o connection_id atual para o contexto da busca
            $cronContext = $cron->replicate();
            $cronContext->id = $cron->id; // Mantém ID original para logs/referência
            $cronContext->type = $cron->type;
            $cronContext->connection_id = $connection->id; // Força o ID da conexão atual
            // Copia outras configs relevantes
            $cronContext->days_before_due = $cron->days_before_due;
            $cronContext->days_after_due = $cron->days_after_due;
            $cronContext->period_value = $cron->period_value;
            $cronContext->period_unit = $cron->period_unit;
            $cronContext->message_template_id = $cron->message_template_id;

            $this->calculateForCron($cronContext);
        }
    }

    public function calculateForCron(MessageCron $cron)
    {
        // Calcula para hoje e próximos dias (ex: 3 dias de visibilidade)
        $horizon = 3;

        for ($i = 0; $i <= $horizon; $i++) {
            $targetDate = Carbon::today()->addDays($i);

            // Verifica se a regra da empresa permite envio neste dia
            if (! $this->isDayAllowed($cron, $targetDate)) {
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

        if (! $rule) {
            $rule = CompanyCronRule::whereNull('conta_azul_connection_id')
                ->where('message_type', $cron->type)
                ->where('is_active', true)
                ->first();
        }

        if (! $rule) {
            return true;
        } // Sem regra, permite tudo (ou default?) Assumindo true.

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
            ->when($invoice, fn ($q) => $q->where('invoice_id', $invoice->id))
            ->exists();

        if ($exists) {
            return;
        }

        // Verifica restrições
        $status = 'pending';
        if (! $blockReason) {
            $isBlocked = $this->restrictionService->isBlocked($cron->connection_id, [
                'cliente_nome' => $cliente->name,
                'cliente_ca_id' => $cliente->ca_id,
                'invoice_ca_id' => $invoice?->ca_id,
                'descricao' => $invoice?->descricao,
            ]);

            if ($isBlocked) {
                $status = 'blocked';
                $blockReason = 'Restrição de envio configurada';
            }
        } else {
            $status = 'blocked'; // Ou ignored?
        }

        if (in_array($cron->type, ['billing', 'due_date', 'boleto']) && $sendDate->isToday()) {
            $alreadySent = WhatsappMessageLog::where('cliente_id', $cliente->id)
                ->where('message_type', $cron->type)
                ->where('status', 'success')
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

        $today = Carbon::today()->format('Y-m-d');

        // Busca faturas com vencimento > hoje e com link_boleto preenchido
        $query = Invoice::where('connection_id', $cron->connection_id)
            ->where('data_vencimento', '>', $today)
            ->whereNotNull('link_boleto')
            ->where('link_boleto', '!=', '')
            ->where('saldo_devedor', '>', 0)
            ->where(function ($q) {
                $q->whereIn('status', ['PENDING', 'ABERTO'])
                    ->orWhereNull('status');
            });

        // Se o cron tiver configuração de dias (period_value), usamos como filtro de vencimento?
        // Ex: Vencimento nos próximos 30 dias.
        // Se period_value for definido, usamos. Se não, pegamos todas futuras (cuidado com volume).
        // Vamos limitar a 30 dias por segurança/padrão se não houver config.
        $days = (int) ($cron->period_value ?? 30);
        $limitDate = Carbon::today()->addDays($days)->format('Y-m-d');

        $query->where('data_vencimento', '<=', $limitDate);

        // AQUI: Agendar para a data de vencimento da fatura, não para hoje
        // Mas o sistema precisa enviar HOJE (targetDate) se a fatura vence no futuro?
        // Se for "Aviso de Emissão", enviamos assim que possível.
        // Se a fatura vence em 25/02 e hoje é 26/01.
        // Se agendarmos para 26/01 (hoje), aparecerá na lista de hoje.
        // Se agendarmos para 25/02, só aparecerá em fevereiro.
        // O conceito de "Emissão" é avisar "Seu boleto está disponível".
        // Então deve ser agendado para o targetDate (dia de execução da automação).

        $invoices = $query->get();

        foreach ($invoices as $invoice) {
            if (! $invoice->cliente) {
                continue;
            }

            // Verifica se já enviou mensagem de EMISSÃO para esta fatura
            $alreadySent = WhatsappMessageLog::where('message_cron_id', $cron->id)
                ->when($invoice->ca_id, function ($q) use ($invoice) {
                    // boleto_ids é JSON; checamos se contém o ca_id da fatura
                    $q->whereJsonContains('boleto_ids', $invoice->ca_id)
                        ->orWhereJsonContains('boleto_ids', (string) $invoice->ca_id);
                }, function ($q) use ($invoice) {
                    // Fallback: sem ca_id, evita duplicar pelo cliente e tipo 'boleto'
                    $q->where('cliente_id', $invoice->cliente_id)
                        ->where('message_type', 'boleto');
                })
                ->exists();

            if ($alreadySent) {
                continue;
            }

            // Agenda para o targetDate (dia da execução da regra)
            $this->createSchedule($cron, $invoice->cliente, $invoice, $invoice->data_vencimento, $targetDate);
        }
    }

    protected function scheduleDueDate(MessageCron $cron, Carbon $targetDate)
    {
        // NOVA REGRA: Vencimento = Faturas com vencimento futuro (> hoje) E SEM link_boleto.
        // Isso serve como aviso de vencimento / lembrete de pagamento, mesmo sem o boleto gerado ainda na API.

        $today = Carbon::today()->format('Y-m-d');

        $query = Invoice::where('connection_id', $cron->connection_id)
            ->where('data_vencimento', '>', $today)
            ->where(function ($q) {
                $q->whereNull('link_boleto')->orWhere('link_boleto', '');
            })
            ->where('saldo_devedor', '>', 0)
            ->where(function ($q) {
                $q->whereIn('status', ['PENDING', 'ABERTO'])
                    ->orWhereNull('status');
            });

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
            if (! $invoice->cliente) {
                continue;
            }

            // Verifica se já enviou HOJE (ou neste ciclo)
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
        $daysLate = max(0, (int) ($cron->days_after_due ?? 0));
        $dueDateLimit = $targetDate->copy()->subDays($daysLate)->format('Y-m-d');
        $todayDate = \Carbon\Carbon::today()->format('Y-m-d');

        // Billing service original também usa periodStart (period_value/unit) para limitar quão antigo buscar
        $periodStart = null;
        if ($cron->period_value && $cron->period_unit) {
            $d = $targetDate->copy();
            switch ($cron->period_unit) {
                case 'days': $d->subDays($cron->period_value);
                    break;
                case 'months': $d->subMonths($cron->period_value);
                    break;
                case 'years': $d->subYears($cron->period_value);
                    break;
            }
            $periodStart = $d->format('Y-m-d');
        }

        $query = Invoice::where('connection_id', $cron->connection_id)
            ->where('data_vencimento', '<', $dueDateLimit)
            ->where('data_vencimento', '<', $todayDate)
            ->where('saldo_devedor', '>', 0)
            ->whereNotIn('status', ['PAID', 'PAGO', 'BAIXADO', 'LIQUIDADO', 'CANCELLED', 'CANCELADO', 'PENDING', 'ABERTO']);

        if ($periodStart) {
            $query->where('data_vencimento', '>=', $periodStart);
        }
        // Somente boletos para cobranças (flexível):
        // Considera como boleto quando payment_type LIKE BOLETO OU quando há link_boleto preenchido
        $query->where(function ($q) {
            $q->where(function ($qq) {
                $qq->whereNotNull('payment_type')
                    ->where('payment_type', 'LIKE', '%BOLETO%');
            })->orWhere(function ($qq) {
                $qq->whereNotNull('link_boleto')->where('link_boleto', '!=', '');
            });
        });

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
            if (! $cliente) {
                continue;
            }

            $firstInvoice = $clientInvoices->first();
            $clientBlocked = $this->restrictionService->isBlocked($cron->connection_id, [
                'cliente_nome' => $cliente->name,
                'cliente_ca_id' => $cliente->ca_id,
                'invoice_ca_id' => null,
                'descricao' => '',
            ]);
            $this->createSchedule($cron, $cliente, $firstInvoice, $targetDate, $targetDate, $clientBlocked ? 'Restrição de envio configurada (cliente)' : null);
        }
    }
}
