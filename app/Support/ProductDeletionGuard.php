<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Decides whether a product may be permanently deleted.
 *
 * Permanent deletion is the exception, not the ordinary way to retire a product. It is permitted
 * only for a product that nothing in the business record refers to — no sale, no purchase, no
 * return, and no inventory movement. Anything else is archived instead, so receipts, reports and
 * the movement ledger keep resolving.
 *
 * The check reads the referencing tables directly rather than relying on relations, so a table that
 * gains a product reference later is a deliberate addition here rather than a silent gap. The
 * database also declares every one of these foreign keys `restrict on delete`, which is the final
 * backstop if this class is ever bypassed.
 */
final class ProductDeletionGuard
{
    /**
     * Tables that reference a product, and the label used when explaining a refusal.
     *
     * `inventory_movements` is listed like any other: creating a product writes its opening
     * movement, and that movement is immutable, so in practice a product is only ever deletable
     * when that movement has already been removed by something other than this code path.
     *
     * @var array<string, string>
     */
    private const REFERENCES = [
        'sale_items' => 'sales',
        'purchase_items' => 'purchases',
        'sale_return_items' => 'returns',
        'inventory_movements' => 'inventory movements',
    ];

    /**
     * The kinds of history holding this product, empty when there are none.
     *
     * @return array<int, string>
     */
    public function historyFor(Product $product): array
    {
        $found = [];

        foreach (self::REFERENCES as $table => $label) {
            if (DB::table($table)->where('product_id', $product->getKey())->exists()) {
                $found[] = $label;
            }
        }

        return array_values(array_unique($found));
    }

    public function isDeletable(Product $product): bool
    {
        return $this->historyFor($product) === [];
    }

    /** The message shown when a product cannot be permanently deleted. */
    public function refusalReason(Product $product): ?string
    {
        $history = $this->historyFor($product);

        if ($history === []) {
            return null;
        }

        return 'This product cannot be permanently deleted because it has transaction or inventory history ('
            .implode(', ', $history).'). Archive it instead.';
    }
}
