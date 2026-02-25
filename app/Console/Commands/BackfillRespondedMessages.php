<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WhatsappMessageLog;
use Illuminate\Support\Facades\DB;

class BackfillRespondedMessages extends Command
{
    protected $signature = 'messages:backfill-responded';

    protected $description = 'Marca como respondidas mensagens que possuem registros em incoming_messages e ainda estão com responded=false.';

    public function handle()
    {
        $tz = config('app.timezone') ?: 'America/Sao_Paulo';
        $today = \Carbon\Carbon::today($tz);

        $logs = WhatsappMessageLog::whereNull('responded')->orWhere('responded', false)
            ->whereNotNull('phone_sanitized')
            ->whereDate('sent_at', '>=', $today->copy()->subDays(7)->toDateString())
            ->orderBy('sent_at', 'desc')
            ->limit(500)
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
                $log->responded_at = \Carbon\Carbon::parse($incoming->created_at, $tz);
                $log->save();
                $updated++;
            }
        }

        $this->info("Backfill concluído. Respondidas: {$updated}");

        return Command::SUCCESS;
    }
}
