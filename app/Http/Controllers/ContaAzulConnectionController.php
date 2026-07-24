<?php

namespace App\Http\Controllers;

use App\Models\ContaAzulConnection;
use App\Services\ContaAzulApiService;
use App\Services\ContaAzulAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            'ca_redirect_uri' => 'nullable|url|max:255',
            'is_active' => 'boolean',
        ]);

        $data['ca_redirect_uri'] = trim((string) config('services.contaazul.redirect_uri')) ?: route('contaazul.callback');

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
            'ca_redirect_uri' => 'nullable|url|max:255',
            'is_active' => 'boolean',
        ]);

        $data['ca_redirect_uri'] = trim((string) config('services.contaazul.redirect_uri')) ?: route('contaazul.callback');

        // Tratamento para evitar falha de descriptografia se a chave mudou
        // Se a APP_KEY mudou, o acesso aos atributos criptografados (como ca_client_secret)
        // vai lançar exceção na leitura implícita que o Eloquent pode fazer antes do update.
        // Mas o update sobrescreve. O problema é se o update tenta ler os valores antigos para comparar "dirty".
        // Para garantir, forçamos a definição dos atributos sem leitura prévia se possível,
        // ou capturamos a exceção para permitir a sobrescrita.

        try {
            $connection->update($data);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            // Se falhou ao descriptografar, significa que os dados antigos estão corrompidos (chave mudou).
            // Como estamos fornecendo TODOS os dados sensíveis novamente no request (secret, etc),
            // podemos forçar a gravação direta ignorando o estado anterior.

            $connection->empresa_nome = $data['empresa_nome'];
            $connection->email_desenvolvedor = $data['email_desenvolvedor'];
            $connection->ca_client_id = $data['ca_client_id'];
            $connection->ca_client_secret = $data['ca_client_secret']; // Será encriptado com a NOVA chave
            $connection->ca_redirect_uri = $data['ca_redirect_uri'];
            $connection->is_active = $data['is_active'];

            // Limpa tokens antigos pois eles também estarão corrompidos
            $connection->access_token = null;
            $connection->refresh_token = null;
            $connection->token_expires_at = null;

            $connection->save();
        }

        return redirect()->back()->with('success', 'Conexão atualizada.');
    }

    public function destroy(ContaAzulConnection $connection)
    {
        $connection->delete();

        return redirect()->back()->with('success', 'Conexão removida.');
    }

    public function connect(ContaAzulConnection $connection)
    {
        if (empty($connection->ca_client_id)) {
            return redirect()->back()->with('error', 'Conexão inválida: Client ID ausente.');
        }
        $url = $this->auth->getAuthUrl($connection);

        return redirect()->away($url);
    }

    public function callback(Request $request, ContaAzulConnection $connection)
    {
        $state = $request->input('state');
        $savedState = $this->auth->getSavedState($connection->id);
        $stateHash = is_string($state) && $state !== '' ? substr(sha1($state), 0, 12) : null;
        $savedStateHash = is_string($savedState) && $savedState !== '' ? substr(sha1($savedState), 0, 12) : null;

        Log::info('Conta Azul connection callback debug', [
            'connection_id' => $connection->id,
            'request_host' => $request->getHost(),
            'full_url' => $request->fullUrl(),
            'session_id' => session()->getId(),
            'has_state' => ! empty($state),
            'has_saved_state' => ! empty($savedState),
            'state_hash' => $stateHash,
            'saved_state_hash' => $savedStateHash,
        ]);

        if (! $state || ! $savedState || ! hash_equals($savedState, $state)) {
            Log::warning('Conta Azul connection callback inválido: state não confere com a sessão.', [
                'connection_id' => $connection->id,
                'request_host' => $request->getHost(),
                'session_id' => session()->getId(),
                'state_hash' => $stateHash,
                'saved_state_hash' => $savedStateHash,
            ]);
            return redirect()->route('contaazul.connections.index')->with('error', 'Falha na autenticação Conta Azul (State inválido).');
        }

        $payload = $this->auth->decodeState($state);
        if (! $payload || ($payload['connection_id'] ?? null) != $connection->id) {
            return redirect()->route('contaazul.connections.index')->with('error', 'Conexão inválida no callback.');
        }

        $code = $request->input('code');
        $data = $this->auth->exchangeCode($connection, $code);
        if (! $data || empty($data['access_token'])) {
            return redirect()->route('contaazul.connections.index')->with('error', 'Falha ao obter token da Conta Azul.');
        }
        $this->auth->saveTokens($connection, $data);
        $this->auth->clearOAuthSession($connection->id);

        return redirect()->route('contaazul.connections.index')->with('success', 'Conexão reautorizada com sucesso.');
    }

    public function refreshToken(ContaAzulConnection $connection)
    {
        try {
            if (! $connection->refresh_token) {
                return response()->json(['success' => false, 'error' => 'Sem refresh token disponível. Realize a conexão manual.']);
            }

            $newToken = $this->auth->refreshToken($connection);

            if ($newToken) {
                return response()->json(['success' => true, 'message' => 'Token renovado com sucesso.']);
            } else {
                return response()->json(['success' => false, 'error' => 'Falha ao renovar token. O refresh token pode ter expirado.']);
            }
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Erro interno: '.$e->getMessage()]);
        }
    }
}
