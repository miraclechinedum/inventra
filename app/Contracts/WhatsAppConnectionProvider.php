<?php

namespace App\Contracts;

use App\Models\WhatsAppConnection;
use App\Support\WhatsApp\ConnectionAttempt;
use App\Support\WhatsApp\MetaOnboardingResult;
use App\Support\WhatsApp\OnboardingFailure;

/**
 * The WhatsApp provider, as the application sees it.
 *
 *  - Onboarding is split so the application can decide between steps: `verifyOnboarding()` has no
 *    side effects at Meta and proves the token and the assets; only then is the account checked
 *    against every other Business, and only then are `subscribeApp()` and `registerPhoneNumber()`
 *    allowed to change anything at Meta.
 *  - Sending takes the connection. The sender is `$connection->phone_number_id` authenticated with
 *    `$connection->access_token`, so one Business can never send as another.
 *  - No method ever returns, logs or throws a credential, an authorization code or a PIN.
 */
interface WhatsAppConnectionProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * Exchanges the Embedded Signup code server-side, validates the resulting token for this app
     * and its WhatsApp permissions, and proves with it that the claimed WABA is accessible and the
     * claimed number belongs to it. Browser-supplied identifiers are claims, never facts.
     */
    public function verifyOnboarding(string $code, string $claimedWabaId, string $claimedPhoneNumberId): MetaOnboardingResult;

    /** Subscribes this app to a verified WABA's webhooks. Safe to repeat. */
    public function subscribeApp(string $accessToken, string $wabaId): bool;

    /** Removes this app's webhook subscription from a WABA. Best effort. */
    public function unsubscribeApp(string $accessToken, string $wabaId): bool;

    /**
     * Registers a verified number for Cloud API messaging with its two-step verification PIN.
     * Returns null on success, otherwise why it failed. The PIN is used for this call only.
     */
    public function registerPhoneNumber(string $accessToken, string $phoneNumberId, string $pin): ?OnboardingFailure;

    /**
     * The message templates of the connection's own WABA, as Meta reports them.
     *
     * @return list<array{name: string, language: string, status: string}>|null null when Meta could not be read
     */
    public function messageTemplates(WhatsAppConnection $connection): ?array;

    public function sendTemplate(
        WhatsAppConnection $connection,
        string $to,
        string $templateName,
        string $language,
        array $parameters,
    ): ConnectionAttempt;
}
