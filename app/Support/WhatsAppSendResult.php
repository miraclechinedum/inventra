<?php

namespace App\Support;

final readonly class WhatsAppSendResult
{
    private function __construct(
        public bool $accepted,
        public ?string $providerMessageId,
        public ?string $failureCode,
        public ?string $failureReason,
    ) {}

    public static function accepted(string $providerMessageId): self
    {
        return new self(true, $providerMessageId, null, null);
    }

    public static function rejected(?string $code, ?string $reason): self
    {
        return new self(false, null, $code, $reason);
    }
}
