<?php

use App\Models\ContaAzulConnection;
use Illuminate\Contracts\Console\Kernel;

// 1. Carrega o autoloader do Composer
require __DIR__ . '/vendor/autoload.php';

// 2. Inicializa a aplicação Laravel (Bootstrap)
$app = require_once __DIR__ . '/bootstrap/app.php';

// 3. Boot no Kernel para carregar configurações, banco de dados e Facades
$app->make(Kernel::class)->bootstrap();

// --- Lógica do Script ---

echo "\n=======================================================\n";
echo "    GERADOR DE URLS DE AUTORIZAÇÃO CONTA AZUL\n";
echo "=======================================================\n\n";

// Tenta buscar a primeira conexão ativa no banco
try {
    $connection = ContaAzulConnection::first();
} catch (\Exception $e) {
    echo "ERRO: Não foi possível conectar ao banco de dados.\n";
    echo $e->getMessage() . "\n";
    exit(1);
}

// Define credenciais (prioridade: Banco > Config > .env)
$clientId = $connection ? trim($connection->ca_client_id) : config('services.contaazul.client_id');
$redirectUri = ($connection && $connection->ca_redirect_uri) 
    ? trim($connection->ca_redirect_uri) 
    : (config('services.contaazul.redirect_uri') ?: env('CONTA_AZUL_REDIRECT_URI'));

// Validação básica
if (empty($clientId)) {
    echo "ERRO CRÍTICO: Client ID não encontrado (nem no banco, nem no .env).\n";
    exit(1);
}

if (empty($redirectUri)) {
    echo "ERRO CRÍTICO: Redirect URI não encontrada.\n";
    exit(1);
}

echo "Configuração Carregada:\n";
echo "- Client ID: " . substr($clientId, 0, 5) . "..." . substr($clientId, -3) . "\n";
echo "- Redirect URI: {$redirectUri}\n";
if ($connection) {
    echo "- Usando conexão do banco (ID: {$connection->id})\n";
} else {
    echo "- Usando configurações do .env\n";
}
echo "\n-------------------------------------------------------\n\n";

// Gera um state fictício para teste
$state = base64_encode(json_encode([
    'nonce' => bin2hex(random_bytes(8)),
    'test_mode' => true
]));

// Cenários de Teste de Escopo
$scenarios = [
    '1. Documentação Oficial (API v2)' => 'openid profile aws.cognito.signin.user.admin',
];

echo "INSTRUÇÕES:\n";
echo "Copie cada URL abaixo e cole em uma aba ANÔNIMA do navegador.\n";
echo "A que abrir a tela de login/permissão do Conta Azul é a combinação correta.\n";
echo "Se der 'invalid_scope', a combinação é inválida para sua App.\n\n";

foreach ($scenarios as $name => $scope) {
    $params = [
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'state' => $state,
        'response_type' => 'code',
        'scope' => $scope,
        // 'prompt' => 'login consent' // Força tela de consentimento
    ];
    
    $query = http_build_query($params);
    $url = "https://auth.contaazul.com/authorize?{$query}";
    
    echo "CENÁRIO: {$name}\n";
    echo "Escopos: [{$scope}]\n";
    echo "URL: {$url}\n";
    echo "\n" . str_repeat('-', 40) . "\n\n";
}
