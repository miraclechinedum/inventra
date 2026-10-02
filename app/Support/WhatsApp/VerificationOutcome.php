<?php

namespace App\Support\WhatsApp;

/**
 * Whether the provider confirmed the number, and under which account identifier. A provider that
 * cannot establish this must return `failed()`; nothing here may be assumed from silence.
 */
final class VerificationOutcome
{
    private function __construct(
        public readonly bool $confirmed,
        public readonly ?string $providerAccountId = null,
        public readonly ?string $reason = null,
    ) {}

    public static function confirmed(?string $providerAccountId): self
    {
        return new self(true, providerAccountId: $providerAccountId);
    }

    public static function failed(string $reason): self
    {
        return new self(false, reason: mb_substr($reason, 0, 500));
    }
}
