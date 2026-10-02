<?php

namespace App\Actions\Sale;

use App\Enums\DiscountRequestStatus;
use App\Models\Sale;
use App\Models\SaleDiscountRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use App\Support\SaleDiscountEligibility;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Raises a discount request against a completed Sale. Nothing financial moves here — the Sale is
 * untouched until an Admin approves.
 */
class RequestSaleDiscount
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{amount: string, reason: string}  $data
     */
    public function execute(User $actor, Sale $sale, array $data): SaleDiscountRequest
    {
        return DB::transaction(function () use ($actor, $sale, $data): SaleDiscountRequest {
            $locked = Sale::query()->lockForUpdate()->findOrFail($sale->id);

            if (($blocked = SaleDiscountEligibility::blockedReason($locked)) !== null) {
                throw ValidationException::withMessages(['sale' => $blocked]);
            }

            $amount = Money::round($data['amount']);

            if (bccomp($amount, '0.00', 2) <= 0) {
                throw ValidationException::withMessages(['amount' => 'The discount must be greater than zero.']);
            }

            // The ceiling is checked again at approval against the state at that moment, because a
            // payment or another decision may land in between.
            if (bccomp($amount, SaleDiscountEligibility::maximumAmount($locked), 2) > 0) {
                throw ValidationException::withMessages([
                    'amount' => 'The discount cannot exceed the Sale total of '.Money::format($locked->total_amount).'.',
                ]);
            }

            $request = new SaleDiscountRequest;
            foreach ([
                'business_id' => $locked->business_id,
                'sale_id' => $locked->id,
                'requested_amount' => $amount,
                'reason' => $data['reason'],
                'status' => DiscountRequestStatus::Pending->value,
                'requested_by' => $actor->id,
                'requested_by_name_snapshot' => $actor->name,
                'requested_at' => now(),
                'pending_sale_guard' => $locked->id,
            ] as $key => $value) {
                $request->$key = $value;
            }

            try {
                $request->save();
            } catch (UniqueConstraintViolationException) {
                // The pending guard caught a concurrent request for the same Sale.
                throw ValidationException::withMessages([
                    'sale' => 'This Sale already has a discount request awaiting a decision.',
                ]);
            }

            $this->audit->record('sale_discount_requested', $request, $actor, newValues: [
                'sale_id' => $locked->id,
                'sale_number' => $locked->sale_number,
                'requested_amount' => $amount,
                'reason' => $request->reason,
            ]);

            return $request;
        });
    }
}
