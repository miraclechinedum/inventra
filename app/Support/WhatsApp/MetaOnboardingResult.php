<?php

namespace App\Support\WhatsApp;

/**
 * What server-side verification of an Embedded Signup result established.
 *
 * Confirmed only when the code was exchanged, the token was proven valid for this app with the
 * WhatsApp permissions, and Meta itself served the WABA and the number within it. The token is
 * carried in memory to the final write and never logged or serialised.
 */
final class MetaOnboardingResult
{
    private function __construct(
        public readonly bool $confirmed,
        public readonly ?string $wabaId = null,
        public readonly ?string $phoneNumberId = null,
        public readonly ?string $displayPhoneNumber = null,
        public readonly ?string $verifiedName = null,
        public readonly ?string $metaBusinessId = null,
        public readonly ?string $accessToken = null,
        public readonly ?\DateTimeInterface $tokenExpiresAt = null,
        public readonly ?OnboardingFailure $failure = null,
    ) {}

    public static function confirmed(
        string $wabaId,
        string $phoneNumberId,
        ?string $displayPhoneNumber,
        ?string $verifiedName,
        ?string $metaBusinessId,
        string $accessToken,
        ?\DateTimeInterface $tokenExpiresAt = null,
    ): self {
        return new self(true, $wabaId, $phoneNumberId, $displayPhoneNumber, $verifiedName,
            $metaBusinessId, $accessToken, $tokenExpiresAt);
    }

    public static function failed(OnboardingFailure $failure): self
    {
        return new self(false, failure: $failure);
    }

    /** Operator-facing: never a token, payload or stack trace. */
    public function reason(): ?string
    {
        return $this->failure?->message();
    }
}
