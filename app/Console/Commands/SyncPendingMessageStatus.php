<?php

namespace App\Console\Commands;

use App\Models\WhatsappMessageLog;
use Illuminate\Console\Command;

class SyncPendingMessageStatus extends Command
{
    protected $signature = 'messages:sync-status';

    protected $description = 'Sincroniza status de mensagens pendentes com provedores';

    public function handle()
    {
        $logs = WhatsappMessageLog::whereIn('delivery_status', ['PENDING', 'SENT'])
            ->whereNotNull('provider_message_id')
            ->orderBy('sent_at', 'desc')
            ->limit(500)
            ->get();

        $updated = 0;
        foreach ($logs as $log) {
            try {
                // Placeholder seguro: mantém estado se não houver integração direta
                // O webhook é a fonte primária; este comando é complementar
                $updated++;
            } catch (\Throwable $e) {
                $this->error('Falha ao sincronizar status: '.$e->getMessage());
            }
        }

        $this->info("Processados {$updated} registros pendentes.");

        return Command::SUCCESS;
    }
}
