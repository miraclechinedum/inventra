<?php

namespace App\Support;

use App\Enums\DiscountRequestStatus;
use App\Enums\SaleStatus;
use App\Models\Sale;

/**
 * The approved, deliberately conservative conditions under which a completed Sale may receive a
 * post-hoc discount. Returns cannot be in play and neither can refunds: once merchandise has come
 * back or cash has gone out, the arithmetic that reconciles a Sale has more moving parts than a
 * discount can safely reason about, and getting it wrong would corrupt financial evidence. Such a
 * case is handled by the existing returns and refunds workflows instead.
 *
 * A paid or part-paid Sale IS eligible. If the discount takes the total below what was already
 * paid, the difference becomes refundable credit through the existing model rather than a negative
 * balance or a rewritten payment.
 */
class SaleDiscountEligibility
{
    /**
     * A human-readable reason the Sale cannot take a discount at all, or null when it can. Used
     * when raising a request, so it includes the one-open-request rule.
     */
    public static function blockedReason(Sale $sale): ?string
    {
        if (($blocked = self::blockedReasonForApproval($sale)) !== null) {
            return $blocked;
        }

        if ($sale->discountRequests()->where('status', DiscountRequestStatus::Pending->value)->exists()) {
            return 'This Sale already has a discount request awaiting a decision.';
        }

        return null;
    }

    /**
     * The conditions re-tested at the moment of approval. Deliberately excludes the
     * one-open-request rule: the request being approved is itself that open request.
     */
    public static function blockedReasonForApproval(Sale $sale): ?string
    {
        if ($sale->status !== SaleStatus::Completed) {
            return 'Only a completed Sale can be discounted.';
        }

        if (bccomp((string) $sale->returned_amount, '0.00', 2) !== 0) {
            return 'A Sale with a recorded return cannot be discounted. Use the returns workflow instead.';
        }

        if (bccomp((string) $sale->refunded_amount, '0.00', 2) !== 0) {
            return 'A Sale with a recorded refund cannot be discounted.';
        }

        return null;
    }

    public static function permits(Sale $sale): bool
    {
        return self::blockedReason($sale) === null;
    }

    /**
     * The largest discount this Sale could still take. A discount may never push the total below
     * zero, and `subtotal - discount_amount = total_amount` must continue to hold, so the ceiling
     * is whatever remains of the total.
     */
    public static function maximumAmount(Sale $sale): string
    {
        return Money::round((string) $sale->total_amount);
    }
}
