<?php

namespace App\Actions\Sale;

use App\Actions\WhatsAppAutomation\WhatsAppAutomationTriggers;
use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marks an order ready for collection — the fulfilment event the Pickup reminder fires from.
 *
 * The single writer of `pickup_ready_at`/`pickup_ready_by`, which is what keeps the pair coherent:
 * MySQL would not accept a CHECK constraint alongside the actor's ON DELETE SET NULL foreign key
 * (see the migration), so coherence is guaranteed here instead, by there being nowhere else to set
 * them.
 *
 * Idempotent by state: a sale already marked ready returns unchanged rather than re-marking, so the
 * reminder cannot be rescheduled by clicking twice. The reminder itself is additionally protected
 * by its `pickup:{sale}` idempotency key.
 */
class MarkSaleReadyForPickup
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly WhatsAppAutomationTriggers $triggers,
    ) {}

    public function execute(User $actor, Sale $sale): Sale
    {
        $marked = DB::transaction(function () use ($actor, $sale): ?Sale {
            $locked = Sale::query()->lockForUpdate()->findOrFail($sale->id);

            if ($locked->status !== SaleStatus::Completed) {
                throw ValidationException::withMessages([
                    'sale' => 'Only a completed sale can be marked ready for pickup.',
                ]);
            }

            if ($locked->isWalkIn() || $locked->customer_id === null) {
                throw ValidationException::withMessages([
                    'sale' => 'A walk-in sale has no customer to collect it.',
                ]);
            }

            // Already ready: nothing changes, and nothing is re-triggered.
            if ($locked->pickup_ready_at !== null) {
                return null;
            }

            // Through the model's own guarded transition, which is the single writer of these two
            // columns and keeps them coherent.
            $locked->markPickupReady(now(), $actor->id);

            $this->audit->record('sale_ready_for_pickup', $locked, $actor, newValues: [
                'pickup_ready_at' => $locked->pickup_ready_at?->toISOString(),
            ]);

            return $locked;
        });

        if ($marked === null) {
            return $sale->refresh();
        }

        // After commit, outside the transaction: a WhatsApp problem must never undo the fulfilment
        // record. Queueing swallows its own failures.
        $this->triggers->saleReadyForPickup($marked);

        return $marked;
    }
}
