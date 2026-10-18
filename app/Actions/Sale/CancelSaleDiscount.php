<?php

namespace App\Actions\Sale;

use App\Enums\DiscountRequestStatus;
use App\Enums\UserRole;
use App\Models\Sale;
use App\Models\SaleDiscountRequest;
use App\Models\SaleDraft;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Withdraws a pending discount request before anyone decides it.
 *
 * Open to the person who asked and to any Administrator. Cancelling moves no money: it closes the
 * request, releases the guard that held its Sale or cart, and records who closed it. Locks are
 * taken in the order every decision takes them, the request first and then what it is about, so a
 * cancellation and a decision on the same request serialise and only the first one counts.
 */
class CancelSaleDiscount
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, SaleDiscountRequest $request, ?string $note = null): SaleDiscountRequest
    {
        return DB::transaction(function () use ($actor, $request, $note): SaleDiscountRequest {
            $locked = SaleDiscountRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($actor->role !== UserRole::Admin && (int) $locked->requested_by !== (int) $actor->id) {
                throw ValidationException::withMessages([
                    'request' => 'Only the person who asked, or an administrator, can cancel this discount request.',
                ]);
            }

            if ($locked->status !== DiscountRequestStatus::Pending) {
                throw ValidationException::withMessages([
                    'request' => 'This discount request has already been '.mb_strtolower($locked->status->label()).'.',
                ]);
            }

            if ($locked->isPendingExpired()) {
                throw ValidationException::withMessages([
                    'request' => 'This discount request has already expired.',
                ]);
            }

            $locked->sale_draft_id !== null
                ? SaleDraft::query()->lockForUpdate()->findOrFail($locked->sale_draft_id)
                : Sale::query()->lockForUpdate()->findOrFail($locked->sale_id);

            $locked->recordDecision([
                'status' => DiscountRequestStatus::Cancelled->value,
                'decided_by' => $actor->id,
                'decided_by_name_snapshot' => $actor->name,
                'decided_at' => now(),
                'decision_note' => $note,
                'pending_sale_guard' => null,
                'pending_draft_guard' => null,
            ]);

            $this->audit->record('sale_discount_cancelled', $locked, $actor, newValues: [
                'sale_id' => $locked->sale_id,
                'requested_amount' => $locked->requested_amount,
                'decision_note' => $note,
            ]);

            return $locked;
        });
    }
}
