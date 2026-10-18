<?php

namespace App\Enums;

enum DiscountRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';
    // Withdrawn by the person who asked, or closed by an Administrator, before any decision.
    case Cancelled = 'cancelled';
    // A pending request no one decided in time, or a pre-sale approval no Sale spent in time.
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Declined => 'Declined',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    public function isDecided(): bool
    {
        return $this !== self::Pending;
    }
}
