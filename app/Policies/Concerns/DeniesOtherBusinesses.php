<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Denies every ability on a record owned by another Business, before any role rule is consulted.
 *
 * Tenant-owned models are already confined by their scope and route binding, so this should never
 * be the check that decides; it exists so that a record resolved some other way still cannot be
 * authorized across businesses. It returns null otherwise, leaving each policy's role rules intact.
 */
trait DeniesOtherBusinesses
{
    public function before(User $user, string $ability, mixed ...$arguments): ?bool
    {
        foreach ($arguments as $argument) {
            if ($argument instanceof Model && array_key_exists('business_id', $argument->getAttributes())
                && (int) $argument->getAttribute('business_id') !== (int) $user->business_id) {
                return false;
            }
        }

        return null;
    }
}
