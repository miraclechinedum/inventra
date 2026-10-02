<?php

namespace App\Actions\WhatsAppAutomation;

use App\Contracts\WhatsAppConnectionProvider;
use App\Models\Business;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppOnboardingAttempt;
use App\Services\AuditLogger;
use App\Subscriptions\Entitlement;
use App\Subscriptions\Entitlements;
use App\Support\WhatsApp\MetaOnboardingResult;
use App\Support\WhatsApp\OnboardingFailure;
use App\Tenancy\CurrentBusiness;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Completes an Embedded Signup attempt for the Administrator's own Business.
 *
 * Nothing the browser says is taken as fact. In order:
 *
 *   1. The server-issued state is consumed: it must be this Business's, this Administrator's, from
 *      this session, unexpired and unused. It is spent on first valid presentation, whatever
 *      happens next, so it can never be replayed.
 *   2. The provider exchanges the code server-side, validates the token for this app and its
 *      WhatsApp permissions, and proves the WABA is accessible and the number is one of its own.
 *      Nothing at Meta has changed yet.
 *   3. The account is checked against every other Business. A WABA or number another Business
 *      holds is refused before Inventra subscribes to it or registers it.
 *   4. The app is subscribed to the WABA and the number registered with the two-step verification
 *      PIN the Administrator entered. The PIN is used for that one call and never stored, logged
 *      or returned.
 *   5. One short transaction re-checks ownership under lock and writes the connection, the
 *      Business's default automations and the audit row together. Only here does the connection
 *      become connected.
 *
 * No network call is made inside a database transaction. A failure at any step leaves the
 * connection exactly as it was, records a classified reason on the spent attempt, and tells the
 * Administrator what to do without echoing Meta's error.
 */
class CompleteMetaOnboarding
{
    public function __construct(
        private readonly WhatsAppConnectionProvider $provider,
        private readonly AuditLogger $audit,
        private readonly CurrentBusiness $tenancy,
        private readonly EnsureDefaultAutomations $defaults,
        private readonly SyncWhatsAppTemplates $templates,
        private readonly Entitlements $entitlements,
    ) {}

    public function execute(
        User $actor,
        string $state,
        string $sessionId,
        string $code,
        string $wabaId,
        string $phoneNumberId,
        string $pin,
    ): WhatsAppConnection {
        if (! $this->provider->isConfigured()) {
            throw $this->refusal(OnboardingFailure::NotConfigured);
        }

        $business = $this->tenancy->forActor($actor);

        if (! $this->entitlements->allows($business, Entitlement::WhatsAppAutomation)) {
            throw $this->refusal(OnboardingFailure::NotEntitled);
        }

        $attempt = $this->consume($actor, $business, $state, $sessionId)
            ?? throw $this->refusal(OnboardingFailure::StateInvalid);

        $outcome = $this->provider->verifyOnboarding($code, $wabaId, $phoneNumberId);

        if (! $outcome->confirmed) {
            throw $this->fail($attempt, $outcome->failure ?? OnboardingFailure::ExchangeFailed);
        }

        if ($this->claimedElsewhere($business, $outcome, lock: false)) {
            throw $this->fail($attempt, OnboardingFailure::AccountUnavailable);
        }

        if (! $this->provider->subscribeApp((string) $outcome->accessToken, (string) $outcome->wabaId)) {
            throw $this->fail($attempt, OnboardingFailure::SubscriptionFailed);
        }

        if (($failure = $this->provider->registerPhoneNumber((string) $outcome->accessToken, (string) $outcome->phoneNumberId, $pin)) !== null) {
            throw $this->fail($attempt, $failure);
        }

        try {
            $connection = DB::transaction(fn (): ?WhatsAppConnection => $this->connect($actor, $business, $attempt, $outcome));
        } catch (UniqueConstraintViolationException) {
            // Another Business claimed the account, or this one connected concurrently, between
            // the check and the write. Nothing was written.
            $connection = null;
        }

        if ($connection === null) {
            throw $this->fail($attempt, OnboardingFailure::AccountUnavailable);
        }

        // Best effort, after commit: the connection stands even if Meta's template list cannot be
        // read right now, and it can be refreshed from the page later.
        try {
            $this->templates->execute($business, $actor);
        } catch (Throwable $exception) {
            Log::warning('WhatsApp templates could not be synchronised after connecting.', [
                'business_id' => $business->getKey(), 'exception' => $exception::class,
            ]);
        }

        return $connection->refresh();
    }

    /** The attempt, spent — or null if this state is not this operator's to spend. */
    private function consume(User $actor, Business $business, string $state, string $sessionId): ?WhatsAppOnboardingAttempt
    {
        return DB::transaction(function () use ($actor, $business, $state, $sessionId): ?WhatsAppOnboardingAttempt {
            $attempt = WhatsAppOnboardingAttempt::query()
                ->where('business_id', $business->getKey())
                ->where('state_hash', hash('sha256', $state))
                ->lockForUpdate()
                ->first();

            // Every binding is checked the same way and fails the same way: a stranger's state, a
            // colleague's, another session's, a spent one and an expired one are indistinguishable.
            if ($attempt === null
                || (int) $attempt->user_id !== (int) $actor->getKey()
                || ! hash_equals($attempt->session_hash, hash('sha256', $sessionId))
                || $attempt->consumed_at !== null
                || $attempt->expires_at->isPast()) {
                return null;
            }

            $attempt->forceFill(['consumed_at' => now()])->save();

            return $attempt;
        });
    }

    private function claimedElsewhere(Business $business, MetaOnboardingResult $outcome, bool $lock): bool
    {
        return WhatsAppConnection::acrossBusinesses()
            ->where('business_id', '<>', $business->getKey())
            ->where(fn ($query) => $query->where('waba_id', $outcome->wabaId)->orWhere('phone_number_id', $outcome->phoneNumberId))
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->exists();
    }

    /** The final write. Null when another Business turned out to hold the account. */
    private function connect(User $actor, Business $business, WhatsAppOnboardingAttempt $attempt, MetaOnboardingResult $outcome): ?WhatsAppConnection
    {
        if ($this->claimedElsewhere($business, $outcome, lock: true)) {
            return null;
        }

        // One connection per Business: reconnecting — the same account or another — updates
        // this Business's row, and UNIQUE business_id holds that under concurrency too.
        $connection = WhatsAppConnection::query()->where('business_id', $business->getKey())->lockForUpdate()->first()
            ?? (new WhatsAppConnection)->forceFill(['business_id' => $business->getKey(), 'singleton_key' => 'whatsapp']);

        $now = now();
        $connection->forceFill([
            'provider' => $this->provider->name(),
            'waba_id' => $outcome->wabaId,
            'phone_number_id' => $outcome->phoneNumberId,
            'display_phone_number' => $outcome->displayPhoneNumber,
            'verified_name' => $outcome->verifiedName,
            'meta_business_id' => $outcome->metaBusinessId,
            'access_token' => $outcome->accessToken,
            'token_expires_at' => $outcome->tokenExpiresAt,
            // The number the page shows is Meta's, never anything typed into the form.
            'phone_number' => $outcome->displayPhoneNumber !== null
                ? '+'.preg_replace('/\D/', '', $outcome->displayPhoneNumber)
                : $connection->phone_number,
            'status' => 'connected',
            'verified_at' => $now,
            'connected_at' => $now,
            'disconnected_at' => null,
            'failure_reason' => null,
            'connected_by' => $actor->id,
            'verification_code_hash' => null,
            'verification_phone' => null,
            'verification_expires_at' => null,
            'verification_attempts' => 0,
            'verification_sends' => 0,
            'verification_last_sent_at' => null,
        ])->save();

        $this->defaults->for($business);

        DB::table('whatsapp_onboarding_attempts')->where('business_id', $business->getKey())->where('id', $attempt->id)
            ->update(['whatsapp_connection_id' => $connection->id, 'updated_at' => $now]);

        // Identity only. No token, no code, no PIN: nothing that could authenticate as the business.
        $this->audit->record('whatsapp_connected', $connection, $actor, newValues: [
            'provider' => $connection->provider,
            'status' => 'connected',
        ]);

        return $connection;
    }

    private function fail(WhatsAppOnboardingAttempt $attempt, OnboardingFailure $failure): ValidationException
    {
        DB::table('whatsapp_onboarding_attempts')->where('business_id', $attempt->business_id)->where('id', $attempt->id)
            ->update(['failure_code' => $failure->value, 'updated_at' => now()]);

        Log::warning('A WhatsApp connection attempt did not complete.', [
            'business_id' => $attempt->business_id, 'attempt_id' => $attempt->id, 'failure' => $failure->value,
        ]);

        return $this->refusal($failure);
    }

    private function refusal(OnboardingFailure $failure): ValidationException
    {
        return ValidationException::withMessages(['connection' => $failure->message()]);
    }
}
