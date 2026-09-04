<?php

namespace App\Support;

final class SupplierCode
{
    public static function fromId(int $id): string
    {
        return 'SUP-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }
}
