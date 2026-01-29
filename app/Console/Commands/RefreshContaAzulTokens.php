<?php

namespace App\Console\Commands;

use App\Models\ContaAzulConnection;
use App\Services\ContaAzulAuthService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshContaAzulTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'contaazul:refresh-tokens';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verifica e renova tokens de acesso Conta Azul que estão próximos de expirar.';

    protected ContaAzulAuthService $auth;

    public function __construct(ContaAzulAuthService $auth)
    {
        parent::__construct();
        $this->auth = $auth;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Iniciando verificação de tokens Conta Azul...');

        // Busca conexões ativas que têm refresh_token
        // e cujo token expira nos próximos 20 minutos (margem de segurança)
        // ou já expirou.
        $connections = ContaAzulConnection::where('is_active', true)
            ->whereNotNull('refresh_token')
            ->where(function ($query) {
                $query->whereNull('token_expires_at')
                    ->orWhere('token_expires_at', '<=', Carbon::now()->addMinutes(20));
            })
            ->get();

        if ($connections->isEmpty()) {
            $this->info('Nenhum token precisa ser renovado no momento.');

            return Command::SUCCESS;
        }

        $this->info("Encontradas {$connections->count()} conexões para renovação.");

        foreach ($connections as $connection) {
            $this->info("Renovando token para: {$connection->empresa_nome} (ID: {$connection->id})");

            try {
                $newToken = $this->auth->refreshToken($connection);

                if ($newToken) {
                    $this->info('  [OK] Token renovado com sucesso.');
                    Log::info("Cron: Token renovado para conexão {$connection->id} ({$connection->empresa_nome})");
                } else {
                    $this->error('  [ERRO] Falha ao renovar token. O refresh token pode ter expirado.');
                    Log::warning("Cron: Falha na renovação de token para conexão {$connection->id}");
                }
            } catch (\Exception $e) {
                $this->error('  [EXCEPTION] '.$e->getMessage());
                Log::error("Cron: Erro ao renovar token conexão {$connection->id}: ".$e->getMessage());
            }
        }

        $this->info('Processo de renovação concluído.');

        return Command::SUCCESS;
    }
}
