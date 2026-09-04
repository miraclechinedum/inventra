<?php

namespace App\Support;

final class ExpenseCategoryCode
{
    public static function fromId(int $id): string
    {
        return 'EXPCAT-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }
}
