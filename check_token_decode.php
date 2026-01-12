<?php

use App\Models\ContaAzulToken;

$token = ContaAzulToken::latest()->first();

if ($token) {
    echo "Token encontrado.\n";
    $parts = explode('.', $token->access_token);
    if (count($parts) === 3) {
        $payload = json_decode(base64_decode($parts[1]), true);
        echo "Token Payload:\n";
        print_r($payload);
    } else {
        echo "Token não parece ser um JWT.\n";
        echo "Token: " . $token->access_token . "\n";
    }
} else {
    echo "Nenhum token encontrado.\n";
}
