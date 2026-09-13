<?php

namespace App\Enums;

enum WhatsAppDeliveryOrigin: string
{
    /** A staff member pressed Send or Retry on the Sale. */
    case Manual = 'manual';

    /** The Sale qualified on completion and the scheduler dispatched it. */
    case Automatic = 'automatic';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Automatic => 'Automatic',
        };
    }
}
