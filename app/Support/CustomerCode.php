<?php

namespace App\Support;

use InvalidArgumentException;

final class CustomerCode
{
    public static function fromId(int $id): string
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Customer ID must be positive.');
        }

        return 'CUST-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }
}
