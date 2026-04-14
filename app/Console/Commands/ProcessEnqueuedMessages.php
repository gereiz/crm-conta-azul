<?php

namespace App\Console\Commands;

use App\Models\WhatsappMessageLog;
use App\Services\HumanizedWhatsAppOrchestrator;
use Illuminate\Console\Command;

class ProcessEnqueuedMessages extends Command
{
    protected $signature = 'message:process-enqueued';

    protected $description = 'Reprocessa envios enfileirados (skipped/Enfileirado) quando o número estiver livre.';

    protected HumanizedWhatsAppOrchestrator $orchestrator;

    public function __construct(HumanizedWhatsAppOrchestrator $orchestrator)
    {
        parent::__construct();
        $this->orchestrator = $orchestrator;
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

    public function handle()
    {
        $result = \App\Services\CronMutexService::run('cron:message-enqueued', 0, 600, function () {
            $tz = config('app.timezone') ?: 'America/Sao_Paulo';
            $today = \Carbon\Carbon::today($tz)->toDateString();

            $logs = WhatsappMessageLog::where('status', 'skipped')
                ->where('error_message', 'like', '%Enfileirado%')
                ->whereDate('sent_at', $today)
                ->orderBy('sent_at', 'asc')
                ->limit(100)
                ->get();

            if ($logs->isEmpty()) {
                $this->info('Reprocessados 0 registros.');

                return Command::SUCCESS;
            }

            $successKeys = WhatsappMessageLog::query()
                ->where('status', 'success')
                ->whereDate('sent_at', $today)
                ->whereIn('whatsapp_number_id', $logs->pluck('whatsapp_number_id')->filter()->unique()->all())
                ->whereIn('phone_sanitized', $logs->pluck('phone_sanitized')->filter()->unique()->all())
                ->whereIn('message_type', $logs->pluck('message_type')->filter()->unique()->all())
                ->get(['whatsapp_number_id', 'phone_sanitized', 'message_type'])
                ->mapWithKeys(function ($item) {
                    $key = implode('|', [
                        (string) $item->whatsapp_number_id,
                        (string) $item->phone_sanitized,
                        (string) $item->message_type,
                    ]);

                    return [$key => true];
                });

            $processed = 0;
            foreach ($logs as $log) {
                $key = implode('|', [
                    (string) $log->whatsapp_number_id,
                    (string) $log->phone_sanitized,
                    (string) $log->message_type,
                ]);
                $recentSuccess = (bool) ($successKeys[$key] ?? false);
                if ($recentSuccess) {
                    continue;
                }

                $res = $this->orchestrator->sendOne(
                    (int) $log->whatsapp_number_id,
                    (string) $log->phone_sanitized,
                    (string) $log->content,
                    ['batch_id' => $log->batch_id ?: (string) \Illuminate\Support\Str::uuid()]
                );

                $log->status = ($res['queued'] ?? false) ? 'skipped' : (($res['success'] ?? false) ? 'success' : 'error');
                $log->error_message = ($res['queued'] ?? false)
                    ? ($res['message'] ?? 'Enfileirado')
                    : (($res['success'] ?? false) ? null : ($res['message'] ?? 'Erro desconhecido'));
                if (isset($res['meta'])) {
                    $meta = $res['meta'];
                    $log->provider_message_id = $meta['message_id'] ?? $log->provider_message_id;
                    $st = $meta['whapi_status'] ?? ($meta['evolution_status'] ?? null);
                    $log->delivery_status = $this->normalizeStatus($st);
                    $log->delivery_status_updated_at = $st ? now($tz) : $log->delivery_status_updated_at;
                }
                $log->save();

                if (($res['success'] ?? false) && ! ($res['queued'] ?? false)) {
                    $successKeys[$key] = true;
                }
                $processed++;
            }

            $this->info("Reprocessados {$processed} registros.");

            return Command::SUCCESS;
        });

        if ($result === null) {
            $this->info('Reprocessamento da fila ignorado: já existe outra execução ativa.');

            return Command::SUCCESS;
        }

        return $result ?? Command::SUCCESS;
    }
}
