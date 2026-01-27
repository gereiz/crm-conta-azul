<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\MessageCron;
use App\Models\DelayedCronQueue;
use App\Models\WhatsappMessageLog;
use App\Services\MessageCronService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProcessDelayedCrons extends Command
{
    protected $signature = 'message:process-delayed-crons {--force}';
    protected $description = 'Cria fila e processa automações atrasadas, com intervalo mínimo de 15 minutos entre cada execução.';

    public function handle(MessageCronService $service)
    {
        $now = Carbon::now();
        $this->info("Verificando automações atrasadas em {$now}...");

        // 1) Descobrir crons elegíveis para catch-up
        $crons = MessageCron::where('is_active', true)
            ->where('run_when_delayed', true)
            ->get();

        foreach ($crons as $cron) {
            $expected = $this->lastExpectedRunAt($cron, $now);
            if (!$expected) {
                continue;
            }
            // Se a última execução efetiva é anterior ao esperado, e não há log na data esperada, enfileira
            $lastRun = $cron->last_run_at;
            if ($lastRun && $lastRun->gte($expected)) {
                continue;
            }

            $alreadyLogged = WhatsappMessageLog::where('message_cron_id', $cron->id)
                ->whereDate('sent_at', $expected->toDateString())
                ->exists();
            if ($alreadyLogged) {
                continue;
            }

            // Evita duplicar na fila
            $existsInQueue = DelayedCronQueue::where('message_cron_id', $cron->id)
                ->whereDate('expected_run_at', $expected->toDateString())
                ->whereNull('processed_at')
                ->exists();
            if (!$existsInQueue) {
                DelayedCronQueue::create([
                    'message_cron_id' => $cron->id,
                    'expected_run_at' => $expected,
                ]);
                $this->info("Enfileirada automação atrasada: {$cron->name} (ID {$cron->id}) para {$expected}");
            }
        }

        // 2) Respeitar intervalo mínimo de 15 minutos entre execuções
        $force = (bool) $this->option('force');
        if (! $force) {
            $lastProcessed = DelayedCronQueue::whereNotNull('processed_at')
                ->orderBy('processed_at', 'desc')
                ->first();
            if ($lastProcessed && $lastProcessed->processed_at->gt($now->subMinutes(15))) {
                $this->info("Aguardando janela de 15 minutos para próxima execução atrasada.");
                return 0;
            }
        }

        // 3) Processar uma fila por vez
        $next = DelayedCronQueue::whereNull('processed_at')
            ->orderBy('expected_run_at', 'asc')
            ->first();
        if (!$next) {
            $this->info("Nenhuma automação atrasada pendente.");
            return 0;
        }

        $cron = MessageCron::find($next->message_cron_id);
        if (!$cron) {
            $this->warn("Cron ID {$next->message_cron_id} não encontrado. Removendo da fila.");
            $next->delete();
            return 0;
        }

        $this->info("Executando automação atrasada: {$cron->name} (ID {$cron->id})");
        $stats = $service->processCron($cron);
        $this->info("Resultado: " . json_encode($stats));

        $next->processed_at = Carbon::now();
        $next->save();

        return 0;
    }

    protected function lastExpectedRunAt(MessageCron $cron, Carbon $now): ?Carbon
    {
        // Baseia-se nos campos rule_type e send_time do próprio cron
        [$hour, $minute] = explode(':', $cron->send_time ?? '00:00');

        switch ($cron->rule_type) {
            case 'daily':
                $candidate = $now->copy()->setTime((int)$hour, (int)$minute, 0);
                if ($cron->exclude_weekends && ($candidate->isSaturday() || $candidate->isSunday())) {
                    $candidate->subDay(); // recua um dia e confere novamente
                    if ($candidate->isSaturday() || $candidate->isSunday()) {
                        $candidate = $candidate->subDay();
                    }
                }
                if ($candidate->lte($now)) return $candidate;
                return $candidate->subDay();

            case 'weekly_day':
                $days = is_array($cron->day_of_week) ? $cron->day_of_week : [(int)$cron->day_of_week];
                if (empty($days)) return null;
                // Procura o último dia da semana permitido antes de agora
                for ($i = 0; $i < 7; $i++) {
                    $candidate = $now->copy()->subDays($i)->setTime((int)$hour, (int)$minute, 0);
                    if ($cron->exclude_weekends && ($candidate->isSaturday() || $candidate->isSunday())) {
                        continue;
                    }
                    if (in_array($candidate->dayOfWeek, $days) && $candidate->lte($now)) {
                        return $candidate;
                    }
                }
                return null;

            case 'monthly_day':
                $days = is_array($cron->day_of_month) ? $cron->day_of_month : [(int)$cron->day_of_month];
                if (empty($days)) return null;
                // Verifica mês atual e anterior
                foreach ([$now->copy(), $now->copy()->subMonth()] as $ref) {
                    foreach ($days as $d) {
                        $d = (int)$d;
                        $day = min($d, $ref->copy()->endOfMonth()->day);
                        $candidate = $ref->copy()->day($day)->setTime((int)$hour, (int)$minute, 0);
                        if ($cron->exclude_weekends && ($candidate->isSaturday() || $candidate->isSunday())) {
                            continue;
                        }
                        if ($candidate->lte($now)) {
                            return $candidate;
                        }
                    }
                }
                return null;

            case 'interval_days':
                if (!$cron->last_run_at) {
                    // Nunca rodou; considera agora - intervalo como esperado
                    return $now->copy()->subDays((int)($cron->interval_days ?? 1))->setTime((int)$hour, (int)$minute, 0);
                }
                $candidate = $cron->last_run_at->copy()->addDays((int)($cron->interval_days ?? 1))->setTime((int)$hour, (int)$minute, 0);
                return $candidate->lte($now) ? $candidate : null;

            default:
                // Sem regra: assume diário
                $candidate = $now->copy()->setTime((int)$hour, (int)$minute, 0);
                return $candidate->lte($now) ? $candidate : $candidate->subDay();
        }
    }
}
