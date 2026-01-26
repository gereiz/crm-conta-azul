<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$conn = \App\Models\ContaAzulConnection::where('is_active', true)->first();
$service = app(\App\Services\ContaAzulApiService::class);

echo "Usando conexão: " . $conn->id . " - " . $conn->empresa_nome . "\n";

try {
    // Busca OVERDUE pois sabemos que tem dados
    $res = $service->request($conn, 'get', 'financeiro/eventos-financeiros/contas-a-receber/buscar', [
        'pagina' => 1,
        'tamanho_pagina' => 1,
        'status' => 'OVERDUE'
    ]);
    
    if (!empty($res['itens'])) {
        $item = $res['itens'][0];
        echo "Campos retornados na listagem:\n";
        print_r(array_keys($item));
        
        echo "\nValor de 'url' ou similar:\n";
        // Check recursive for url keys
        array_walk_recursive($item, function($value, $key) {
             if (strpos($key, 'url') !== false || strpos($key, 'link') !== false) {
                echo "$key: $value\n";
            }
        });
        
    } else {
        echo "Nenhum item retornado.\n";
    }

} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
