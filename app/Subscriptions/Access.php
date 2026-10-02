<?php

namespace App\Subscriptions;

/**
 * What a Business may do right now, commercially. One answer for the browser and the scheduler.
 *
 *  - Full: everything its plan grants.
 *  - Grace: everything its plan grants, with a warning that it will soon become restricted.
 *  - Restricted: read-only. Every record stays readable and exportable; nothing changes, and no
 *    paid capability (WhatsApp sending) runs, until the subscription is active again.
 */
enum Access: string
{
    case Full = 'full';
    case Grace = 'grace';
    case Restricted = 'restricted';

    public function permitsWrites(): bool
    {
        return $this !== self::Restricted;
    }
}
