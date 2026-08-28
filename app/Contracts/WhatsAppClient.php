<?php

namespace App\Contracts;

use App\Models\WhatsAppDelivery;
use App\Support\WhatsAppSendResult;

interface WhatsAppClient
{
    public function isConfigured(): bool;

    /** @param array<string, string> $parameters */
    public function sendReceipt(WhatsAppDelivery $delivery, array $parameters): WhatsAppSendResult;
}
