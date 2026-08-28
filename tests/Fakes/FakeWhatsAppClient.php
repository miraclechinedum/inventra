<?php

namespace Tests\Fakes;

use App\Contracts\WhatsAppClient;
use App\Exceptions\WhatsAppOutcomeUnknownException;
use App\Models\WhatsAppDelivery;
use App\Support\WhatsAppSendResult;

class FakeWhatsAppClient implements WhatsAppClient
{
    public bool $configured = true;

    public bool $outcomeUnknown = false;

    public WhatsAppSendResult $result;

    /** @var array<int, array{delivery: WhatsAppDelivery, parameters: array<string, string>}> */
    public array $requests = [];

    public function __construct()
    {
        $this->result = WhatsAppSendResult::accepted('wamid.test-message');
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function sendReceipt(WhatsAppDelivery $delivery, array $parameters): WhatsAppSendResult
    {
        $this->requests[] = compact('delivery', 'parameters');

        if ($this->outcomeUnknown) {
            throw new WhatsAppOutcomeUnknownException('Simulated unknown outcome.');
        }

        return $this->result;
    }
}
