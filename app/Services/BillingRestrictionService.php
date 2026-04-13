<?php

namespace App\Services;

use App\Models\BillingRestriction;
use App\Models\Cliente;
use Illuminate\Support\Collection;

class BillingRestrictionService
{
    protected array $rulesCache = [];

    protected array $clientNameCache = [];

    public function isBlocked(int $connectionId, array $context = []): bool
    {
        $rules = $this->getRules($connectionId);

        if ($rules->isEmpty()) {
            return false;
        }

        $clienteNome = trim((string) ($context['cliente_nome'] ?? ''));
        $clienteCaId = $context['cliente_ca_id'] ?? null;
        $invoiceCaId = $context['invoice_ca_id'] ?? null;
        $descricao = trim((string) ($context['descricao'] ?? ''));

        foreach ($rules as $rule) {
            $value = trim(mb_strtolower($rule->value));
            switch ($rule->type) {
                case 'client_equals':
                    $target = mb_strtolower($clienteNome);
                    if (! $target && $clienteCaId) {
                        $target = $this->resolveClientName($connectionId, (string) $clienteCaId);
                    }
                    if ($target && $target === $value) {
                        return true;
                    }
                    break;
                case 'invoice_equals':
                    $inv = $invoiceCaId ? (string) $invoiceCaId : '';
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

    protected function getRules(int $connectionId): Collection
    {
        if (array_key_exists($connectionId, $this->rulesCache)) {
            return $this->rulesCache[$connectionId];
        }

        $specificRules = BillingRestriction::where('connection_id', $connectionId)
            ->where('is_active', true)
            ->get();

        $globalRules = BillingRestriction::whereNull('connection_id')
            ->where('is_active', true)
            ->get();

        return $this->rulesCache[$connectionId] = $specificRules->merge($globalRules);
    }

    protected function resolveClientName(int $connectionId, string $clienteCaId): string
    {
        $cacheKey = $connectionId.'|'.$clienteCaId;
        if (array_key_exists($cacheKey, $this->clientNameCache)) {
            return $this->clientNameCache[$cacheKey];
        }

        $client = Cliente::where('connection_id', $connectionId)
            ->where('ca_id', $clienteCaId)
            ->first(['name', 'company_name']);

        return $this->clientNameCache[$cacheKey] = mb_strtolower((string) ($client->name ?? $client->company_name ?? ''));
    }
}
