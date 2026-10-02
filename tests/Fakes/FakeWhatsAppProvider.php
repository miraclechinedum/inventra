<?php

namespace Tests\Fakes;

use App\Contracts\WhatsAppConnectionProvider;
use App\Models\WhatsAppConnection;
use App\Support\WhatsApp\ConnectionAttempt;
use App\Support\WhatsApp\MetaOnboardingResult;
use App\Support\WhatsApp\OnboardingFailure;

/**
 * A provider for tests ONLY. It is never bound outside the test environment, so no development or
 * production request can reach it — those use MetaWhatsAppProvider, which reports a truthful
 * failure when credentials are absent rather than faking success.
 *
 * It records the sender identity of every send (`phone_number_id` and token) so isolation can be
 * asserted: a test can prove Business A's message left A's number under A's token, and that B's
 * credentials were never used for it.
 */
class FakeWhatsAppProvider implements WhatsAppConnectionProvider
{
    /** @var list<array{phone_number_id: string, token: string, to: string, template: string, language: string, parameters: list<string>}> */
    public array $sent = [];

    /** @var list<string> */
    public array $subscribed = [];

    public bool $configured = true;

    public bool $rejectSends = false;

    public bool $outcomeUnknown = false;

    /** When set, verification fails at that step instead of confirming. */
    public ?OnboardingFailure $onboardingFailure = null;

    /** When set, number registration fails with this. */
    public ?OnboardingFailure $registrationFailure = null;

    public bool $subscriptionFails = false;

    /** What Meta "returns" for a verified onboarding. */
    public array $onboarding = [
        'display_phone_number' => '+234 700 000 1234',
        'verified_name' => 'AutoParts NG',
        'access_token' => 'fake-business-token',
    ];

    /**
     * WABA id => its phone number ids, as Meta would list them to the exchanged token. A claim
     * outside this map is exactly what the real provider refuses: an inaccessible WABA, or a
     * number that is not the WABA's.
     *
     * @var array<string, list<string>>
     */
    public array $accessible = [
        '100000000000001' => ['200000000000001'],
        '100000000000002' => ['200000000000002'],
    ];

    /** @var list<string> codes presented for exchange */
    public array $exchanged = [];

    /** @var list<string> */
    public array $unsubscribed = [];

    /** @var list<array{phone_number_id: string, token: string}> registrations, deliberately without the PIN */
    public array $registered = [];

    /** @var array<string, list<array{name: string, language: string, status: string}>> WABA id => its templates */
    public array $templates = [];

    public bool $templatesUnavailable = false;

    private int $counter = 0;

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function verifyOnboarding(string $code, string $claimedWabaId, string $claimedPhoneNumberId): MetaOnboardingResult
    {
        $this->exchanged[] = $code;

        if ($this->onboardingFailure !== null) {
            return MetaOnboardingResult::failed($this->onboardingFailure);
        }

        if (trim($code) === '') {
            return MetaOnboardingResult::failed(OnboardingFailure::ExchangeFailed);
        }

        if (! array_key_exists($claimedWabaId, $this->accessible)) {
            return MetaOnboardingResult::failed(OnboardingFailure::WabaInaccessible);
        }

        if (! in_array($claimedPhoneNumberId, $this->accessible[$claimedWabaId], true)) {
            return MetaOnboardingResult::failed(OnboardingFailure::PhoneNotInWaba);
        }

        return MetaOnboardingResult::confirmed(
            wabaId: $claimedWabaId,
            phoneNumberId: $claimedPhoneNumberId,
            displayPhoneNumber: $this->onboarding['display_phone_number'],
            verifiedName: $this->onboarding['verified_name'],
            metaBusinessId: null,
            accessToken: $this->onboarding['access_token'],
        );
    }

    public function subscribeApp(string $accessToken, string $wabaId): bool
    {
        if ($this->subscriptionFails) {
            return false;
        }

        $this->subscribed[] = $wabaId;

        return true;
    }

    public function unsubscribeApp(string $accessToken, string $wabaId): bool
    {
        $this->unsubscribed[] = $wabaId;

        return true;
    }

    public function registerPhoneNumber(string $accessToken, string $phoneNumberId, string $pin): ?OnboardingFailure
    {
        if ($this->registrationFailure !== null) {
            return $this->registrationFailure;
        }

        $this->registered[] = ['phone_number_id' => $phoneNumberId, 'token' => $accessToken];

        return null;
    }

    public function messageTemplates(WhatsAppConnection $connection): ?array
    {
        return $this->templatesUnavailable ? null : ($this->templates[(string) $connection->waba_id] ?? []);
    }

    public function sendTemplate(
        WhatsAppConnection $connection,
        string $to,
        string $templateName,
        string $language,
        array $parameters,
    ): ConnectionAttempt {
        if ($this->outcomeUnknown) {
            return ConnectionAttempt::unknown();
        }

        if ($this->rejectSends) {
            return ConnectionAttempt::rejected('fake_rejected', 'The provider rejected the message.');
        }

        // Recording the identity, not just the payload: this is what isolation tests assert on.
        $this->sent[] = [
            'phone_number_id' => (string) $connection->phone_number_id,
            'token' => (string) $connection->access_token,
            'to' => $to,
            'template' => $templateName,
            'language' => $language,
            'parameters' => array_values($parameters),
        ];

        return ConnectionAttempt::accepted('fake-message-'.(++$this->counter));
    }
}
