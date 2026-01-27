<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\ContaAzulConnection;
use App\Models\MessageCron;
use App\Models\SystemSetting;
use App\Services\ContaAzulApiService;
use App\Services\ContaAzulAuthService;
use App\Services\ContaAzulService;
use App\Services\MessageCronService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class SettingsController extends Controller
{
    protected $contaAzulService;

    protected $contaAzulAuthService;

    protected $contaAzulApiService;

    protected $futureMessageService;

    public function __construct(
        ContaAzulService $contaAzulService, 
        ContaAzulAuthService $contaAzulAuthService, 
        ContaAzulApiService $contaAzulApiService,
        \App\Services\FutureMessageService $futureMessageService
    )
    {
        $this->contaAzulService = $contaAzulService;
        $this->contaAzulAuthService = $contaAzulAuthService;
        $this->contaAzulApiService = $contaAzulApiService;
        $this->futureMessageService = $futureMessageService;
    }

    public function runArtisanCommand(Request $request)
    {
        $command = $request->input('command');
        $allowedCommands = [
            'message:calculate-future' => 'Cálculo de Envios Futuros',
            'contaazul:sync-stale' => 'Sincronização de Dados Obsoletos',
            'contaazul:refresh-tokens' => 'Renovação de Tokens',
        ];

        if (!array_key_exists($command, $allowedCommands)) {
            return response()->json(['error' => 'Comando não permitido.'], 403);
        }

        try {
            \Illuminate\Support\Facades\Artisan::call($command);
            $output = \Illuminate\Support\Facades\Artisan::output();
            return response()->json(['success' => true, 'output' => $output]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao executar comando: ' . $e->getMessage()], 500);
        }
    }

    public function system()
    {
        $settings = SystemSetting::latest()->first();

        return Inertia::render('Settings/System', [
            'settings' => $settings,
        ]);
    }

    public function systemJson()
    {
        $settings = SystemSetting::latest()->first();

        return response()->json([
            'system_name' => $settings->system_name ?? 'IbitWeb',
            'primary_color' => $settings->primary_color ?? '#6366F1',
            'secondary_color' => $settings->secondary_color ?? '#22C55E',
            'logo' => $settings->logo_path ?? null,
            'favicon' => $settings->favicon_path ?? null,
        ]);
    }

    public function contaAzulCronStatus()
    {
        $settings = SystemSetting::latest()->first();
        $enabled = $settings ? (bool) ($settings->contaazul_cron_enabled ?? true) : true;
        return response()->json(['enabled' => $enabled]);
    }

    public function contaAzulCronToggle(Request $request)
    {
        if (! $request->user() || ($request->user()->role ?? '') !== 'admin') {
            return response()->json(['error' => 'Acesso negado'], 403);
        }
        $enabled = filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOLEAN);
        $settings = SystemSetting::latest()->first() ?? new SystemSetting();
        $settings->system_name = $settings->system_name ?? 'IbitWeb';
        $settings->primary_color = $settings->primary_color ?? '#6366F1';
        $settings->secondary_color = $settings->secondary_color ?? '#22C55E';
        $settings->contaazul_cron_enabled = $enabled;
        $settings->save();
        return response()->json(['success' => true, 'enabled' => $enabled]);
    }

    public function delayedCronStatus()
    {
        $now = Carbon::now();
        $today = $now->toDateString();
        $pendingQuery = MessageCron::where('is_active', true)
            ->where('run_when_delayed', true)
            ->where('send_time', '<=', $now->format('H:i'))
            ->where(function ($q) use ($today) {
                $q->whereNull('last_run_at')->orWhereDate('last_run_at', '<', $today);
            });
        $pendingCount = $pendingQuery->count();
        $next = $pendingQuery->orderBy('send_time', 'asc')->first();
        $nextExpectedRunAt = $next ? Carbon::parse($today . ' ' . $next->send_time)->toDateTimeString() : null;
        $lastProcessedAt = MessageCron::whereNotNull('last_run_at')->max('last_run_at');
        return response()->json([
            'pending_count' => $pendingCount,
            'next_expected_run_at' => $nextExpectedRunAt,
            'last_processed_at' => $lastProcessedAt ? Carbon::parse($lastProcessedAt)->toDateTimeString() : null,
        ]);
    }

    public function processNextDelayedCron(MessageCronService $service)
    {
        $now = Carbon::now();
        $today = $now->toDateString();
        $cron = MessageCron::where('is_active', true)
            ->where('run_when_delayed', true)
            ->where('send_time', '<=', $now->format('H:i'))
            ->where(function ($q) use ($today) {
                $q->whereNull('last_run_at')->orWhereDate('last_run_at', '<', $today);
            })
            ->orderBy('send_time', 'asc')
            ->first();
        if (! $cron) {
            return response()->json(['success' => false, 'error' => 'Nenhuma automação atrasada para processar.']);
        }
        $service->processCron($cron, true);
        return response()->json(['success' => true, 'cron_id' => $cron->id]);
    }

    public function systemSave(Request $request)
    {
        $data = $request->validate([
            'system_name' => 'nullable|string|max:50',
            'primary_color' => 'nullable|string|max:20',
            'secondary_color' => 'nullable|string|max:20',
            'logo' => 'nullable|image|max:2048',
            'favicon' => 'nullable|image|max:1024',
        ]);

        $settings = SystemSetting::latest()->first() ?? new SystemSetting;
        $settings->system_name = $request->input('system_name');
        $settings->primary_color = $request->input('primary_color', '#6366F1');
        $settings->secondary_color = $request->input('secondary_color', '#22C55E');

        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            if ($file->isValid()) {
                try {
                    $filename = \Illuminate\Support\Str::uuid().'.'.$file->getClientOriginalExtension();
                    $path = 'branding/'.$filename;

                    // Garante que temos um caminho válido para leitura
                    $sourcePath = $file->getRealPath() ?: $file->getPathname();
                    if (! $sourcePath) {
                        throw new \Exception('Caminho temporário do arquivo vazio.');
                    }

                    Storage::disk('public')->put($path, file_get_contents($sourcePath));
                    $settings->logo_path = $path;
                } catch (\Exception $e) {
                    Log::error('Logo upload failed: '.$e->getMessage());

                    return redirect()->back()->withErrors(['logo' => 'Erro ao processar o arquivo. Tente novamente.']);
                }
            } else {
                Log::error('Logo upload failed: '.$file->getErrorMessage());

                return redirect()->back()->withErrors(['logo' => 'Erro no upload do logo. Tente um arquivo menor ou diferente.']);
            }
        }

        if ($request->hasFile('favicon')) {
            $file = $request->file('favicon');
            if ($file->isValid()) {
                try {
                    $filename = \Illuminate\Support\Str::uuid().'.'.$file->getClientOriginalExtension();
                    $path = 'branding/'.$filename;

                    $sourcePath = $file->getRealPath() ?: $file->getPathname();
                    if (! $sourcePath) {
                        throw new \Exception('Caminho temporário do arquivo vazio.');
                    }

                    Storage::disk('public')->put($path, file_get_contents($sourcePath));
                    $settings->favicon_path = $path;
                } catch (\Exception $e) {
                    Log::error('Favicon upload failed: '.$e->getMessage());

                    return redirect()->back()->withErrors(['favicon' => 'Erro ao processar o arquivo. Tente novamente.']);
                }
            } else {
                Log::error('Favicon upload failed: '.$file->getErrorMessage());

                return redirect()->back()->withErrors(['favicon' => 'Erro no upload do favicon. Tente um arquivo menor ou diferente.']);
            }
        }

        if (isset($data['primary_color'])) {
            $settings->primary_color = $data['primary_color'];
        }
        if (isset($data['secondary_color'])) {
            $settings->secondary_color = $data['secondary_color'];
        }

        $settings->save();

        return redirect()->back()->with('success', 'Configurações atualizadas com sucesso.');
    }

    public function index()
    {
        $connections = ContaAzulConnection::orderBy('empresa_nome')->get();
        if ($connections->isEmpty()) {
            $clientId = trim(config('services.contaazul.client_id'));
            $clientSecret = trim(config('services.contaazul.client_secret'));
            $redirectUri = trim(config('services.contaazul.redirect_uri'));
            if ($clientId && $clientSecret && $redirectUri) {
                ContaAzulConnection::create([
                    'empresa_nome' => 'Default',
                    'email_desenvolvedor' => null,
                    'ca_client_id' => $clientId,
                    'ca_client_secret' => $clientSecret,
                    'ca_redirect_uri' => $redirectUri,
                    'is_active' => true,
                ]);
                $connections = ContaAzulConnection::orderBy('empresa_nome')->get();
            }
        }

        return Inertia::render('Settings/ContaAzul', [
            'connections' => $connections,
            'lastSync' => Cliente::latest('updated_at')->value('updated_at'),
            'totalClientes' => Cliente::count(),
        ]);
    }

    public function getToken()
    {
        try {
            $connectionId = request()->input('connection_id');
            if ($connectionId) {
                $connection = ContaAzulConnection::find($connectionId);
                if (! $connection) {
                    return response()->json(['error' => 'Conexão não encontrada.'], 404);
                }
                $token = $this->contaAzulAuthService->getValidToken($connection) ?? $this->contaAzulAuthService->getValidToken($connection, true);
                if (! $token) {
                    return response()->json(['error' => 'Token inválido ou expirado para esta conexão. Reautorize.'], 404);
                }

                return response()->json(['token' => $token]);
            }
            $token = $this->contaAzulService->getValidToken();
            if (! $token) {
                return response()->json(['error' => 'Token não encontrado ou expirado. Reconecte a conta.'], 404);
            }

            return response()->json(['token' => $token]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function syncClientes(Request $request)
    {
        try {
            $connectionId = $request->input('connection_id');
            $mode = $request->input('mode', 'update'); // 'reset' or 'update'
            
            if (! $connectionId) {
                return response()->json(['error' => 'Selecione uma empresa para sincronizar.'], 400);
            }
            $connection = ContaAzulConnection::find($connectionId);
            if (! $connection) {
                return response()->json(['error' => 'Conexão não encontrada.'], 404);
            }

            // Marca o tempo de início para pruning (se for update)
            $startTime = now();

            if ($mode === 'reset') {
                Cliente::where('connection_id', $connection->id)->delete();
                \App\Models\Invoice::where('connection_id', $connection->id)->delete();
            }

            $page = 1;
            $size = 50; // Aumentado para performance
            $hasMore = true;
            $syncedCount = 0;

            while ($hasMore) {
                $response = $this->contaAzulApiService->getClients($connection, ['page' => $page, 'size' => $size]);
                $clientsData = [];
                if (isset($response['items'])) {
                    $clientsData = $response['items'];
                } elseif (is_array($response)) {
                    $clientsData = $response;
                }
                
                if (empty($clientsData)) {
                    $hasMore = false;
                    break;
                }

                foreach ($clientsData as $caClient) {
                    // Filtra apenas clientes ativos e com perfil de cliente
                    $perfis = $caClient['perfis'] ?? [];
                    $perfis = array_map('strtolower', $perfis);
                    if (! in_array('cliente', $perfis)) {
                        continue;
                    }
                    if (empty($caClient['ativo'])) {
                        continue;
                    }

                    $cpfCnpj = $caClient['documento'] ?? ($caClient['cpf'] ?? ($caClient['cnpj'] ?? null));
                    $phone = $caClient['telefone'] ?? ($caClient['telefone_comercial'] ?? null);
                    $mobilePhone = $caClient['telefone_celular'] ?? null;
                    
                    $city = null;
                    $state = null;
                    if (! empty($caClient['enderecos']) && is_array($caClient['enderecos'])) {
                        $primaryAddress = $caClient['enderecos'][0];
                        $city = $primaryAddress['cidade'] ?? null;
                        $state = $primaryAddress['estado'] ?? null;
                    }

                    // Proteção anti-duplicação entre empresas:
                    // Se já existe um cliente IDÊNTICO (mesmo CPF/CNPJ) em outra conexão, não duplicar aqui.
                    // Também protege por e-mail+telefone quando CPF/CNPJ estiver ausente.
                    try {
                        $existsInOther = false;
                        if (!empty($cpfCnpj)) {
                            $existsInOther = \App\Models\Cliente::where('cpf_cnpj', $cpfCnpj)
                                ->where('connection_id', '!=', $connection->id)
                                ->exists();
                        } else {
                            $email = $caClient['email'] ?? null;
                            $normalizedPhone = preg_replace('/\D+/', '', (string) ($mobilePhone ?? $phone ?? ''));
                            if (!empty($email) && !empty($normalizedPhone)) {
                                $existsInOther = \App\Models\Cliente::where('connection_id', '!=', $connection->id)
                                    ->where(function($q) use ($email, $normalizedPhone) {
                                        $q->where('email', $email)
                                          ->where(function($qq) use ($normalizedPhone) {
                                              $qq->whereRaw("REGEXP_REPLACE(COALESCE(mobile_phone, phone, ''), '[^0-9]', '') = ?", [$normalizedPhone]);
                                          });
                                    })->exists();
                            }
                        }
                        if ($existsInOther) {
                            Log::warning("Dedup: cliente potencialmente duplicado detectado e ignorado na conexão {$connection->id}.", [
                                'ca_client_id' => $caClient['id'] ?? null,
                                'cpf_cnpj' => $cpfCnpj,
                                'email' => $caClient['email'] ?? null,
                                'phone' => $mobilePhone ?? $phone,
                            ]);
                            continue;
                        }
                    } catch (\Exception $e) {
                        Log::error("Falha na verificação de duplicidade de cliente: " . $e->getMessage());
                    }

                    Cliente::updateOrCreate(
                        ['connection_id' => $connection->id, 'ca_id' => $caClient['id']],
                        [
                            'connection_id' => $connection->id,
                            'name' => $caClient['nome'] ?? 'Sem Nome',
                            'company_name' => $connection->empresa_nome,
                            'email' => $caClient['email'] ?? null,
                            'phone' => $phone,
                            'mobile_phone' => $mobilePhone,
                            'cpf_cnpj' => $cpfCnpj,
                            'person_type' => $caClient['tipo_pessoa'] ?? null,
                            'city' => $city,
                            'state' => $state,
                            // O updated_at será atualizado automaticamente, permitindo o pruning
                        ]
                    );
                    $syncedCount++;
                }

                if (count($clientsData) < $size) {
                    $hasMore = false;
                } else {
                    $page++;
                }
                
                // Safety break
                if ($page > 500) {
                    $hasMore = false;
                }
                
                // Pequena pausa para evitar rate limit excessivo
                usleep(200000); 
            }

            // Pruning: Se for update, remove clientes que não foram tocados (não vieram na API)
            // Mas cuidado: se a API falhar em trazer alguns, podemos deletar indevidamente.
            // Vamos assumir que se syncedCount > 0, a sincronização funcionou e podemos limpar os antigos.
            if ($mode === 'update' && $syncedCount > 0) {
                // Deleta clientes desta conexão que não foram atualizados desde o início do processo
                // Damos uma margem de segurança de alguns segundos antes do startTime
                Cliente::where('connection_id', $connection->id)
                    ->where('updated_at', '<', $startTime)
                    ->delete();
            }

            // Sincroniza faturas
            $invoicesCount = $this->contaAzulApiService->syncOverdueInvoices($connection);
            
            // Atualiza timestamp da conexão
            $connection->last_sync_at = now();
            $connection->save();

            // Recalcula envios futuros
            try {
                $this->futureMessageService->calculateForConnection($connection);
            } catch (\Exception $e) {
                Log::error('Erro ao calcular envios futuros após sync: ' . $e->getMessage());
            }

            $clientesDbCount = \App\Models\Cliente::where('connection_id', $connection->id)->count();
            $apiTotals = $this->contaAzulApiService->getOverdueTotals($connection);
            $invoicesApiCount = $apiTotals['count'] ?? 0;

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => "Sincronização concluída! {$clientesDbCount} clientes e {$invoicesApiCount} faturas em atraso.",
                    'details' => [
                        'clientes_count' => $clientesDbCount,
                        'invoices_count' => $invoicesApiCount,
                        'synced_count' => $syncedCount
                    ]
                ]);
            }

            return redirect()->back()->with('success', "Sincronização concluída! {$clientesDbCount} clientes e {$invoicesApiCount} faturas em atraso processados para a conexão {$connection->empresa_nome}.");

        } catch (\Exception $e) {
            Log::error('Erro na sincronização de clientes: '.$e->getMessage());
            
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'error' => 'Erro ao sincronizar: '.$e->getMessage()], 500);
            }

            return redirect()->back()->with('error', 'Erro ao sincronizar: '.$e->getMessage());
        }
    }

    public function syncAllClientes()
    {
        try {
            $connections = ContaAzulConnection::active()->orderBy('empresa_nome')->get();
            if ($connections->isEmpty()) {
                return redirect()->back()->with('error', 'Nenhuma conexão ativa encontrada.');
            }
            $summary = [];
            foreach ($connections as $connection) {
                $page = 1;
                $size = 20;
                $hasMore = true;
                $syncedCount = 0;
                $token = $this->contaAzulAuthService->getValidToken($connection) ?? $this->contaAzulAuthService->getValidToken($connection, true);
                if (! $token) {
                    $summary[] = "Conexão {$connection->empresa_nome}: sem token válido.";
                    continue;
                }
                while ($hasMore) {
                    $response = $this->contaAzulApiService->getClients($connection, ['page' => $page, 'size' => $size]);
                    $clientsData = [];
                    if (isset($response['items'])) {
                        $clientsData = $response['items'];
                    } elseif (is_array($response)) {
                        $clientsData = $response;
                    }
                    if (empty($clientsData)) {
                        $hasMore = false;
                        break;
                    }
                    foreach ($clientsData as $caClient) {
                        $perfis = $caClient['perfis'] ?? [];
                        $perfis = array_map('strtolower', $perfis);
                        if (! in_array('cliente', $perfis)) {
                            continue;
                        }
                        if (empty($caClient['ativo'])) {
                            continue;
                        }
                        $cpfCnpj = $caClient['documento'] ?? ($caClient['cpf'] ?? ($caClient['cnpj'] ?? null));
                        $phone = $caClient['telefone'] ?? ($caClient['telefone_comercial'] ?? null);
                        $mobilePhone = $caClient['telefone_celular'] ?? null;
                        $city = null;
                        $state = null;
                        if (! empty($caClient['enderecos']) && is_array($caClient['enderecos'])) {
                            $primaryAddress = $caClient['enderecos'][0];
                            $city = $primaryAddress['cidade'] ?? null;
                            $state = $primaryAddress['estado'] ?? null;
                        }
                        Cliente::updateOrCreate(
                            ['connection_id' => $connection->id, 'ca_id' => $caClient['id']],
                            [
                                'connection_id' => $connection->id,
                                'name' => $caClient['nome'] ?? 'Sem Nome',
                                'company_name' => $connection->empresa_nome,
                                'email' => $caClient['email'] ?? null,
                                'phone' => $phone,
                                'mobile_phone' => $mobilePhone,
                                'cpf_cnpj' => $cpfCnpj,
                                'person_type' => $caClient['tipo_pessoa'] ?? null,
                                'city' => $city,
                                'state' => $state,
                            ]
                        );
                        $syncedCount++;
                    }
                    if (count($clientsData) < $size) {
                        $hasMore = false;
                    } else {
                        $page++;
                    }
                    if ($page > 500) {
                        $hasMore = false;
                    }
                    sleep(1);
                }
                $invoicesCount = $this->contaAzulApiService->syncOverdueInvoices($connection);
                $clientesDbCount = \App\Models\Cliente::where('connection_id', $connection->id)->count();
                $apiTotals = $this->contaAzulApiService->getOverdueTotals($connection);
                $invoicesApiCount = $apiTotals['count'] ?? 0;
                $summary[] = "{$connection->empresa_nome}: {$clientesDbCount} clientes e {$invoicesApiCount} faturas em atraso.";
                $connection->last_sync_at = now();
                $connection->save();
                
                // Recalcula envios futuros
                try {
                    $this->futureMessageService->calculateForConnection($connection);
                } catch (\Exception $e) {
                    Log::error("Erro ao calcular envios futuros após sync geral (conn {$connection->id}): " . $e->getMessage());
                }
            }
            return redirect()->back()->with('success', 'Sincronização concluída: '.implode(' | ', $summary));
        } catch (\Exception $e) {
            Log::error('Erro na sincronização geral: '.$e->getMessage());
            return redirect()->back()->with('error', 'Erro ao sincronizar todas: '.$e->getMessage());
        }
    }
}
