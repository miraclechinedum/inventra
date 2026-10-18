<?php

namespace App\Actions\Sale;

use App\Enums\DiscountRequestStatus;
use App\Models\Sale;
use App\Models\SaleDiscountRequest;
use App\Models\SaleDraft;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Records that a discount request outlived its time.
 *
 * Expiry is already enforced wherever a request would be used, from its own timestamps; this only
 * writes it down, so queues stop listing it and the guard on its Sale or cart is released. Two cases:
 *
 *   - a pending request no one decided within its lifetime: closed with no decider, because no
 *     decision was ever made;
 *   - a pre-sale approval no Sale spent before its business day ended: closed keeping the approval
 *     it records, so the history still shows who approved it.
 *
 * A system transition, so the audit row has no human actor. Re-checked under the row lock, and a
 * no-op for anything that is no longer expired-but-unrecorded.
 */
class ExpireSaleDiscountRequest
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(SaleDiscountRequest $request): bool
    {
        return DB::transaction(function () use ($request): bool {
            $locked = SaleDiscountRequest::query()->lockForUpdate()->find($request->id);

            if ($locked === null) {
                return false;
            }

            $draft = $locked->sale_draft_id !== null
                ? SaleDraft::query()->lockForUpdate()->findOrFail($locked->sale_draft_id)
                : null;

            if ($draft === null) {
                Sale::query()->lockForUpdate()->findOrFail($locked->sale_id);
            }

            $pending = $locked->isPendingExpired();
            $approval = $locked->isApprovalExpired() && $draft !== null && ! $draft->isConsumed();

            if (! $pending && ! $approval) {
                return false;
            }

            $from = $locked->status->value;

            $locked->recordDecision([
                'status' => DiscountRequestStatus::Expired->value,
                'expired_at' => now(),
                'pending_sale_guard' => null,
                'pending_draft_guard' => null,
            ]);

            $this->audit->record('sale_discount_expired', $locked, null,
                oldValues: ['status' => $from],
                newValues: ['status' => DiscountRequestStatus::Expired->value, 'expired_at' => $locked->expired_at?->toIso8601String()],
                explicitDiff: true,
            );

            return true;
        });
    }
}
