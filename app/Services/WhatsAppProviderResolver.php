<?php

namespace App\Services;

use App\Contracts\WhatsAppProviderInterface;
use App\Models\WhatsappNumber;

class WhatsAppProviderResolver
{
    protected WhapiService $whapi;

    protected EvolutionWhatsAppService $evolution;

    public function __construct(WhapiService $whapi, EvolutionWhatsAppService $evolution)
    {
        $this->whapi = $whapi;
        $this->evolution = $evolution;
    }

    public function resolve(WhatsappNumber $number): WhatsAppProviderInterface
    {
        $provider = $number->provider ?? 'whapi';
        if ($provider === 'evolution') {
            return $this->evolution;
        }

        return $this->whapi;
    }
}

