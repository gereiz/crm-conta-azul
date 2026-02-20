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
use App\Models\WhatsappNumber;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappSendState;
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
    ) {
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
            'schedule:run' => 'Executar Scheduler Agora',
        ];

        if (! array_key_exists($command, $allowedCommands)) {
            return response()->json(['error' => 'Comando não permitido.'], 403);
        }

        try {
            \Illuminate\Support\Facades\Artisan::call($command);
            $output = \Illuminate\Support\Facades\Artisan::output();

            return response()->json(['success' => true, 'output' => $output]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao executar comando: '.$e->getMessage()], 500);
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

    public function orchestrator()
    {
        $settings = SystemSetting::latest()->first();

        return Inertia::render('Settings/Orchestrator', [
            'settings' => $settings,
        ]);
    }

    public function orchestratorSave(Request $request)
    {
        $data = $request->validate([
            'orchestrator_delay_min_seconds' => 'required|integer|min:1',
            'orchestrator_delay_max_seconds' => 'required|integer|min:1|gte:orchestrator_delay_min_seconds',
            'orchestrator_batch_size' => 'required|integer|min:1|max:200',
            'orchestrator_batch_interval_min_seconds' => 'required|integer|min:60',
            'orchestrator_batch_interval_max_seconds' => 'required|integer|min:60|gte:orchestrator_batch_interval_min_seconds',
            'orchestrator_hourly_limit_per_number' => 'required|integer|min:1|max:1000',
            'orchestrator_safe_start_hour' => 'required|string|regex:/^\\d{2}:\\d{2}$/',
            'orchestrator_safe_end_hour' => 'required|string|regex:/^\\d{2}:\\d{2}$/',
            'orchestrator_concurrent_cooldown_minutes' => 'required|integer|min:1|max:120',
            'orchestrator_pause_on_error_minutes' => 'required|integer|min:1|max:180',
            'orchestrator_warmup_day1_limit' => 'required|integer|min:1|max:500',
            'orchestrator_warmup_day2_limit' => 'required|integer|min:1|max:500',
            'orchestrator_warmup_day3_limit' => 'required|integer|min:1|max:500',
        ]);

        $settings = SystemSetting::latest()->first() ?? new SystemSetting;
        foreach ($data as $k => $v) {
            $settings->{$k} = $v;
        }
        $settings->save();

        return redirect()->back()->with('success', 'Parâmetros do Orquestrador atualizados com sucesso.');
    }

    public function orchestratorStatus()
    {
        $tz = config('app.timezone') ?: 'America/Sao_Paulo';
        $now = \Carbon\Carbon::now($tz);
        $numbers = WhatsappNumber::orderBy('description')->get(['id', 'description', 'provider', 'provider_instance', 'status']);
        $items = [];
        foreach ($numbers as $n) {
            $state = WhatsappSendState::firstOrCreate(['whatsapp_number_id' => $n->id], []);
            $queueCount = WhatsappMessageLog::where('whatsapp_number_id', $n->id)
                ->where('status', 'skipped')
                ->where('error_message', 'like', '%Enfileirado%')
                ->whereDate('sent_at', $now->toDateString())
                ->count();
            $hourlyRemaining = null;
            if ($state->hourly_window_start) {
                $until = \Carbon\Carbon::parse($state->hourly_window_start, $tz)->addHour();
                $hourlyRemaining = max(0, $until->diffInSeconds($now, false) * -1);
            }
            $nextAvailable = null;
            if ($state->paused_until && \Carbon\Carbon::parse($state->paused_until, $tz)->gt($now)) {
                $nextAvailable = \Carbon\Carbon::parse($state->paused_until, $tz)->toDateTimeString();
            }
            $items[] = [
                'id' => $n->id,
                'description' => $n->description,
                'provider' => $n->provider,
                'instance' => $n->provider_instance,
                'status' => $n->status,
                'in_progress' => (bool) ($state->in_progress ?? false),
                'paused_until' => $state->paused_until ? \Carbon\Carbon::parse($state->paused_until, $tz)->toDateTimeString() : null,
                'hourly_count' => (int) ($state->hourly_count ?? 0),
                'hourly_remaining_seconds' => $hourlyRemaining,
                'daily_count' => (int) ($state->daily_count ?? 0),
                'queue_count' => $queueCount,
                'next_available_at' => $nextAvailable,
            ];
        }

        return response()->json(['items' => $items, 'now' => $now->toDateTimeString()]);
    }

    public function orchestratorResume(Request $request)
    {
        $id = (int) $request->input('whatsapp_number_id');
        if (! $id) {
            return response()->json(['success' => false, 'error' => 'whatsapp_number_id obrigatório'], 422);
        }
        $state = WhatsappSendState::firstOrCreate(['whatsapp_number_id' => $id], []);
        $state->paused_until = null;
        $state->in_progress = false;
        $state->save();

        return response()->json(['success' => true]);
    }

    public function orchestratorForceResume(Request $request)
    {
        $id = (int) $request->input('whatsapp_number_id');
        if (! $id) {
            return response()->json(['success' => false, 'error' => 'whatsapp_number_id obrigatório'], 422);
        }
        $tz = config('app.timezone') ?: 'America/Sao_Paulo';
        $settings = SystemSetting::latest()->first();
        $cooldown = (int) ($settings->orchestrator_concurrent_cooldown_minutes ?? 15);
        $state = WhatsappSendState::firstOrCreate(['whatsapp_number_id' => $id], []);
        $state->paused_until = null;
        $state->in_progress = false;
        $state->hourly_window_start = \Carbon\Carbon::now($tz)->subHour()->subMinute();
        $state->hourly_count = 0;
        $state->last_finished_at = \Carbon\Carbon::now($tz)->subMinutes($cooldown)->subMinute();
        $state->save();

        return response()->json(['success' => true]);
    }

    public function orchestratorClearQueue(Request $request)
    {
        $id = (int) $request->input('whatsapp_number_id');
        if (! $id) {
            return response()->json(['success' => false, 'error' => 'whatsapp_number_id obrigatório'], 422);
        }
        $tz = config('app.timezone') ?: 'America/Sao_Paulo';
        $today = \Carbon\Carbon::today($tz);
        $count = WhatsappMessageLog::where('whatsapp_number_id', $id)
            ->where('status', 'skipped')
            ->where('error_message', 'like', '%Enfileirado%')
            ->whereDate('sent_at', $today)
            ->delete();

        return response()->json(['success' => true, 'cleared' => $count]);
    }

    public function contaAzulCronStatus()
    {
        $settings = SystemSetting::latest()->first();
        $enabled = $settings ? (bool) ($settings->contaazul_cron_enabled ?? true) : true;

        return response()->json(['enabled' => $enabled]);
    }

    public function schedulerStatus()
    {
        $heartbeat = \Illuminate\Support\Facades\Cache::get('scheduler_heartbeat');
        $tz = config('app.timezone') ?: 'America/Sao_Paulo';
        $active = false;
        $last = null;
        if ($heartbeat) {
            try {
                $last = \Carbon\Carbon::parse($heartbeat, $tz);
                $active = $last->gt(\Carbon\Carbon::now($tz)->subMinutes(2));
            } catch (\Throwable $e) {
            }
        }

        return response()->json([
            'active' => $active,
            'last_beat' => $heartbeat,
        ]);
    }

    public function contaAzulCronToggle(Request $request)
    {
        if (! $request->user() || ($request->user()->role ?? '') !== 'admin') {
            return response()->json(['error' => 'Acesso negado'], 403);
        }
        $enabled = filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOLEAN);
        $settings = SystemSetting::latest()->first() ?? new SystemSetting;
        $settings->system_name = $settings->system_name ?? 'IbitWeb';
        $settings->primary_color = $settings->primary_color ?? '#6366F1';
        $settings->secondary_color = $settings->secondary_color ?? '#22C55E';
        $settings->contaazul_cron_enabled = $enabled;
        $settings->save();

        return response()->json(['success' => true, 'enabled' => $enabled]);
    }

    public function delayedCronStatus()
    {
        $tz = config('app.timezone') ?: 'America/Sao_Paulo';
        $now = Carbon::now($tz);
        $today = $now->toDateString();
        $pendingQuery = MessageCron::where('is_active', true)
            ->where('run_when_delayed', true)
            ->where('send_time', '<=', $now->format('H:i'))
            ->where(function ($q) use ($today) {
                $q->whereNull('last_run_at')->orWhereDate('last_run_at', '<', $today);
            });
        $pendingCount = $pendingQuery->count();
        $next = $pendingQuery->orderBy('send_time', 'asc')->first();
        $nextExpectedRunAt = $next ? Carbon::parse($today.' '.$next->send_time, $tz)->toDateTimeString() : null;
        $lastProcessedAt = MessageCron::whereNotNull('last_run_at')->max('last_run_at');

        return response()->json([
            'pending_count' => $pendingCount,
            'next_expected_run_at' => $nextExpectedRunAt,
            'last_processed_at' => $lastProcessedAt ? Carbon::parse($lastProcessedAt, $tz)->toDateTimeString() : null,
        ]);
    }

    public function processNextDelayedCron(MessageCronService $service)
    {
        $tz = config('app.timezone') ?: 'America/Sao_Paulo';
        $now = Carbon::now($tz);
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

    public function clearDelayedCrons()
    {
        $tz = config('app.timezone') ?: 'America/Sao_Paulo';
        $now = Carbon::now($tz);
        $today = $now->toDateString();
        $query = MessageCron::where('is_active', true)
            ->where('run_when_delayed', true)
            ->where('send_time', '<=', $now->format('H:i'))
            ->where(function ($q) use ($today) {
                $q->whereNull('last_run_at')->orWhereDate('last_run_at', '<', $today);
            });
        $ids = $query->pluck('id');
        if ($ids->isEmpty()) {
            return response()->json(['success' => true, 'cleared_count' => 0]);
        }
        MessageCron::whereIn('id', $ids)->update(['last_run_at' => $now]);

        return response()->json(['success' => true, 'cleared_count' => $ids->count()]);
    }

    public function processCronsNow(MessageCronService $service)
    {
        $tz = config('app.timezone') ?: 'America/Sao_Paulo';
        $now = \Carbon\Carbon::now($tz);
        $currentTime = $now->format('H:i');
        $crons = \App\Models\MessageCron::where('is_active', true)
            ->where(function ($q) use ($currentTime) {
                $q->where('send_time', $currentTime)
                    ->orWhere('send_time', ltrim($currentTime, '0'));
            })
            ->with(['messageTemplate', 'whatsappNumber'])
            ->get();
        $processed = [];
        foreach ($crons as $cron) {
            $service->processCron($cron);
            $processed[] = $cron->id;
        }

        return response()->json(['success' => true, 'processed' => $processed, 'time' => $currentTime]);
    }

    public function systemSave(Request $request)
    {
        $data = $request->validate([
            'system_name' => 'nullable|string|max:50',
            'primary_color' => 'nullable|string|max:20',
            'secondary_color' => 'nullable|string|max:20',
            'logo' => 'nullable|image|max:2048',
            'favicon' => 'nullable|image|max:1024',
            'evolution_api_base_url' => 'nullable|url',
        ]);

        $settings = SystemSetting::latest()->first() ?? new SystemSetting;
        $settings->system_name = $request->input('system_name');
        $settings->primary_color = $request->input('primary_color', '#6366F1');
        $settings->secondary_color = $request->input('secondary_color', '#22C55E');
        $settings->evolution_api_base_url = $request->input('evolution_api_base_url') ? rtrim($request->input('evolution_api_base_url'), '/') : null;

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
                    // Copia também para um caminho estável versionado (fallback do frontend)
                    try {
                        $publicPath = public_path('logo.png');
                        @copy($sourcePath, $publicPath);
                    } catch (\Throwable $e) {
                        Log::warning('Falha ao copiar logo para public/logo.png: '.$e->getMessage());
                    }
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
                    // Copia para caminho estável em public/
                    try {
                        $ext = strtolower($file->getClientOriginalExtension());
                        $dest = public_path($ext === 'ico' ? 'favicon.ico' : 'favicon.png');
                        @copy($sourcePath, $dest);
                    } catch (\Throwable $e) {
                        Log::warning('Falha ao copiar favicon para public/: '.$e->getMessage());
                    }
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
            $target = $request->input('target', 'all'); // 'all' | 'invoices' | 'clients'

            if (! $connectionId) {
                return response()->json(['error' => 'Selecione uma empresa para sincronizar.'], 400);
            }
            $connection = ContaAzulConnection::find($connectionId);
            if (! $connection) {
                return response()->json(['error' => 'Conexão não encontrada.'], 404);
            }

            // Renova o token ANTES de sincronizar manualmente
            $token = $this->contaAzulAuthService->getValidToken($connection, true);
            if (! $token) {
                return response()->json(['error' => 'Falha ao renovar token antes da sincronização.'], 400);
            }

            // Marca o tempo de início para pruning (se for update)
            $startTime = now();

            if ($mode === 'reset') {
                if ($target === 'all') {
                    Cliente::where('connection_id', $connection->id)->delete();
                    \App\Models\Invoice::where('connection_id', $connection->id)->delete();
                } elseif ($target === 'invoices') {
                    \App\Models\Invoice::where('connection_id', $connection->id)->delete();
                }
            }

            $page = 1;
            $size = 50; // Aumentado para performance
            $hasMore = true;
            $syncedCount = 0;

            if ($target === 'all' || $target === 'clients') {
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
                            if (! empty($cpfCnpj)) {
                                $existsInOther = \App\Models\Cliente::where('cpf_cnpj', $cpfCnpj)
                                    ->where('connection_id', '!=', $connection->id)
                                    ->exists();
                            } else {
                                $email = $caClient['email'] ?? null;
                                $normalizedPhone = preg_replace('/\D+/', '', (string) ($mobilePhone ?? $phone ?? ''));
                                if (! empty($email) && ! empty($normalizedPhone)) {
                                    $existsInOther = \App\Models\Cliente::where('connection_id', '!=', $connection->id)
                                        ->where(function ($q) use ($email, $normalizedPhone) {
                                            $q->where('email', $email)
                                                ->where(function ($qq) use ($normalizedPhone) {
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
                            Log::error('Falha na verificação de duplicidade de cliente: '.$e->getMessage());
                        }

                        $existing = Cliente::where('connection_id', $connection->id)->where('ca_id', $caClient['id'])->first();
                        $currentPhone = $existing?->phone;
                        $currentMobile = $existing?->mobile_phone;
                        $hasPlusCurrent = is_string($currentPhone) && preg_match('/^\s*\+/', $currentPhone);
                        $hasPlusMobile = is_string($currentMobile) && preg_match('/^\s*\+/', $currentMobile);
                        $sanitizedCurrent = \App\Services\PhoneSanitizerService::sanitize($currentPhone ?? '');
                        $sanitizedMobile = \App\Services\PhoneSanitizerService::sanitize($currentMobile ?? '');
                        $isInternationalCurrent = $sanitizedCurrent && (!str_starts_with($sanitizedCurrent, '55')) && (strlen($sanitizedCurrent) >= 12);
                        $isInternationalMobile = $sanitizedMobile && (!str_starts_with($sanitizedMobile, '55')) && (strlen($sanitizedMobile) >= 12);
                        $preserveCurrent = $currentPhone && ($hasPlusCurrent || $isInternationalCurrent);
                        $preserveMobile = $currentMobile && ($hasPlusMobile || $isInternationalMobile);

                        $data = [
                            'connection_id' => $connection->id,
                            'name' => $caClient['nome'] ?? 'Sem Nome',
                            'company_name' => $connection->empresa_nome,
                            'email' => $caClient['email'] ?? null,
                            'cpf_cnpj' => $cpfCnpj,
                            'person_type' => $caClient['tipo_pessoa'] ?? null,
                            'city' => $city,
                            'state' => $state,
                        ];
                        if (! $preserveCurrent && ! empty($phone)) {
                            $data['phone'] = $phone;
                        }
                        if (! $preserveMobile && ! empty($mobilePhone)) {
                            $data['mobile_phone'] = $mobilePhone;
                        }

                        Cliente::updateOrCreate(
                            ['connection_id' => $connection->id, 'ca_id' => $caClient['id']],
                            $data
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
            }

            // Pruning incremental por ID (mais robusto):
            // Remove clientes que existem no sistema para esta conexão mas não vieram da Conta Azul.
            // Mantém proteção: não remove clientes que possuem faturas vinculadas.
            if (in_array($target, ['all', 'clients'], true) && $mode === 'update' && $syncedCount > 0) {
                try {
                    $processedIds = \App\Models\Cliente::where('connection_id', $connection->id)
                        ->where('updated_at', '>=', $startTime)
                        ->pluck('ca_id')
                        ->filter()
                        ->values()
                        ->all();

                    if (! empty($processedIds)) {
                        $toDeleteQuery = \App\Models\Cliente::where('connection_id', $connection->id)
                            ->whereNotIn('ca_id', $processedIds);

                        // Proteção anti-erro: não remover clientes com faturas vinculadas
                        $toDeleteQuery->whereDoesntHave('invoices');

                        $toDeleteQuery->delete();
                    } else {
                        // Fallback seguro: se por algum motivo não houve IDs processados,
                        // aplica a regra anterior baseada em updated_at.
                        Cliente::where('connection_id', $connection->id)
                            ->where('updated_at', '<', $startTime)
                            ->delete();
                    }
                } catch (\Exception $e) {
                    Log::error('Falha no pruning de clientes: '.$e->getMessage());
                }
            }

            // Sincroniza faturas quando solicitado
            $invoicesCount = 0;
            $closedCount = 0;
            if (in_array($target, ['all', 'invoices'], true)) {
                $invoicesCount = $this->contaAzulApiService->syncOverdueInvoices($connection);
                $closedCount = $this->contaAzulApiService->syncRecentlyClosedInvoices($connection);
            }

            // Atualiza timestamp da conexão
            $connection->last_sync_at = now();
            $connection->save();

            // Recalcula envios futuros
            try {
                $this->futureMessageService->calculateForConnection($connection);
            } catch (\Exception $e) {
                Log::error('Erro ao calcular envios futuros após sync: '.$e->getMessage());
            }

            $clientesDbCount = \App\Models\Cliente::where('connection_id', $connection->id)->count();
            $apiTotals = $this->contaAzulApiService->getOverdueTotals($connection);
            $invoicesApiCount = $apiTotals['count'] ?? 0;

            // Fallback: se solicitei clientes (all/clients) e o total ficou 0, tenta sincronizar clientes novamente uma única vez
            if (in_array($target, ['all', 'clients'], true) && $clientesDbCount === 0) {
                try {
                    $page = 1;
                    $size = 50;
                    $hasMore = true;
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
                            $existing = Cliente::where('connection_id', $connection->id)->where('ca_id', $caClient['id'])->first();
                            $currentPhone = $existing?->phone;
                            $currentMobile = $existing?->mobile_phone;
                            $hasPlusCurrent = is_string($currentPhone) && preg_match('/^\s*\+/', $currentPhone);
                            $hasPlusMobile = is_string($currentMobile) && preg_match('/^\s*\+/', $currentMobile);
                            $sanitizedCurrent = \App\Services\PhoneSanitizerService::sanitize($currentPhone ?? '');
                            $sanitizedMobile = \App\Services\PhoneSanitizerService::sanitize($currentMobile ?? '');
                            $isInternationalCurrent = $sanitizedCurrent && (!str_starts_with($sanitizedCurrent, '55')) && (strlen($sanitizedCurrent) >= 12);
                            $isInternationalMobile = $sanitizedMobile && (!str_starts_with($sanitizedMobile, '55')) && (strlen($sanitizedMobile) >= 12);
                            $preserveCurrent = $currentPhone && ($hasPlusCurrent || $isInternationalCurrent);
                            $preserveMobile = $currentMobile && ($hasPlusMobile || $isInternationalMobile);
                            $data = [
                                'connection_id' => $connection->id,
                                'name' => $caClient['nome'] ?? 'Sem Nome',
                                'company_name' => $connection->empresa_nome,
                                'email' => $caClient['email'] ?? null,
                                'cpf_cnpj' => $cpfCnpj,
                                'person_type' => $caClient['tipo_pessoa'] ?? null,
                                'city' => $city,
                                'state' => $state,
                            ];
                            if (! $preserveCurrent && ! empty($phone)) {
                                $data['phone'] = $phone;
                            }
                            if (! $preserveMobile && ! empty($mobilePhone)) {
                                $data['mobile_phone'] = $mobilePhone;
                            }
                            Cliente::updateOrCreate(
                                ['connection_id' => $connection->id, 'ca_id' => $caClient['id']],
                                $data
                            );
                        }
                        if (count($clientsData) < $size) {
                            $hasMore = false;
                        } else {
                            $page++;
                        }
                        usleep(200000);
                    }
                } catch (\Throwable $t) {
                    Log::warning("Fallback de clientes falhou ({$connection->empresa_nome}): ".$t->getMessage());
                }
                $clientesDbCount = \App\Models\Cliente::where('connection_id', $connection->id)->count();
            }

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' =>
                        $target === 'all' ? "Sincronização concluída! {$clientesDbCount} clientes, {$invoicesApiCount} faturas em atraso e {$closedCount} atualizações de pagas/canceladas."
                        : ($target === 'clients'
                            ? "Sincronização de clientes concluída! {$clientesDbCount} clientes atualizados para a conexão {$connection->empresa_nome}."
                            : "Sincronização concluída! {$invoicesApiCount} faturas em atraso e {$closedCount} atualizações de pagas/canceladas."),
                    'details' => [
                        'clientes_count' => $clientesDbCount,
                        'invoices_count' => $invoicesApiCount,
                        'synced_count' => $syncedCount,
                        'closed_updates' => $closedCount,
                    ],
                ]);
            }

            $msg =
                $target === 'all'
                    ? "Sincronização concluída! {$clientesDbCount} clientes, {$invoicesApiCount} faturas em atraso e {$closedCount} pagas/canceladas atualizadas para a conexão {$connection->empresa_nome}."
                    : ($target === 'clients'
                        ? "Sincronização de clientes concluída! {$clientesDbCount} clientes atualizados para a conexão {$connection->empresa_nome}."
                        : "Sincronização concluída! {$invoicesApiCount} faturas em atraso e {$closedCount} pagas/canceladas atualizadas para a conexão {$connection->empresa_nome}.");

            return redirect()->back()->with('success', $msg);

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
                // Renova token ANTES de sincronizar manualmente cada empresa
                $token = $this->contaAzulAuthService->getValidToken($connection, true);
                if (! $token) {
                    $summary[] = "Conexão {$connection->empresa_nome}: falha ao renovar token. Pulando.";

                    continue;
                }
                $page = 1;
                $size = 20;
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
                        $existing = Cliente::where('connection_id', $connection->id)->where('ca_id', $caClient['id'])->first();
                        $currentPhone = $existing?->phone;
                        $currentMobile = $existing?->mobile_phone;
                        $hasPlusCurrent = is_string($currentPhone) && preg_match('/^\s*\+/', $currentPhone);
                        $hasPlusMobile = is_string($currentMobile) && preg_match('/^\s*\+/', $currentMobile);
                        $sanitizedCurrent = \App\Services\PhoneSanitizerService::sanitize($currentPhone ?? '');
                        $sanitizedMobile = \App\Services\PhoneSanitizerService::sanitize($currentMobile ?? '');
                        $isInternationalCurrent = $sanitizedCurrent && (!str_starts_with($sanitizedCurrent, '55')) && (strlen($sanitizedCurrent) >= 12);
                        $isInternationalMobile = $sanitizedMobile && (!str_starts_with($sanitizedMobile, '55')) && (strlen($sanitizedMobile) >= 12);
                        $preserveCurrent = $currentPhone && ($hasPlusCurrent || $isInternationalCurrent);
                        $preserveMobile = $currentMobile && ($hasPlusMobile || $isInternationalMobile);

                        $data = [
                            'connection_id' => $connection->id,
                            'name' => $caClient['nome'] ?? 'Sem Nome',
                            'company_name' => $connection->empresa_nome,
                            'email' => $caClient['email'] ?? null,
                            'cpf_cnpj' => $cpfCnpj,
                            'person_type' => $caClient['tipo_pessoa'] ?? null,
                            'city' => $city,
                            'state' => $state,
                        ];
                        if (! $preserveCurrent && ! empty($phone)) {
                            $data['phone'] = $phone;
                        }
                        if (! $preserveMobile && ! empty($mobilePhone)) {
                            $data['mobile_phone'] = $mobilePhone;
                        }
                        Cliente::updateOrCreate(
                            ['connection_id' => $connection->id, 'ca_id' => $caClient['id']],
                            $data
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
                // Fallback: se a conexão ficou com 0 clientes, tenta novamente uma única vez
                $clientesCountConn = \App\Models\Cliente::where('connection_id', $connection->id)->count();
                if ($clientesCountConn === 0) {
                    $this->contaAzulAuthService->getValidToken($connection, true);
                    $page = 1;
                    $size = 20;
                    $hasMore = true;
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
                            $data = [
                                'connection_id' => $connection->id,
                                'name' => $caClient['nome'] ?? 'Sem Nome',
                                'company_name' => $connection->empresa_nome,
                                'email' => $caClient['email'] ?? null,
                                'cpf_cnpj' => $cpfCnpj,
                                'person_type' => $caClient['tipo_pessoa'] ?? null,
                                'city' => $city,
                                'state' => $state,
                            ];
                            if (! empty($phone)) {
                                $data['phone'] = $phone;
                            }
                            if (! empty($mobilePhone)) {
                                $data['mobile_phone'] = $mobilePhone;
                            }
                            \App\Models\Cliente::updateOrCreate(
                                ['connection_id' => $connection->id, 'ca_id' => $caClient['id']],
                                $data
                            );
                        }
                        if (count($clientsData) < $size) {
                            $hasMore = false;
                        } else {
                            $page++;
                        }
                        sleep(1);
                    }
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
                    Log::error("Erro ao calcular envios futuros após sync geral (conn {$connection->id}): ".$e->getMessage());
                }
            }

            return redirect()->back()->with('success', 'Sincronização concluída: '.implode(' | ', $summary));
        } catch (\Exception $e) {
            Log::error('Erro na sincronização geral: '.$e->getMessage());

            return redirect()->back()->with('error', 'Erro ao sincronizar todas: '.$e->getMessage());
        }
    }
}
