<?php

namespace App\Http\Controllers;

use App\Models\ContaAzulConnection;
use App\Services\ContaAzulAuthService;
use App\Services\ContaAzulApiService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ContaAzulConnectionController extends Controller
{
    protected ContaAzulAuthService $auth;
    protected ContaAzulApiService $api;

    public function __construct(ContaAzulAuthService $auth, ContaAzulApiService $api)
    {
        $this->auth = $auth;
        $this->api = $api;
    }

    public function index()
    {
        try {
            $connections = ContaAzulConnection::orderBy('empresa_nome')->get();
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            // Se falhar ao descriptografar na listagem, retorna lista vazia ou trata o erro
            // O ideal seria forçar limpeza via comando, mas aqui evitamos o crash da página
            $connections = collect([]);
            session()->flash('error', 'Erro de criptografia ao carregar conexões. Verifique a APP_KEY ou limpe os dados.');
        }

        return Inertia::render('Settings/ContaAzul', [
            'connections' => $connections,
            'lastSync' => \App\Models\Cliente::latest('updated_at')->value('updated_at'),
            'totalClientes' => \App\Models\Cliente::count(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'empresa_nome' => 'required|string|max:100',
            'email_desenvolvedor' => 'nullable|email|max:150',
            'ca_client_id' => 'required|string|max:255',
            'ca_client_secret' => 'required|string',
            'ca_redirect_uri' => 'required|url|max:255',
            'is_active' => 'boolean',
        ]);

        $connection = ContaAzulConnection::create($data);
        return redirect()->back()->with('success', 'Conexão criada.');
    }

    public function update(Request $request, ContaAzulConnection $connection)
    {
        $data = $request->validate([
            'empresa_nome' => 'required|string|max:100',
            'email_desenvolvedor' => 'nullable|email|max:150',
            'ca_client_id' => 'required|string|max:255',
            'ca_client_secret' => 'required|string',
            'ca_redirect_uri' => 'required|url|max:255',
            'is_active' => 'boolean',
        ]);

        // Tratamento para evitar falha de descriptografia se a chave mudou
        // Usamos DB::table para ignorar os mutators/accessors do Eloquent que tentam descriptografar o valor antigo
        // antes de salvar o novo.
        
        try {
            // Criptografa manualmente o novo secret para salvar direto no banco
            $encryptedSecret = \Illuminate\Support\Facades\Crypt::encryptString($data['ca_client_secret']);
            
            \Illuminate\Support\Facades\DB::table('conta_azul_connections')
                ->where('id', $connection->id)
                ->update([
                    'empresa_nome' => $data['empresa_nome'],
                    'email_desenvolvedor' => $data['email_desenvolvedor'],
                    'ca_client_id' => $data['ca_client_id'],
                    'ca_client_secret' => $encryptedSecret,
                    'ca_redirect_uri' => $data['ca_redirect_uri'],
                    'is_active' => $data['is_active'],
                    'access_token' => null,  // Reseta tokens pois a credencial mudou/foi corrigida
                    'refresh_token' => null,
                    'token_expires_at' => null,
                    'updated_at' => now(),
                ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Erro ao atualizar conexão Conta Azul: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Erro ao salvar conexão: ' . $e->getMessage());
        }

        return redirect()->back()->with('success', 'Conexão atualizada com sucesso. Agora clique em Reconectar.');
    }

    public function destroy(ContaAzulConnection $connection)
    {
        $connection->delete();
        return redirect()->back()->with('success', 'Conexão removida.');
    }

    public function connect(ContaAzulConnection $connection)
    {
        if (empty($connection->ca_client_id) || empty($connection->ca_redirect_uri)) {
            return redirect()->back()->with('error', 'Conexão inválida: Client ID ou Redirect URI ausentes.');
        }
        $url = $this->auth->getAuthUrl($connection);
        return redirect()->away($url);
    }

    public function callback(Request $request, ContaAzulConnection $connection)
    {
        $state = $request->input('state');
        
        // Try specific state key first, then fallback to global
        $savedState = session('contaazul_state_' . $connection->id) ?? session('contaazul_state');

        if (!$state || $state !== $savedState) {
            \Illuminate\Support\Facades\Log::warning("Callback ContaAzul: State mismatch for Conn {$connection->id}. Received: $state | Saved: $savedState");
            return redirect()->route('contaazul.connections.index')->with('error', 'Falha na autenticação Conta Azul (State inválido).');
        }

        $payload = json_decode(base64_decode($state), true);
        if (!$payload || ($payload['connection_id'] ?? null) != $connection->id) {
            return redirect()->route('contaazul.connections.index')->with('error', 'Conexão inválida no callback.');
        }

        $code = $request->input('code');
        $data = $this->auth->exchangeCode($connection, $code);

        if (isset($data['error']) && $data['error'] === 'decrypt_error') {
            return redirect()->route('contaazul.connections.index')->with('error', 'A senha do aplicativo (Client Secret) está corrompida no banco. Por favor, edite a conexão e insira a senha novamente.');
        }

        if (!$data || empty($data['access_token'])) {
            return redirect()->route('contaazul.connections.index')->with('error', 'Falha ao obter token da Conta Azul.');
        }
        $this->auth->saveTokens($connection, $data);
        return redirect()->route('contaazul.connections.index')->with('success', 'Conexão reautorizada com sucesso.');
    }

    public function refreshToken(ContaAzulConnection $connection)
    {
        try {
            if (!$connection->refresh_token) {
                return response()->json(['success' => false, 'error' => 'Sem refresh token disponível. Realize a conexão manual.']);
            }

            $newToken = $this->auth->refreshToken($connection);

            if ($newToken) {
                return response()->json(['success' => true, 'message' => 'Token renovado com sucesso.']);
            } else {
                return response()->json(['success' => false, 'error' => 'Falha ao renovar token. O refresh token pode ter expirado.']);
            }
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Erro interno: ' . $e->getMessage()]);
        }
    }
}
