<?php

namespace App\Actions\Inventory;

use App\Models\Product;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ImageStore;
use App\Support\ProductDeletionGuard;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Permanently removes a product that nothing in the business record refers to.
 *
 * The eligibility check is repeated inside the transaction: the caller has already asked, but a
 * sale, purchase or movement could have landed in between, and the point of this action is that it
 * never destroys something history depends on. Nothing here deletes a movement, a sale line or an
 * audit entry to make a product removable — if any exist, the deletion is refused outright.
 *
 * The audit entry is written before the row goes, so the record of the deletion outlives the thing
 * deleted.
 */
class ForceDeleteProduct
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ProductDeletionGuard $guard,
        private readonly ImageStore $images,
    ) {}

    public function execute(User $actor, Product $product): void
    {
        $imagePath = $product->image_path;

        DB::transaction(function () use ($actor, $product): void {
            // Re-checked under the transaction, not taken on trust from the caller.
            if (! $this->guard->isDeletable($product)) {
                throw new RuntimeException('Refusing to permanently delete a product that has business history.');
            }

            $this->audit->record('product_permanently_deleted', $product, $actor, oldValues: [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'category_id' => $product->category_id,
            ], explicitDiff: true);

            $product->forceDelete();
        });

        // Only once the row is gone is its photograph discarded.
        $this->images->delete($imagePath);
    }
}
