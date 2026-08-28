<?php

namespace App\Enums;

enum WhatsAppDeliveryStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
    case Unresolved = 'unresolved';

    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Accepted => 1,
            self::Sent => 2,
            self::Delivered => 3,
            self::Read => 4,
            self::Failed => 5,
            self::Unresolved => 6,
        };
    }
}
