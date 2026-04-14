<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WhatsappMessageLog;
use Illuminate\Support\Facades\DB;
use App\Models\WebhookEventLog;
use Carbon\Carbon;

class BackfillRespondedMessages extends Command
{
    protected $signature = 'messages:backfill-responded';

    protected $description = 'Marca como respondidas mensagens que possuem registros em incoming_messages e ainda estão com responded=false.';

    public function handle()
    {
        $result = \App\Services\CronMutexService::run('cron:messages-backfill', 0, 900, function () {
            $tz = config('app.timezone') ?: 'America/Sao_Paulo';
            $today = Carbon::today($tz);

            $logs = WhatsappMessageLog::where(function ($q) {
                    $q->whereNull('responded')->orWhere('responded', false);
                })
                ->whereNotNull('phone_sanitized')
                ->whereDate('sent_at', '>=', $today->copy()->subDays(7)->toDateString())
                ->orderBy('sent_at', 'desc')
                ->limit(200)
                ->get();

            $updated = 0;

            foreach ($logs as $log) {
                $phone = preg_replace('/\D+/', '', (string) $log->phone_sanitized);
                if (! $phone) {
                    continue;
                }
                $incoming = DB::table('incoming_messages')
                    ->where('numero_origem', $phone)
                    ->where('created_at', '>=', $log->sent_at ?? $today->copy()->subDays(7))
                    ->orderBy('created_at', 'desc')
                    ->first();
                if ($incoming) {
                    $log->responded = true;
                    $log->responded_at = Carbon::parse($incoming->created_at, $tz);
                    $log->save();
                    $updated++;
                }

                if (! $log->responded) {
                    $evt = WebhookEventLog::where(function ($q) use ($log, $phone) {
                            $q->where('matched_log_id', $log->id)
                              ->orWhere('provider_message_id', $log->provider_message_id)
                              ->orWhere('phone', $phone);
                        })
                        ->whereIn('reason', ['respondida_ok', 'respondida_fallback_dia'])
                        ->where('created_at', '>=', $log->sent_at ?? $today->copy()->subDays(7))
                        ->orderBy('created_at', 'desc')
                        ->first();
                    if ($evt) {
                        $log->responded = true;
                        $log->responded_at = Carbon::parse($evt->created_at, $tz);
                        $log->save();
                        $updated++;
                    }
                }

                $statusEvt = WebhookEventLog::where(function ($q) use ($log, $phone) {
                        $q->where('matched_log_id', $log->id)
                          ->orWhere('provider_message_id', $log->provider_message_id)
                          ->orWhere('phone', $phone);
                    })
                    ->where('reason', 'like', 'status_atualizado:%')
                    ->where('created_at', '>=', $log->sent_at ?? $today->copy()->subDays(7))
                    ->orderBy('created_at', 'desc')
                    ->first();
                if ($statusEvt) {
                    $parts = explode(':', $statusEvt->reason, 2);
                    $st = isset($parts[1]) ? strtoupper(trim($parts[1])) : null;
                    if ($st) {
                        $log->delivery_status = $st;
                        $log->delivery_status_updated_at = Carbon::parse($statusEvt->created_at, $tz);
                        $log->save();
                    }
                }
            }

            $this->info("Backfill concluído. Respondidas: {$updated}");

            return Command::SUCCESS;
        });

        if ($result === null) {
            $this->info('Backfill ignorado: já existe outra execução ativa.');

            return Command::SUCCESS;
        }

        return $result ?? Command::SUCCESS;
    }
}
