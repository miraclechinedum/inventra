<?php

namespace App\Support;

use App\Models\Sale;

final class WhatsAppReceiptTemplate
{
    /** @return array<string, string> */
    public static function parameters(Sale $sale): array
    {
        return [
            'customer_name' => $sale->customer_name_snapshot,
            'sale_number' => $sale->sale_number,
            'total_amount' => Money::format($sale->total_amount),
            'payment_status' => ucfirst($sale->payment_status->value),
            'sold_by_name' => $sale->sold_by_name_snapshot,
        ];
    }
}
