<?php

use App\Models\ContaAzulToken;
use Illuminate\Support\Facades\Log;

$token = ContaAzulToken::latest()->first();

if ($token) {
    echo "Token encontrado:\n";
    echo "ID: " . $token->id . "\n";
    echo "Access Token (start): " . substr($token->access_token, 0, 10) . "...\n";
    echo "Expires At: " . $token->expires_at . "\n";
    echo "Current Time: " . now() . "\n";
    echo "Is Expired? " . ($token->expires_at->lt(now()) ? 'Yes' : 'No') . "\n";
} else {
    echo "Nenhum token encontrado.\n";
}
