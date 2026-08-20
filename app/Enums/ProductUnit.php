<?php

namespace App\Enums;

enum ProductUnit: string
{
    case Piece = 'piece';
    case Pair = 'pair';
    case Set = 'set';
    case Pack = 'pack';
    case Box = 'box';
    case Litre = 'litre';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
