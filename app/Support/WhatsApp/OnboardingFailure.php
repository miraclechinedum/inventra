<?php

namespace App\Support\WhatsApp;

/**
 * Why an Embedded Signup attempt did not connect. The value is recorded on the attempt; the message
 * is what the Administrator sees. Neither ever carries a code, a token, a PIN or a Meta error body.
 *
 * `StateInvalid` deliberately covers an expired, replayed, foreign-operator or foreign-Business
 * state alike, and `AccountUnavailable` a WABA or number held by another Business: neither says
 * more than that the attempt cannot proceed.
 */
enum OnboardingFailure: string
{
    case NotConfigured = 'not_configured';
    case NotEntitled = 'not_entitled';
    case StateInvalid = 'state_invalid';
    case ExchangeFailed = 'exchange_failed';
    case ProviderUnreachable = 'provider_unreachable';
    case TokenInvalid = 'token_invalid';
    case WrongApp = 'wrong_app';
    case MissingPermission = 'missing_permission';
    case WabaInaccessible = 'waba_inaccessible';
    case PhoneNotInWaba = 'phone_not_in_waba';
    case AccountUnavailable = 'account_unavailable';
    case SubscriptionFailed = 'subscription_failed';
    case RegistrationFailed = 'registration_failed';
    case RegistrationRateLimited = 'registration_rate_limited';
    case PinRejected = 'pin_rejected';

    public function message(): string
    {
        return match ($this) {
            self::NotConfigured => 'Unable to start Meta connection. WhatsApp is not configured for this installation.',
            self::NotEntitled => 'WhatsApp automation is not available on your current plan.',
            self::StateInvalid => 'This connection attempt has expired. Start the connection again.',
            self::ExchangeFailed => 'Meta did not confirm the connection. Start the connection again.',
            self::ProviderUnreachable => 'We couldn\'t reach Meta. Please try again in a moment.',
            self::TokenInvalid, self::WrongApp => 'Meta did not grant a valid connection for Inventra. Start the connection again.',
            self::MissingPermission => 'Meta did not grant the WhatsApp permissions Inventra needs. Start again and allow every requested permission.',
            self::WabaInaccessible => 'Meta did not give access to the selected WhatsApp Business Account.',
            self::PhoneNotInWaba => 'The selected number does not belong to the selected WhatsApp Business Account.',
            self::AccountUnavailable => 'This WhatsApp Business Account can\'t be connected here.',
            self::SubscriptionFailed => 'Meta did not let Inventra receive updates for this account. Start the connection again.',
            self::RegistrationFailed => 'Meta did not register the number for messaging. Start the connection again.',
            self::RegistrationRateLimited => 'Meta has paused registration for this number after repeated attempts. Try again in 72 hours.',
            self::PinRejected => 'Meta did not accept that two-step verification PIN. Check the PIN and start again.',
        };
    }
}
