<?php

namespace App\Support;

use InvalidArgumentException;

final class SaleNumber
{
    public static function fromId(int $id): string
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Sale ID must be positive.');
        }

        return 'SALE-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }
}
