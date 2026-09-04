<?php

namespace App\Support;

final class ExpenseNumber
{
    public static function fromId(int $id): string
    {
        return 'EXP-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }
}
