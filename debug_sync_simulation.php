<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$conn = \App\Models\ContaAzulConnection::where('is_active', true)->first();

if (!$conn) {
    die("Nenhuma conexão ativa encontrada.\n");
}

echo "Usando conexão: " . $conn->id . " - " . $conn->empresa_nome . "\n";

$service = app(\App\Services\ContaAzulApiService::class);

echo "\n--- Simulando syncOverdueInvoices ---\n";

$startDate = \Carbon\Carbon::now()->subYears(5)->format('Y-m-d');
$endDate = \Carbon\Carbon::now()->addMonths(12)->format('Y-m-d');
$size = 50; // Menor para teste
$allInvoices = [];

$statuses = ['OVERDUE', 'PENDING'];

foreach ($statuses as $status) {
    echo "\nBuscando status: $status (De $startDate até $endDate)\n";
    $hasMore = true;
    $page = 1;
    $countStatus = 0;
    
    while ($hasMore) {
        echo "  Página $page... ";
        try {
            // Chamada direta ao método público
            $data = $service->getAllOverdueInvoices($conn, $page, $size, $startDate, $endDate, $status);
            
            $items = $data['itens'] ?? [];
            $qtd = count($items);
            echo "Encontrados: $qtd itens.\n";
            
            if ($qtd > 0) {
                // Amostra de datas
                $datas = array_map(function($i) { return $i['data_vencimento'] . ' (' . $i['status'] . ')'; }, array_slice($items, 0, 3));
                echo "    Amostra datas: " . implode(', ', $datas) . "\n";
                
                $allInvoices = array_merge($allInvoices, $items);
                $countStatus += $qtd;
            }
            
            if (empty($items) || $qtd < $size || $page >= 3) { // Limite de paginas para debug
                $hasMore = false;
            } else {
                $page++;
            }
        } catch (\Exception $e) {
            echo "ERRO: " . $e->getMessage() . "\n";
            $hasMore = false;
        }
    }
    echo "Total $status: $countStatus\n";
}

echo "\nTotal Geral Coletado: " . count($allInvoices) . "\n";

// Analisar datas do total
if (count($allInvoices) > 0) {
    $datasVencimento = array_column($allInvoices, 'data_vencimento');
    sort($datasVencimento);
    echo "Menor data: " . $datasVencimento[0] . "\n";
    echo "Maior data: " . end($datasVencimento) . "\n";
    
    // Contar futuros
    $hoje = date('Y-m-d');
    $futuros = array_filter($datasVencimento, function($d) use ($hoje) { return $d > $hoje; });
    echo "Qtd Futuros (> $hoje): " . count($futuros) . "\n";
}
