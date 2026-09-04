<?php

namespace App\Support;

final class PurchaseNumber
{
    public static function fromId(int $id): string
    {
        return 'PUR-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }
}
