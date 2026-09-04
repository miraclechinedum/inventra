<?php

namespace App\Support;

final class PaymentNumber
{
    public static function fromId(int $id): string
    {
        return 'PAY-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }
}
