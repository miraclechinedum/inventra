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
    case Sale = 'sale';
    case SaleVoid = 'sale_void';
    case Purchase = 'purchase';
}
