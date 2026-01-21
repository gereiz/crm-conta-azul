<?php

namespace App\Http\Controllers;

use App\Services\ContaAzulService;
use App\Services\ContaAzulAuthService;
use App\Models\ContaAzulConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use Inertia\Inertia;

class ContaAzulController extends Controller
{
    protected $contaAzulService;
    protected ContaAzulAuthService $authService;

    public function __construct(ContaAzulService $contaAzulService, ContaAzulAuthService $authService)
    {
        $this->contaAzulService = $contaAzulService;
        $this->authService = $authService;
    }

    public function connect()
    {
        try {
            $url = $this->contaAzulService->getAuthUrl();
            Log::info('Redirecionando para Conta Azul: ' . $url);
            return redirect()->away($url);
        } catch (\Exception $e) {
            Log::error('Erro ao conectar Conta Azul: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Erro na configuração: ' . $e->getMessage());
        }
    }

    public function callback(Request $request)
    {
        Log::info('Callback recebido:', $request->all());

        if ($request->has('error')) {
            $error = $request->input('error');
            $desc = $request->input('error_description');
            Log::error("Erro no callback Conta Azul: $error - $desc");
            return redirect()->route('dashboard')->with('error', "Erro na autenticação Conta Azul: $desc");
        }

        $code = $request->input('code');
        $state = $request->input('state');
        
        $savedState = session('contaazul_state');

        if (!$code || !$state || $state !== $savedState) {
            Log::error('Callback Conta Azul inválido: Code ou State incorretos.');
            return redirect()->route('dashboard')->with('error', 'Falha na autenticação com Conta Azul (State inválido).');
        }

        $decoded = json_decode(base64_decode($state), true);
        if (is_array($decoded) && isset($decoded['connection_id'])) {
            $connection = ContaAzulConnection::find($decoded['connection_id']);
            if ($connection) {
                $tokenData = $this->authService->exchangeCode($connection, $code);
                if ($tokenData && isset($tokenData['access_token'])) {
                    $this->authService->saveTokens($connection, $tokenData);
                    return redirect()->route('contaazul.connections.index')->with('success', 'Conectado com sucesso ao Conta Azul (multi).');
                }
                return redirect()->route('contaazul.connections.index')->with('error', 'Falha ao obter token do Conta Azul.');
            }
        }

        $tokenData = $this->contaAzulService->getToken($code);

        if ($tokenData && isset($tokenData['access_token'])) {
            $this->contaAzulService->saveToken($tokenData);
            return redirect()->route('dashboard')->with('success', 'Conectado com sucesso ao Conta Azul!');
        }

        return redirect()->route('dashboard')->with('error', 'Falha ao obter token do Conta Azul.');
    }
}
