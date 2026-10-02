<?php

namespace App\Actions\Inventory;

use App\Models\Product;
use App\Models\User;
use App\Services\AuditLogger;
use App\Subscriptions\Entitlement;
use App\Subscriptions\Entitlements;
use App\Tenancy\CurrentBusiness;
use Illuminate\Support\Facades\DB;

/**
 * Archives a product: it stops being available for new sales, purchases and stock operations, and
 * stays exactly where it was for every historical record that refers to it.
 *
 * Archiving deliberately does not soft-delete the row. A soft-deleted product disappears from the
 * ordinary queries the listing, the reactivation path and the reporting layer all use, which is the
 * opposite of what archiving is for. `is_active` is the single flag that sales, purchases and stock
 * adjustments already consult, so flipping it is the whole operation.
 */
class ArchiveProduct
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Entitlements $entitlements,
        private readonly CurrentBusiness $tenancy,
    ) {}

    public function execute(User $actor, Product $product): void
    {
        DB::transaction(function () use ($actor, $product): void {
            $product->is_active = false;
            $product->updated_by = $actor->id;
            $product->save();

            $this->audit->record('product_archived', $product, $actor,
                oldValues: ['is_active' => true],
                newValues: ['is_active' => false],
                explicitDiff: true,
            );
        });
    }

    public function reactivate(User $actor, Product $product): void
    {
        DB::transaction(function () use ($actor, $product): void {
            // Back in service means back in the plan's product allowance.
            if (! $product->is_active) {
                $this->entitlements->claim($this->tenancy->forActor($actor), Entitlement::MaxProducts);
            }

            $product->is_active = true;
            $product->updated_by = $actor->id;
            $product->save();

            $this->audit->record('product_reactivated', $product, $actor,
                oldValues: ['is_active' => false],
                newValues: ['is_active' => true],
                explicitDiff: true,
            );
        });
    }
}
