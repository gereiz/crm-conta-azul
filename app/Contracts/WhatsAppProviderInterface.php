<?php

namespace App\Contracts;

use App\Models\WhatsappNumber;

interface WhatsAppProviderInterface
{
    public function sendMessage($whatsappId, $to, $message);

    public function checkConnection(WhatsappNumber $whatsapp);
}

