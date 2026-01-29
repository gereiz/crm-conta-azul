<?php

use App\Services\ContaAzulService;

$service = app(ContaAzulService::class);
$token = $service->getValidToken();

echo "Fetching clients raw response...\n";
$clients = $service->getClients(['size' => 1]);
print_r($clients);
