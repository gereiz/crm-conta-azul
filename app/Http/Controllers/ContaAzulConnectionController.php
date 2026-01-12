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
        $connections = ContaAzulConnection::orderBy('empresa_nome')->get();
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
        $connection->update($data);
        return redirect()->back()->with('success', 'Conexão atualizada.');
    }

    public function destroy(ContaAzulConnection $connection)
    {
        $connection->delete();
        return redirect()->back()->with('success', 'Conexão removida.');
    }

    public function connect(ContaAzulConnection $connection)
    {
        $url = $this->auth->getAuthUrl($connection);
        return Inertia::location($url);
    }

    public function callback(Request $request, ContaAzulConnection $connection)
    {
        $state = $request->input('state');
        $savedState = session('contaazul_state');

        if (!$state || $state !== $savedState) {
            return redirect()->route('contaazul.connections.index')->with('error', 'Falha na autenticação Conta Azul (State inválido).');
        }

        $payload = json_decode(base64_decode($state), true);
        if (!$payload || ($payload['connection_id'] ?? null) != $connection->id) {
            return redirect()->route('contaazul.connections.index')->with('error', 'Conexão inválida no callback.');
        }

        $code = $request->input('code');
        $data = $this->auth->exchangeCode($connection, $code);
        if (!$data || empty($data['access_token'])) {
            return redirect()->route('contaazul.connections.index')->with('error', 'Falha ao obter token da Conta Azul.');
        }
        $this->auth->saveTokens($connection, $data);
        return redirect()->route('contaazul.connections.index')->with('success', 'Conexão reautorizada com sucesso.');
    }
}
