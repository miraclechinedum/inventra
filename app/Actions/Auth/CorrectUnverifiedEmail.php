<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SecurityEventRecorder;
use App\Support\UnavailableIdentifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Replaces a signup owner's still-unverified email address.
 *
 * The new address is unproven, so it is recorded unverified and the account remains one that must
 * verify. Any link issued for the old address stops working, because a verification link carries a
 * hash of the address it was sent to. Evidence records that the email changed, never either
 * address, and never the password.
 */
class CorrectUnverifiedEmail
{
    public function __construct(
        private readonly SecurityEventRecorder $events,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $user, string $email): User
    {
        if (! $user->owesEmailVerification()) {
            throw new LogicException('Only an account that still owes verification may correct its email here.');
        }

        try {
            return DB::transaction(function () use ($user, $email): User {
                $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

                $locked->email = $email;
                $locked->email_verified_at = null;
                $locked->email_verification_required = true;
                $locked->save();

                $this->events->record('unverified_email_corrected', $locked, ['changed_fields' => 'email'], $locked);
                $this->audit->record('unverified_email_corrected', $locked, $locked);

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            // Taken between validation and the write: the same neutral words as validation.
            throw ValidationException::withMessages(['email' => UnavailableIdentifier::EMAIL]);
        }
    }
}
