<?php

namespace App\Http\Controllers;

use App\Models\ContaAzulConnection;
use App\Services\ContaAzulAuthService;
use App\Services\ContaAzulService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
            Log::info('Redirecionando para Conta Azul: '.$url);

            return redirect()->away($url);
        } catch (\Exception $e) {
            Log::error('Erro ao conectar Conta Azul: '.$e->getMessage());

            return redirect()->back()->with('error', 'Erro na configuração: '.$e->getMessage());
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

        $decoded = $this->authService->decodeState($state);
        $connectionId = (int) ($decoded['connection_id'] ?? 0);
        $savedState = $this->authService->getSavedState($connectionId ?: null);
        $stateHash = is_string($state) && $state !== '' ? substr(sha1($state), 0, 12) : null;
        $savedStateHash = is_string($savedState) && $savedState !== '' ? substr(sha1($savedState), 0, 12) : null;

        Log::info('Conta Azul callback debug', [
            'connection_id' => $connectionId ?: null,
            'request_host' => $request->getHost(),
            'full_url' => $request->fullUrl(),
            'session_id' => session()->getId(),
            'has_code' => ! empty($code),
            'has_state' => ! empty($state),
            'has_saved_state' => ! empty($savedState),
            'state_hash' => $stateHash,
            'saved_state_hash' => $savedStateHash,
        ]);

        if (! $code || ! $state || ! $savedState || ! hash_equals($savedState, $state)) {
            Log::warning('Callback Conta Azul inválido: state não confere com a sessão.', [
                'connection_id' => $connectionId ?: null,
                'request_host' => $request->getHost(),
                'session_id' => session()->getId(),
                'has_saved_state' => (bool) $savedState,
                'state_hash' => $stateHash,
                'saved_state_hash' => $savedStateHash,
            ]);

            return redirect()->route($connectionId ? 'contaazul.connections.index' : 'dashboard')
                ->with('error', 'Falha na autenticação com Conta Azul (State inválido).');
        }

        if ($connectionId) {
            $connection = ContaAzulConnection::find($connectionId);
            if ($connection) {
                $tokenData = $this->authService->exchangeCode($connection, $code);
                if ($tokenData && isset($tokenData['access_token'])) {
                    $this->authService->saveTokens($connection, $tokenData);
                    $this->authService->clearOAuthSession($connection->id);

                    return redirect()->route('contaazul.connections.index')->with('success', 'Conectado com sucesso ao Conta Azul (multi).');
                }

                return redirect()->route('contaazul.connections.index')->with('error', 'Falha ao obter token do Conta Azul.');
            }
        }

        $tokenData = $this->contaAzulService->getToken($code);

        if ($tokenData && isset($tokenData['access_token'])) {
            $this->contaAzulService->saveToken($tokenData);
            $this->authService->clearOAuthSession();

            return redirect()->route('dashboard')->with('success', 'Conectado com sucesso ao Conta Azul!');
        }

        return redirect()->route('dashboard')->with('error', 'Falha ao obter token do Conta Azul.');
    }
}
