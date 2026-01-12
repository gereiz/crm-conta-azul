<?php

namespace App\Services;

use App\Models\BillingRestriction;
use App\Models\Cliente;

class BillingRestrictionService
{
    public function isBlocked(int $connectionId, array $context = []): bool
    {
        $rules = BillingRestriction::where('connection_id', $connectionId)
            ->where('is_active', true)
            ->get();

        if ($rules->isEmpty()) {
            return false;
        }

        $clienteNome = trim((string)($context['cliente_nome'] ?? ''));
        $clienteCaId = $context['cliente_ca_id'] ?? null;
        $invoiceCaId = $context['invoice_ca_id'] ?? null;
        $descricao = trim((string)($context['descricao'] ?? ''));

        foreach ($rules as $rule) {
            $value = trim(mb_strtolower($rule->value));
            switch ($rule->type) {
                case 'client_equals':
                    $target = mb_strtolower($clienteNome);
                    if (!$target && $clienteCaId) {
                        $client = Cliente::where('connection_id', $connectionId)->where('ca_id', $clienteCaId)->first();
                        $target = $client ? mb_strtolower($client->name ?? $client->company_name ?? '') : '';
                    }
                    if ($target && $target === $value) {
                        return true;
                    }
                    break;
                case 'invoice_equals':
                    $inv = $invoiceCaId ? (string)$invoiceCaId : '';
                    if ($inv && mb_strtolower($inv) === $value) {
                        return true;
                    }
                    break;
                case 'description_contains':
                    if ($descricao && mb_stripos(mb_strtolower($descricao), $value) !== false) {
                        return true;
                    }
                    break;
            }
        }

        return false;
    }
}
