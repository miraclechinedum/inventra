<?php

namespace App\Services;

use App\Contracts\WhatsAppConnectionProvider;
use App\Models\WhatsAppConnection;
use App\Support\WhatsApp\ConnectionAttempt;
use App\Support\WhatsApp\GraphVersion;
use App\Support\WhatsApp\MetaOnboardingResult;
use App\Support\WhatsApp\OnboardingFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Meta WhatsApp Cloud API, implementing Embedded Signup (v4) onboarding for a Tech Provider as
 * Meta documents it:
 *
 *   1. The browser runs Embedded Signup (FB.login with the app's config_id, response_type 'code',
 *      extras.setup). Meta reports the WABA id and business phone number id through a
 *      WA_EMBEDDED_SIGNUP message event, and an exchangeable code — valid 30 seconds — through the
 *      login callback.
 *   2. GET /oauth/access_token exchanges that code, with the app id and APP SECRET, for a business
 *      integration system user token. The secret is used only here, server-side.
 *   3. GET /debug_token, authenticated with the app access token, proves the token is valid, was
 *      issued to this app, and carries whatsapp_business_management and whatsapp_business_messaging
 *      — for the claimed WABA where Meta scopes the grant to targets.
 *   4. GET /{waba-id} and GET /{waba-id}/phone_numbers, with that token, prove the WABA is
 *      accessible and the claimed number is one of its numbers.
 *   5. POST /{waba-id}/subscribed_apps and POST /{phone-number-id}/register (messaging_product and
 *      the six-digit two-step verification PIN) are the only calls that change anything at Meta,
 *      and the application makes them only after steps 2–4 and its own ownership checks.
 *
 * Every call uses the one configured Graph version. Failures are classified, never described with
 * Meta's own error body, and nothing here logs a token, a code, a PIN or the app secret.
 */
class MetaWhatsAppProvider implements WhatsAppConnectionProvider
{
    /** The permissions Embedded Signup must grant for Cloud API messaging on the customer's WABA. */
    private const REQUIRED_SCOPES = ['whatsapp_business_management', 'whatsapp_business_messaging'];

    public function name(): string
    {
        return 'meta';
    }

    /**
     * Application-level configuration only. Deliberately no phone number id: the sender identity
     * belongs to each Business's connection, not to the installation.
     */
    public function isConfigured(): bool
    {
        foreach (['app_id', 'app_secret', 'config_id'] as $key) {
            $value = config('whatsapp.'.$key);

            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return GraphVersion::isValid(config('whatsapp.graph_version'));
    }

    public function verifyOnboarding(string $code, string $claimedWabaId, string $claimedPhoneNumberId): MetaOnboardingResult
    {
        if (! $this->isConfigured()) {
            return MetaOnboardingResult::failed(OnboardingFailure::NotConfigured);
        }

        if (trim($code) === '' || ! ctype_digit($claimedWabaId) || ! ctype_digit($claimedPhoneNumberId)) {
            return MetaOnboardingResult::failed(OnboardingFailure::ExchangeFailed);
        }

        try {
            // ── Exchange the code for the business token ──
            $exchange = $this->client()->get('oauth/access_token', [
                'client_id' => (string) config('whatsapp.app_id'),
                'client_secret' => (string) config('whatsapp.app_secret'),
                'code' => $code,
            ]);

            $token = $exchange->successful() ? data_get($exchange->json(), 'access_token') : null;

            if (! is_string($token) || $token === '') {
                $this->logFailure('token_exchange', $exchange->json());

                return MetaOnboardingResult::failed(OnboardingFailure::ExchangeFailed);
            }

            // ── Prove the token: valid, ours, and carrying the WhatsApp permissions ──
            $inspected = $this->client()->get('debug_token', [
                'input_token' => $token,
                'access_token' => config('whatsapp.app_id').'|'.config('whatsapp.app_secret'),
            ]);

            if (($failure = $this->tokenFailure($inspected->successful() ? data_get($inspected->json(), 'data') : null, $claimedWabaId)) !== null) {
                $this->logFailure('debug_token', $inspected->json());

                return MetaOnboardingResult::failed($failure);
            }

            $expiresAt = (int) data_get($inspected->json(), 'data.expires_at', 0);

            // ── Prove the assets with that token, rather than believing the browser ──
            $account = $this->client()->withToken($token)->get($claimedWabaId, ['fields' => 'id,name']);

            if (! $account->successful() || (string) data_get($account->json(), 'id') !== $claimedWabaId) {
                $this->logFailure('waba', $account->json());

                return MetaOnboardingResult::failed(OnboardingFailure::WabaInaccessible);
            }

            $numbers = $this->client()->withToken($token)
                ->get($claimedWabaId.'/phone_numbers', ['fields' => 'id,display_phone_number,verified_name']);

            if (! $numbers->successful()) {
                $this->logFailure('phone_numbers', $numbers->json());

                return MetaOnboardingResult::failed(OnboardingFailure::WabaInaccessible);
            }
        } catch (ConnectionException) {
            return MetaOnboardingResult::failed(OnboardingFailure::ProviderUnreachable);
        }

        // Exactly the number Embedded Signup named, and only if Meta lists it under that WABA.
        // There is no fallback to some other number of the account.
        $number = collect((array) data_get($numbers->json(), 'data', []))
            ->first(fn ($row): bool => is_array($row) && (string) ($row['id'] ?? '') === $claimedPhoneNumberId);

        if ($number === null) {
            return MetaOnboardingResult::failed(OnboardingFailure::PhoneNotInWaba);
        }

        return MetaOnboardingResult::confirmed(
            wabaId: $claimedWabaId,
            phoneNumberId: $claimedPhoneNumberId,
            displayPhoneNumber: is_string($number['display_phone_number'] ?? null) ? $number['display_phone_number'] : null,
            verifiedName: is_string($number['verified_name'] ?? null) ? $number['verified_name'] : null,
            // Embedded Signup reports the business portfolio id only to the browser, which is a
            // claim; nothing verified here returns it, so none is recorded.
            metaBusinessId: null,
            accessToken: $token,
            tokenExpiresAt: $expiresAt > 0 ? now()->setTimestamp($expiresAt) : null,
        );
    }

    /** Why debug_token's answer disqualifies the token, or null when it proves everything needed. */
    private function tokenFailure(mixed $data, string $wabaId): ?OnboardingFailure
    {
        if (! is_array($data) || ($data['is_valid'] ?? false) !== true) {
            return OnboardingFailure::TokenInvalid;
        }

        if ((string) ($data['app_id'] ?? '') !== (string) config('whatsapp.app_id')) {
            return OnboardingFailure::WrongApp;
        }

        $scopes = array_filter((array) ($data['scopes'] ?? []), 'is_string');

        foreach (self::REQUIRED_SCOPES as $scope) {
            if (! in_array($scope, $scopes, true)) {
                return OnboardingFailure::MissingPermission;
            }

            // Where Meta scopes a grant to specific assets, the claimed WABA must be among them.
            foreach ((array) ($data['granular_scopes'] ?? []) as $grant) {
                $targets = is_array($grant) ? ($grant['target_ids'] ?? null) : null;

                if (is_array($grant) && ($grant['scope'] ?? null) === $scope && is_array($targets) && $targets !== []
                    && ! in_array($wabaId, array_map('strval', $targets), true)) {
                    return OnboardingFailure::WabaInaccessible;
                }
            }
        }

        return null;
    }

    public function subscribeApp(string $accessToken, string $wabaId): bool
    {
        try {
            return $this->client()->withToken($accessToken)->post($wabaId.'/subscribed_apps')->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    public function unsubscribeApp(string $accessToken, string $wabaId): bool
    {
        try {
            return $this->client()->withToken($accessToken)->delete($wabaId.'/subscribed_apps')->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    public function registerPhoneNumber(string $accessToken, string $phoneNumberId, string $pin): ?OnboardingFailure
    {
        try {
            $response = $this->client()->withToken($accessToken)->post($phoneNumberId.'/register', [
                'messaging_product' => 'whatsapp',
                'pin' => $pin,
            ]);
        } catch (ConnectionException) {
            return OnboardingFailure::ProviderUnreachable;
        }

        if ($response->successful() && data_get($response->json(), 'success') === true) {
            return null;
        }

        $this->logFailure('register', $response->json());

        return match ((string) data_get($response->json(), 'error.code')) {
            // Meta allows ten registrations per number in a 72-hour window.
            '133016' => OnboardingFailure::RegistrationRateLimited,
            // Two-step verification PIN mismatch.
            '133005' => OnboardingFailure::PinRejected,
            default => OnboardingFailure::RegistrationFailed,
        };
    }

    public function messageTemplates(WhatsAppConnection $connection): ?array
    {
        $wabaId = $connection->waba_id;
        $token = $connection->access_token;

        if (! is_string($wabaId) || $wabaId === '' || ! is_string($token) || $token === '') {
            return null;
        }

        $templates = [];
        $after = null;

        try {
            // Bounded, so a misbehaving cursor can never loop forever.
            for ($page = 0; $page < 20; $page++) {
                $response = $this->client()->withToken($token)->get($wabaId.'/message_templates', array_filter([
                    'fields' => 'name,language,status',
                    'limit' => 100,
                    'after' => $after,
                ]));

                if (! $response->successful()) {
                    $this->logFailure('message_templates', $response->json());

                    return null;
                }

                foreach ((array) data_get($response->json(), 'data', []) as $row) {
                    if (is_array($row) && is_string($row['name'] ?? null) && is_string($row['language'] ?? null) && is_string($row['status'] ?? null)) {
                        $templates[] = ['name' => $row['name'], 'language' => $row['language'], 'status' => $row['status']];
                    }
                }

                $after = data_get($response->json(), 'paging.cursors.after');

                if (! is_string($after) || data_get($response->json(), 'paging.next') === null) {
                    break;
                }
            }
        } catch (ConnectionException) {
            return null;
        }

        return $templates;
    }

    public function sendTemplate(
        WhatsAppConnection $connection,
        string $to,
        string $templateName,
        string $language,
        array $parameters,
    ): ConnectionAttempt {
        // The sender is this connection's own number, authenticated with this connection's own
        // token. There is no path by which one connection's message can leave another's number.
        $phoneNumberId = $connection->phone_number_id;
        $token = $connection->access_token;

        if (! is_string($phoneNumberId) || $phoneNumberId === '' || ! is_string($token) || $token === '') {
            return ConnectionAttempt::rejected('not_connected', 'This WhatsApp connection needs attention.');
        }

        $components = $parameters === [] ? [] : [[
            'type' => 'body',
            'parameters' => array_map(
                fn (string $value): array => ['type' => 'text', 'text' => $value],
                array_values($parameters),
            ),
        ]];

        try {
            $response = $this->client()->withToken($token)->post($phoneNumberId.'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($to, '+'),
                'type' => 'template',
                'template' => array_filter([
                    'name' => $templateName,
                    'language' => ['code' => $language],
                    'components' => $components === [] ? null : $components,
                ]),
            ]);
        } catch (ConnectionException) {
            // Never a rejection: the message may well have gone out, and resending on this is how a
            // customer receives the same message twice.
            return ConnectionAttempt::unknown('The provider could not be reached.');
        }

        if (! $response->successful()) {
            $code = data_get($response->json(), 'error.code');
            $message = data_get($response->json(), 'error.message');

            return ConnectionAttempt::rejected(
                is_scalar($code) ? (string) $code : 'provider_rejected',
                is_string($message) ? $message : 'The provider rejected the message.',
            );
        }

        $messageId = data_get($response->json(), 'messages.0.id');

        if (! is_string($messageId) || $messageId === '' || mb_strlen($messageId) > 255) {
            return ConnectionAttempt::unknown('The provider response could not be reconciled.');
        }

        return ConnectionAttempt::accepted($messageId);
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl('https://graph.facebook.com/'.GraphVersion::configured())
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('whatsapp.connect_timeout', 3))
            ->timeout((int) config('whatsapp.timeout', 10))
            ->withOptions(['allow_redirects' => false]);
    }

    /**
     * Sanitised diagnostics. Only Meta's own error code and type are recorded — never the message
     * body, never the payload, and never anything that could carry a token.
     */
    private function logFailure(string $stage, mixed $payload): void
    {
        Log::warning('Meta WhatsApp onboarding step failed.', [
            'stage' => $stage,
            'error_code' => is_array($payload) ? data_get($payload, 'error.code') : null,
            'error_type' => is_array($payload) ? data_get($payload, 'error.type') : null,
        ]);
    }
}
