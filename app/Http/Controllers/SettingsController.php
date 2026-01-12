<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\ContaAzulConnection;
use App\Models\SystemSetting;
use App\Services\ContaAzulApiService;
use App\Services\ContaAzulAuthService;
use App\Services\ContaAzulService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class SettingsController extends Controller
{
    protected $contaAzulService;

    protected $contaAzulAuthService;

    protected $contaAzulApiService;

    public function __construct(ContaAzulService $contaAzulService, ContaAzulAuthService $contaAzulAuthService, ContaAzulApiService $contaAzulApiService)
    {
        $this->contaAzulService = $contaAzulService;
        $this->contaAzulAuthService = $contaAzulAuthService;
        $this->contaAzulApiService = $contaAzulApiService;
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

    public function syncClientes()
    {
        try {
            $truncate = request()->boolean('truncate');
            if ($truncate && ContaAzulConnection::count() === 1) {
                Cliente::query()->delete();
            }
            $connectionId = request()->input('connection_id');
            $page = 1;
            $size = 20;
            $hasMore = true;
            $syncedCount = 0;

            if (! $connectionId) {
                return redirect()->back()->with('error', 'Selecione uma empresa para sincronizar.');
            }
            $connection = ContaAzulConnection::find($connectionId);
            if (! $connection) {
                return redirect()->back()->with('error', 'Conexão não encontrada.');
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

            return redirect()->back()->with('success', "Sincronização concluída! {$syncedCount} clientes e {$invoicesCount} faturas em atraso processados para a conexão {$connection->empresa_nome}.");

        } catch (\Exception $e) {
            Log::error('Erro na sincronização de clientes: '.$e->getMessage());

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
                $summary[] = "{$connection->empresa_nome}: {$syncedCount} clientes e {$invoicesCount} faturas em atraso.";
                $connection->last_sync_at = now();
                $connection->save();
            }
            return redirect()->back()->with('success', 'Sincronização concluída: '.implode(' | ', $summary));
        } catch (\Exception $e) {
            Log::error('Erro na sincronização geral: '.$e->getMessage());
            return redirect()->back()->with('error', 'Erro ao sincronizar todas: '.$e->getMessage());
        }
    }
}
