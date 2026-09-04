<?php

namespace App\Enums;

enum SalePaymentType: string
{
    case Initial = 'initial';
    case Settlement = 'settlement';
}
