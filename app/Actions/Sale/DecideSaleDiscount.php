<?php

namespace App\Actions\Sale;

use App\Enums\DiscountRequestStatus;
use App\Models\Sale;
use App\Models\SaleDiscountRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use App\Support\SaleDiscountEligibility;
use App\Support\SaleFinancials;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records an Admin's decision on a discount request.
 *
 * Approval is the only place a completed Sale's price changes, and it changes it by reconciliation
 * rather than by rewriting history:
 *
 *   - `sale_payments` rows are never touched. What the customer paid is a fact.
 *   - `subtotal` is never touched. What was sold, and at what price, is a fact.
 *   - `discount_amount` and `total_amount` move together so the database's
 *     `total_amount = subtotal - discount_amount` invariant continues to hold.
 *   - balance, payment status and refundable credit are re-derived by SaleFinancials from the
 *     locked payment, return and refund rows — the same primitive returns and refunds use — so a
 *     discount that drops the total below what was already paid yields refundable credit instead
 *     of a negative balance.
 *
 * Declining writes nothing but the decision.
 */
class DecideSaleDiscount
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function approve(User $actor, SaleDiscountRequest $request, ?string $note = null): SaleDiscountRequest
    {
        return DB::transaction(function () use ($actor, $request, $note): SaleDiscountRequest {
            [$locked, $sale] = $this->lockPending($request);

            // Eligibility is re-tested against the Sale as it is now, not as it was when asked.
            if (($blocked = SaleDiscountEligibility::blockedReasonForApproval($sale)) !== null) {
                throw ValidationException::withMessages(['request' => $blocked]);
            }

            $amount = Money::round((string) $locked->requested_amount);
            $discountBefore = Money::round((string) $sale->discount_amount);
            $totalBefore = Money::round((string) $sale->total_amount);

            if (bccomp($amount, $totalBefore, 2) > 0) {
                throw ValidationException::withMessages([
                    'request' => 'The Sale total is now '.Money::format($totalBefore)
                        .', which is less than the requested discount. Decline this request and raise a new one.',
                ]);
            }

            $discountAfter = bcadd($discountBefore, $amount, 2);
            $totalAfter = bcsub($totalBefore, $amount, 2);

            // Keeps the subtotal invariant explicit rather than implied by the arithmetic above.
            if (bccomp(bcsub((string) $sale->subtotal, $discountAfter, 2), $totalAfter, 2) !== 0) {
                throw ValidationException::withMessages([
                    'request' => 'The discount cannot be reconciled against this Sale.',
                ]);
            }

            // Set the new obligation, then let SaleFinancials derive everything that follows.
            $sale->setAttribute('total_amount', $totalAfter);
            $state = SaleFinancials::lockedState($sale);
            $sale->applyApprovedDiscount($discountAfter, $totalAfter, $state);

            $locked->recordDecision([
                'status' => DiscountRequestStatus::Approved->value,
                'decided_by' => $actor->id,
                'decided_by_name_snapshot' => $actor->name,
                'decided_at' => now(),
                'decision_note' => $note,
                'discount_before' => $discountBefore,
                'discount_after' => $discountAfter,
                'total_before' => $totalBefore,
                'total_after' => $totalAfter,
                'balance_after' => $state['balance'],
                'refundable_credit_after' => $state['credit'],
                'pending_sale_guard' => null,
            ]);

            $this->audit->record('sale_discount_approved', $locked, $actor,
                oldValues: ['discount_amount' => $discountBefore, 'total_amount' => $totalBefore],
                newValues: [
                    'sale_id' => $sale->id,
                    'sale_number' => $sale->sale_number,
                    'discount_amount' => $discountAfter,
                    'total_amount' => $totalAfter,
                    'amount_paid' => $sale->amount_paid,
                    'balance_due' => $state['balance'],
                    'refundable_credit' => $state['credit'],
                    'payment_status' => $state['status']->value,
                ],
                explicitDiff: true,
            );

            return $locked;
        });
    }

    public function decline(User $actor, SaleDiscountRequest $request, string $note): SaleDiscountRequest
    {
        return DB::transaction(function () use ($actor, $request, $note): SaleDiscountRequest {
            [$locked, $sale] = $this->lockPending($request);

            // Declining is purely a status change. Nothing financial is read or written, which is
            // why it stays available even on a Sale that has since become ineligible.
            $locked->recordDecision([
                'status' => DiscountRequestStatus::Declined->value,
                'decided_by' => $actor->id,
                'decided_by_name_snapshot' => $actor->name,
                'decided_at' => now(),
                'decision_note' => $note,
                'pending_sale_guard' => null,
            ]);

            $this->audit->record('sale_discount_declined', $locked, $actor, newValues: [
                'sale_id' => $sale->id,
                'sale_number' => $sale->sale_number,
                'requested_amount' => $locked->requested_amount,
                'decision_note' => $note,
            ]);

            return $locked;
        });
    }

    /**
     * @return array{0: SaleDiscountRequest, 1: Sale}
     */
    private function lockPending(SaleDiscountRequest $request): array
    {
        $locked = SaleDiscountRequest::query()->lockForUpdate()->findOrFail($request->id);

        if ($locked->status !== DiscountRequestStatus::Pending) {
            throw ValidationException::withMessages([
                'request' => 'This discount request has already been '.$locked->status->label().'.',
            ]);
        }

        return [$locked, Sale::query()->lockForUpdate()->findOrFail($locked->sale_id)];
    }
}
