<?php

namespace App\Support;

use App\Enums\PaymentStatus;
use App\Models\Sale;

/**
 * The one description of what a receipt says about a Sale.
 *
 * The printable receipt and the PDF must never disagree, so neither computes anything: both read
 * this, and this reads only columns the Sale already carries. Nothing here re-derives a total, a
 * balance or a discount — those were settled by CreateSale, DecideSaleDiscount and SaleFinancials
 * when the Sale was written, and a receipt that recalculated them could contradict the ledger.
 *
 * It also decides what a receipt may *not* show. Cost price, internal notes, audit and security
 * columns are simply never read here, so no receipt surface can leak them by accident.
 */
final class ReceiptPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Sale $sale): array
    {
        $sale->loadMissing('items');

        $hasDiscount = bccomp((string) $sale->discount_amount, '0', 2) > 0;
        // Which money lines are truthful for this Sale, in the order a receipt reads them. An
        // unpaid Sale must not print "Amount paid"; a fully paid one has no balance to show. Built
        // here rather than in the template so the printable receipt and the PDF cannot diverge.
        $moneyLines = [['Subtotal', (string) $sale->subtotal, false]];

        if ($hasDiscount) {
            $moneyLines[] = ['Discount', (string) $sale->discount_amount, false];
        }

        $moneyLines[] = ['Total', (string) $sale->total_amount, true];

        if ($sale->payment_status !== PaymentStatus::Unpaid) {
            $moneyLines[] = ['Amount paid', (string) $sale->amount_paid, false];
        }

        if ($sale->payment_status !== PaymentStatus::Paid) {
            $moneyLines[] = ['Balance due', (string) $sale->balance_due, true];
        }

        // The closing lines for the compact completion summary, worded for what actually happened:
        // "Total paid" only when the sale is settled, otherwise what was paid and what is owed.
        // Lives here beside `moneyLines` so the receipt and the confirmation read one source.
        $closingLines = match ($sale->payment_status) {
            PaymentStatus::Paid => [['Total paid', (string) $sale->total_amount, true]],
            PaymentStatus::Partial => [
                ['Amount paid', (string) $sale->amount_paid, false],
                ['Balance due', (string) $sale->balance_due, true],
            ],
            default => [
                ['Total', (string) $sale->total_amount, false],
                ['Balance due', (string) $sale->balance_due, true],
            ],
        };

        return [
            'moneyLines' => $moneyLines,
            'closingLines' => $closingLines,
            'sale' => $sale,
            'number' => $sale->sale_number,
            // The trading day, not the row-write instant: a backdated sale prints the day it
            // happened, matching what the reports count it under.
            'date' => $sale->sale_date,
            'isWalkIn' => $sale->isWalkIn(),
            'buyerName' => $sale->customer_name_snapshot,
            // A walk-in has no code or number; filtered so the line never prints a stray separator.
            'buyerDetail' => implode(' · ', array_filter([
                $sale->customer_code_snapshot,
                $sale->customer_phone_snapshot,
            ])),
            'seller' => $sale->sold_by_name_snapshot,
            'paymentMethod' => $sale->payment_method->label(),
            'paymentStatus' => $sale->payment_status,
            'items' => $sale->items,
            'subtotal' => (string) $sale->subtotal,
            'discount' => (string) $sale->discount_amount,
            'hasDiscount' => $hasDiscount,
            'total' => (string) $sale->total_amount,
            'amountPaid' => (string) $sale->amount_paid,
            'balanceDue' => (string) $sale->balance_due,
            'corrected' => $sale->corrections()->exists(),
        ];
    }
}
