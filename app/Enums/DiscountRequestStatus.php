<?php

namespace App\Enums;

enum DiscountRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Declined => 'Declined',
        };
    }

    public function isDecided(): bool
    {
        return $this !== self::Pending;
    }
}
