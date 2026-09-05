<?php

namespace App\Enums;

enum ReturnDisposition: string
{
    case Restock = 'restock';
    case NonRestock = 'non_restock';

    public function label(): string
    {
        return $this === self::Restock ? 'Restock' : 'Do not restock';
    }
}
