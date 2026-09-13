<?php

namespace App\Support;

use App\Enums\DiscountRequestStatus;
use App\Enums\SaleStatus;
use App\Models\Sale;

/**
 * Whether a Sale is in a state where correcting a recording mistake is safe.
 *
 * Deliberately conservative. A correction re-derives the Sale's money from its lines, so anything
 * that has already reconciled against the old figures — a return, a refund, an approved discount —
 * would be silently invalidated by moving them. In each of those cases the existing workflow is the
 * right answer, and the message says so rather than leaving the user to guess.
 *
 * Note what is NOT blocked: a Sale that has been paid for. Paying does not make a recording mistake
 * less wrong, and the payment ledger is never touched — a correction that lowers the total below
 * what was paid produces refundable credit through the same model a discount uses.
 */
class SaleCorrectionEligibility
{
    /** A human-readable reason the Sale cannot be corrected, or null when it can. */
    public static function blockedReason(Sale $sale): ?string
    {
        if ($sale->status !== SaleStatus::Completed) {
            return 'Only a completed Sale can be corrected. A voided Sale is already withdrawn.';
        }

        if ($sale->returns()->exists()) {
            return 'This Sale has a recorded Return, which has already reconciled against its current figures. Record any further returned goods through the Return workflow instead of correcting the Sale.';
        }

        if ($sale->refunds()->exists()) {
            return 'This Sale has a recorded Refund. Cash has already gone out against its current figures, so it cannot be corrected.';
        }

        if ($sale->discountRequests()->where('status', DiscountRequestStatus::Pending->value)->exists()) {
            return 'This Sale has a discount request awaiting a decision. Decide that request first, so the correction and the discount cannot disagree about the total.';
        }

        if ($sale->discountRequests()->where('status', DiscountRequestStatus::Approved->value)->exists()) {
            return 'This Sale carries an approved discount. Correcting it would invalidate the figures that discount was approved against.';
        }

        return null;
    }

    public static function permits(Sale $sale): bool
    {
        return self::blockedReason($sale) === null;
    }

    /**
     * Whether the customer on this Sale may be changed.
     *
     * Only while no payment has been recorded. `sale_payments` rows carry their own `customer_id`
     * and are immutable, and the customer collections report groups by that column — so moving the
     * Sale to a different customer once money has been taken would leave the payment attributed to
     * the original customer and the report permanently disagreeing with the Sale.
     */
    public static function permitsCustomerChange(Sale $sale): bool
    {
        return self::permits($sale) && ! $sale->payments()->exists();
    }
}
