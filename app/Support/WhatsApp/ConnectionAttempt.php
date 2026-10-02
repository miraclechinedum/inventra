<?php

namespace App\Support\WhatsApp;

/**
 * What a provider says happened. Three outcomes, kept distinct because they demand different
 * handling:
 *
 *  - accepted: the provider took the message and gave an id.
 *  - rejected: the provider definitively refused it. Retrying the same thing will refuse again.
 *  - unknown: the request never got a usable answer (timeout, unreadable response). The message may
 *    or may not have gone out, so it must never be silently resent — the old receipt pipeline
 *    learned this and the distinction is preserved here.
 */
final class ConnectionAttempt
{
    private function __construct(
        public readonly bool $accepted,
        public readonly bool $outcomeUnknown,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureReason = null,
    ) {}

    public static function accepted(string $providerMessageId): self
    {
        return new self(true, false, providerMessageId: $providerMessageId);
    }

    public static function rejected(string $code, string $reason): self
    {
        return new self(false, false, failureCode: mb_substr($code, 0, 64), failureReason: mb_substr($reason, 0, 500));
    }

    public static function unknown(string $reason = 'Provider outcome could not be confirmed.'): self
    {
        return new self(false, true, failureCode: 'outcome_unknown', failureReason: mb_substr($reason, 0, 500));
    }
}
