<?php

namespace App\Enums;

enum InventoryMovementType: string
{
    case Initial = 'initial';
    case Restock = 'restock';
    case Adjustment = 'adjustment';
    case Damage = 'damage';
    case Loss = 'loss';
    case Correction = 'correction';
}
