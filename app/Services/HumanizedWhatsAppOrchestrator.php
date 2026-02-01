<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\WhatsappNumber;
use App\Models\WhatsappSendState;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HumanizedWhatsAppOrchestrator
{
    protected WhatsAppProviderResolver $resolver;
    protected int $delayMin = 6;
    protected int $delayMax = 20;
    protected int $batchSize = 30;
    protected int $batchIntervalMin = 300;
    protected int $batchIntervalMax = 420;
    protected int $hourlyLimit = 100;
    protected string $safeStart = '08:00';
    protected string $safeEnd = '20:00';
    protected int $concurrentCooldownMinutes = 15;
    protected int $pauseOnErrorMinutes = 30;
    protected int $warmDay1 = 20;
    protected int $warmDay2 = 40;
    protected int $warmDay3 = 60;

    public function __construct(WhatsAppProviderResolver $resolver)
    {
        $this->resolver = $resolver;
        try {
            $s = SystemSetting::latest()->first();
            if ($s) {
                $this->delayMin = (int) ($s->orchestrator_delay_min_seconds ?? $this->delayMin);
                $this->delayMax = (int) ($s->orchestrator_delay_max_seconds ?? $this->delayMax);
                $this->batchSize = (int) ($s->orchestrator_batch_size ?? $this->batchSize);
                $this->batchIntervalMin = (int) ($s->orchestrator_batch_interval_min_seconds ?? $this->batchIntervalMin);
                $this->batchIntervalMax = (int) ($s->orchestrator_batch_interval_max_seconds ?? $this->batchIntervalMax);
                $this->hourlyLimit = (int) ($s->orchestrator_hourly_limit_per_number ?? $this->hourlyLimit);
                $this->safeStart = (string) ($s->orchestrator_safe_start_hour ?? $this->safeStart);
                $this->safeEnd = (string) ($s->orchestrator_safe_end_hour ?? $this->safeEnd);
                $this->concurrentCooldownMinutes = (int) ($s->orchestrator_concurrent_cooldown_minutes ?? $this->concurrentCooldownMinutes);
                $this->pauseOnErrorMinutes = (int) ($s->orchestrator_pause_on_error_minutes ?? $this->pauseOnErrorMinutes);
                $this->warmDay1 = (int) ($s->orchestrator_warmup_day1_limit ?? $this->warmDay1);
                $this->warmDay2 = (int) ($s->orchestrator_warmup_day2_limit ?? $this->warmDay2);
                $this->warmDay3 = (int) ($s->orchestrator_warmup_day3_limit ?? $this->warmDay3);
            }
        } catch (\Throwable $e) {
        }
    }

    protected function getState(int $numberId): WhatsappSendState
    {
        return WhatsappSendState::firstOrCreate([
            'whatsapp_number_id' => $numberId,
        ], [
            'warmup_start_date' => Carbon::today(),
        ]);
    }

    protected function inSafeWindow(): bool
    {
        $tz = config('app.timezone') ?: 'America/Sao_Paulo';
        $now = Carbon::now($tz);
        $startParts = explode(':', $this->safeStart);
        $endParts = explode(':', $this->safeEnd);
        $start = $now->copy()->setTime((int) ($startParts[0] ?? 8), (int) ($startParts[1] ?? 0));
        $end = $now->copy()->setTime((int) ($endParts[0] ?? 20), (int) ($endParts[1] ?? 0));

        return $now->between($start, $end);
    }

    protected function allowedByWarmup(WhatsappSendState $state): int
    {
        $start = $state->warmup_start_date ? Carbon::parse($state->warmup_start_date) : Carbon::today();
        $days = max(1, Carbon::today()->diffInDays($start) + 1);
        if ($days <= 1) return $this->warmDay1;
        if ($days === 2) return $this->warmDay2;
        if ($days === 3) return $this->warmDay3;

        return $this->hourlyLimit; // após aquecimento, usar o limite/hora
    }

    protected function canStart(int $numberId): array
    {
        $state = $this->getState($numberId);
        $now = Carbon::now();

        if ($state->paused_until && Carbon::parse($state->paused_until)->gt($now)) {
            return ['ok' => false, 'reason' => 'paused', 'until' => $state->paused_until];
        }

        if (! $this->inSafeWindow()) {
            $until = Carbon::today()->setTime(8, 0);
            if ($until->lt($now)) {
                $until = Carbon::tomorrow()->setTime(8, 0);
            }

            return ['ok' => false, 'reason' => 'safe_hours', 'until' => $until];
        }

        // Limite por hora
        if (! $state->hourly_window_start || Carbon::parse($state->hourly_window_start)->diffInHours($now) >= 1) {
            $state->hourly_window_start = $now;
            $state->hourly_count = 0;
            $state->save();
        }
        if ($state->hourly_count >= $this->hourlyLimit) {
            $until = Carbon::parse($state->hourly_window_start)->addHour();

            return ['ok' => false, 'reason' => 'rate_limit', 'until' => $until];
        }

        // Limite de aquecimento diário
        if (! $state->daily_date || Carbon::parse($state->daily_date)->ne(Carbon::today())) {
            $state->daily_date = Carbon::today();
            $state->daily_count = 0;
            $state->save();
        }
        $warmupLimit = $this->allowedByWarmup($state);
        if ($state->daily_count >= $warmupLimit) {
            $until = Carbon::tomorrow()->setTime(8, 0);

            return ['ok' => false, 'reason' => 'warmup_daily_cap', 'until' => $until];
        }

        // Evitar concorrência — não permitir overlap; respeitar 15m após finalização anterior
        if ($state->in_progress) {
            $until = $state->last_finished_at ? Carbon::parse($state->last_finished_at)->addMinutes($this->concurrentCooldownMinutes) : $now->addMinutes($this->concurrentCooldownMinutes);
            if ($until->gt($now)) {
                return ['ok' => false, 'reason' => 'concurrent', 'until' => $until];
            }
        }

        return ['ok' => true, 'state' => $state];
    }

    public function sendOne(int $whatsappNumberId, string $to, string $message, array $context = [])
    {
        $check = $this->canStart($whatsappNumberId);
        if (! ($check['ok'] ?? false)) {
            return [
                'success' => false,
                'message' => $this->reasonMessage($check['reason'] ?? 'paused', $check['until'] ?? null),
                'queued' => true,
            ];
        }
        /** @var WhatsappSendState $state */
        $state = $check['state'];

        // Lock otimista via update condicional
        $locked = DB::table('whatsapp_send_states')
            ->where('whatsapp_number_id', $whatsappNumberId)
            ->where('in_progress', false)
            ->update([
                'in_progress' => true,
                'last_started_at' => Carbon::now(),
            ]);
        if ($locked === 0 && ! $state->in_progress) {
            return [
                'success' => false,
                'message' => 'Outra execução está em andamento. Tente novamente em alguns minutos.',
                'queued' => true,
            ];
        }

        try {
            $delay = random_int($this->delayMin, max($this->delayMin, $this->delayMax));
            sleep($delay);

            $number = WhatsappNumber::find($whatsappNumberId);
            $provider = $this->resolver->resolve($number);
            $res = $provider->sendMessage($whatsappNumberId, $to, $message);

            // Atualiza contadores
            $state->refresh();
            $state->hourly_count = ($state->hourly_count ?? 0) + 1;
            $state->daily_count = ($state->daily_count ?? 0) + 1;
            $state->save();

            // Pausa automática em erros sensíveis
            if (! ($res['success'] ?? false)) {
                $msg = strtolower((string) ($res['message'] ?? ''));
                if (str_contains($msg, 'block') || str_contains($msg, 'bloque') || str_contains($msg, 'rate') || str_contains($msg, 'limit') || str_contains($msg, 'restrit')) {
                    $state->paused_until = Carbon::now()->addMinutes($this->pauseOnErrorMinutes);
                    $state->save();
                }
            }

            // Libera lock
            DB::table('whatsapp_send_states')
                ->where('whatsapp_number_id', $whatsappNumberId)
                ->update([
                    'in_progress' => false,
                    'last_finished_at' => Carbon::now(),
                ]);

            // Anexa metadados de delay/batch
            $meta = $res['meta'] ?? [];
            $meta['humanized_delay_seconds'] = $delay;
            $meta['batch_id'] = $context['batch_id'] ?? (string) Str::uuid();
            $res['meta'] = $meta;

            return $res;
        } catch (\Throwable $e) {
            DB::table('whatsapp_send_states')
                ->where('whatsapp_number_id', $whatsappNumberId)
                ->update([
                    'in_progress' => false,
                    'last_finished_at' => Carbon::now(),
                ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function sendBatch(int $whatsappNumberId, array $items, array $context = [])
    {
        $results = [];
        $batchId = $context['batch_id'] ?? (string) Str::uuid();
        $chunks = array_chunk($items, $this->batchSize);
        foreach ($chunks as $i => $chunk) {
            foreach ($chunk as $payload) {
                $res = $this->sendOne($whatsappNumberId, $payload['to'], $payload['message'], ['batch_id' => $batchId]);
                $results[] = $res;
            }
            if ($i < count($chunks) - 1) {
                $min = min($this->batchIntervalMin, $this->batchIntervalMax);
                $max = max($this->batchIntervalMin, $this->batchIntervalMax);
                sleep(random_int($min, $max));
            }
        }

        return $results;
    }

    protected function reasonMessage(string $reason, $until): string
    {
        $untilStr = $until ? Carbon::parse($until)->format('H:i') : null;
        return match ($reason) {
            'paused' => "Envios pausados temporariamente até {$untilStr}.",
            'safe_hours' => "Fora do horário seguro (08:00–20:00). Enfileirado para {$untilStr}.",
            'rate_limit' => "Limite horário atingido. Retoma às {$untilStr}.",
            'warmup_daily_cap' => "Limite diário de aquecimento atingido. Retoma às {$untilStr}.",
            'concurrent' => "Outro envio está em andamento. Próxima janela às {$untilStr}.",
            default => "Aguardando próxima janela segura.",
        };
    }
}
