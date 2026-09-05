<?php

namespace App\Support;

use App\Enums\PaymentStatus;
use App\Models\Sale;

final class SaleFinancials
{
    public static function lockedState(Sale $sale): array
    {
        $payments = Money::round((string) $sale->payments()->lockForUpdate()->sum('amount'));
        $returns = Money::round((string) $sale->returns()->lockForUpdate()->sum('merchandise_value'));
        $refunds = Money::round((string) $sale->refunds()->lockForUpdate()->sum('amount'));
        $obligation = bcsub($sale->total_amount, $returns, 2);
        $netCash = bcsub($payments, $refunds, 2);
        $balance = bccomp($obligation, $netCash, 2) > 0 ? bcsub($obligation, $netCash, 2) : '0.00';
        $credit = bccomp($netCash, $obligation, 2) > 0 ? bcsub($netCash, $obligation, 2) : '0.00';
        $status = bccomp($balance, '0.00', 2) === 0 ? PaymentStatus::Paid : (bccomp($netCash, '0.00', 2) > 0 ? PaymentStatus::Partial : PaymentStatus::Unpaid);

        return compact('payments', 'returns', 'refunds', 'obligation', 'netCash', 'balance', 'credit', 'status');
    }
}
