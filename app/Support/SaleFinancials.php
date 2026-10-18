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
        // Never below zero: returns are valued net of the Sale's discount, so they cannot exceed its
        // total, and the database holds `returned_amount <= total_amount` as well. The floor keeps
        // a refund from ever being derived out of returns worth more than the Sale.
        $obligation = bcsub($sale->total_amount, $returns, 2);
        $obligation = bccomp($obligation, '0.00', 2) < 0 ? '0.00' : $obligation;
        $netCash = bcsub($payments, $refunds, 2);
        $balance = bccomp($obligation, $netCash, 2) > 0 ? bcsub($obligation, $netCash, 2) : '0.00';
        $credit = bccomp($netCash, $obligation, 2) > 0 ? bcsub($netCash, $obligation, 2) : '0.00';
        $status = bccomp($balance, '0.00', 2) === 0 ? PaymentStatus::Paid : (bccomp($netCash, '0.00', 2) > 0 ? PaymentStatus::Partial : PaymentStatus::Unpaid);

        return compact('payments', 'returns', 'refunds', 'obligation', 'netCash', 'balance', 'credit', 'status');
    }
}
