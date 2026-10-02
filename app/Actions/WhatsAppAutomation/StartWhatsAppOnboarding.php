<?php

namespace App\Actions\WhatsAppAutomation;

use App\Models\User;
use App\Models\WhatsAppOnboardingAttempt;
use App\Tenancy\CurrentBusiness;
use Illuminate\Support\Str;

/**
 * Issues the server-side state an Embedded Signup attempt must present to complete.
 *
 * The Business comes from the Administrator's persisted account, never from the request. The
 * returned value is random and shown once; only its SHA-256 is stored, together with the
 * Administrator and a hash of their session, so the state proves nothing to anyone else.
 */
class StartWhatsAppOnboarding
{
    public function __construct(private readonly CurrentBusiness $tenancy) {}

    public function execute(User $actor, string $sessionId): string
    {
        $business = $this->tenancy->forActor($actor);
        $state = Str::random(64);

        $attempt = new WhatsAppOnboardingAttempt;
        $attempt->forceFill([
            'business_id' => $business->getKey(),
            'user_id' => $actor->getKey(),
            'state_hash' => hash('sha256', $state),
            'session_hash' => hash('sha256', $sessionId),
            'expires_at' => now()->addMinutes(WhatsAppOnboardingAttempt::LIFETIME_MINUTES),
        ])->save();

        return $state;
    }
}
