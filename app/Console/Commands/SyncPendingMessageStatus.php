<?php

namespace App\Console\Commands;

use App\Models\WhatsappMessageLog;
use App\Models\WebhookEventLog;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncPendingMessageStatus extends Command
{
    protected $signature = 'messages:sync-status';

    protected $description = 'Sincroniza status de mensagens pendentes com provedores';

    public function handle()
    {
        $result = \App\Services\CronMutexService::run('cron:messages-sync-status', 0, 600, function () {
            $logs = WhatsappMessageLog::whereIn('delivery_status', ['PENDING', 'SENT'])
                ->whereNotNull('provider_message_id')
                ->orderBy('sent_at', 'desc')
                ->limit(200)
                ->get();

            $updated = 0;
            foreach ($logs as $log) {
                try {
                    // Atualiza com base nos eventos de webhook já registrados (status_atualizado)
                    $evt = WebhookEventLog::where(function ($q) use ($log) {
                            $q->where('matched_log_id', $log->id)
                              ->orWhere('provider_message_id', $log->provider_message_id)
                              ->orWhere('phone', preg_replace('/\D+/', '', (string) $log->phone_sanitized));
                        })
                        ->where('reason', 'like', 'status_atualizado:%')
                        ->orderBy('created_at', 'desc')
                        ->first();
                    if ($evt) {
                        $parts = explode(':', $evt->reason, 2);
                        $st = isset($parts[1]) ? strtoupper(trim($parts[1])) : null;
                        if ($st && $st !== $log->delivery_status) {
                            $log->delivery_status = $st;
                            $log->delivery_status_updated_at = Carbon::parse($evt->created_at);
                            $log->save();
                            $updated++;
                            continue;
                        }
                    }
                    // Heurística: se já houve respondida, promove para READ
                    if ($log->responded && in_array($log->delivery_status, ['PENDING','SENT','DELIVERED'], true)) {
                        $log->delivery_status = 'READ';
                        $log->delivery_status_updated_at = $log->responded_at ?? now();
                        $log->save();
                        $updated++;
                    }
                } catch (\Throwable $e) {
                    $this->error('Falha ao sincronizar status: '.$e->getMessage());
                }
            }

            $this->info("Processados {$updated} registros pendentes.");

            return Command::SUCCESS;
        });

        if ($result === null) {
            $this->info('Sincronização de status ignorada: já existe outra execução ativa.');

            return Command::SUCCESS;
        }

        return $result ?? Command::SUCCESS;
    }
}
