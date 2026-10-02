<?php

namespace App\Actions\Profile;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SecurityEventRecorder;
use App\Support\UnavailableIdentifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies a staff member's own name, email and phone.
 *
 * Deliberately the same shape as StaffController::update — one transaction, a security event and an
 * audit row carrying only what changed — so Inventra keeps one convention for user updates rather
 * than two that could drift apart. What differs is the authority: there is no separate subject, so
 * the account being changed and the actor are the same row, and only the three self-service columns
 * are ever assigned.
 *
 * The three fields are assigned individually rather than through fill(), so no key beyond them can
 * reach the model even if a caller hands over a wider array.
 */
class UpdateOwnProfile
{
    /** The only columns this action will ever write. */
    private const FIELDS = ['name', 'email', 'phone'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SecurityEventRecorder $events,
    ) {}

    /**
     * @param  array{name: string, email: string, phone: ?string}  $data  validated, already canonicalised
     * @return array<string, string|null> the fields that changed; empty when the submission was a no-op
     *
     * @throws ValidationException when the email or phone was taken between validating and saving
     */
    public function execute(User $user, array $data): array
    {
        // Only the allowlisted keys, whatever else the caller passed.
        $incoming = array_intersect_key($data, array_flip(self::FIELDS));

        try {
            $changed = DB::transaction(function () use ($user, $incoming): array {
                // Re-read under a row lock so two tabs saving at once cannot both diff against a
                // stale before-image and write a half-correct audit trail.
                $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

                $old = [];
                $new = [];

                foreach (self::FIELDS as $field) {
                    if (! array_key_exists($field, $incoming)) {
                        continue;
                    }

                    if ($locked->{$field} !== $incoming[$field]) {
                        $old[$field] = $locked->{$field};
                        $locked->{$field} = $incoming[$field];
                        // Read back off the model: the email and phone mutators canonicalise on
                        // assignment, so this records what was actually stored.
                        $new[$field] = $locked->{$field};
                    }
                }

                // An unchanged submission writes nothing and records nothing. An audit row whose
                // before and after are identical is not evidence of anything.
                if ($new === []) {
                    return [];
                }

                // A new address an owner must prove is unproven until its own link is opened.
                if (array_key_exists('email', $new) && $locked->email_verification_required) {
                    $locked->email_verified_at = null;
                }

                $locked->save();

                $this->events->record('profile_updated', $locked, [
                    'changed_fields' => implode(',', array_keys($new)),
                ], $locked);

                $this->audit->record('profile_updated', $locked, $locked, $old, $new, explicitDiff: true);

                // Keep the caller's instance in step so the redirect renders the new values.
                $user->forceFill($locked->only([...self::FIELDS, 'email_verified_at']))->syncOriginal();

                return $new;
            });

            // After commit, and never fatal: the owner can ask for the link again.
            if ($user->owesEmailVerification() && array_key_exists('email', $changed)) {
                rescue(fn () => $user->sendEmailVerificationNotification(), report: false);
            }

            return $changed;
        } catch (QueryException $exception) {
            // The unique indexes are the real authority. Validation checked a moment earlier, so a
            // 23000 here means somebody else claimed the address or number in between — reported as
            // a field error, never as a database message.
            if (($exception->errorInfo[0] ?? null) !== '23000') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'email' => UnavailableIdentifier::EITHER,
            ]);
        }
    }
}
