<?php

namespace App\Policies;

use App\Models\OperationalAlertRecipient;
use App\Models\User;

/**
 * Two conditions, both required.
 *
 * Ownership: a notification belongs to the person it was delivered to. Being an Administrator
 * confers no authority over another operator's read or acknowledgement state, because that state is
 * a record of what *they* saw.
 *
 * Current entitlement: the holder's role must still be one this alert type is meant for. Delivery
 * decided who received it; this decides who may still read it. An Administrator demoted to Manager
 * loses access to the integrity warnings they were sent, and a Manager demoted to Sales
 * Representative loses the business-wide financial alerts — without any history being rewritten.
 */
class OperationalAlertRecipientPolicy
{
    public function view(User $user, OperationalAlertRecipient $recipient): bool
    {
        return $this->owns($user, $recipient) && $this->entitled($user, $recipient);
    }

    public function markRead(User $user, OperationalAlertRecipient $recipient): bool
    {
        return $this->owns($user, $recipient) && $this->entitled($user, $recipient);
    }

    public function acknowledge(User $user, OperationalAlertRecipient $recipient): bool
    {
        return $this->owns($user, $recipient) && $this->entitled($user, $recipient);
    }

    private function owns(User $user, OperationalAlertRecipient $recipient): bool
    {
        return (int) $recipient->user_id === (int) $user->getKey();
    }

    /** Entitlement comes from the one authoritative map on the alert type itself. */
    private function entitled(User $user, OperationalAlertRecipient $recipient): bool
    {
        return $recipient->alertType()?->allowsRole($user->role) ?? false;
    }
}
