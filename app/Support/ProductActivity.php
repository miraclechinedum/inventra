<?php

namespace App\Support;

use App\Models\Product;
use App\Models\SaleItem;
use Illuminate\Support\Carbon;

/**
 * Read-only figures for the Product Details screen.
 *
 * Nothing here writes, and nothing here restates a business rule that already lives somewhere
 * authoritative: units sold reads the same live `sale_items` rows the Sale itself asserts, and the
 * low-stock question is answered by Product::isLowStock().
 */
final class ProductActivity
{
    /** The window the "Units sold" metric reports on. */
    public const SOLD_WINDOW_DAYS = 30;

    /**
     * Quantity of this product sold in the last 30 days.
     *
     * Counts only what the Sale currently asserts:
     *  - `sales.status = completed`, so a voided Sale contributes nothing;
     *  - `superseded_by_correction_id IS NULL`, the same condition Sale::items() uses, so a line a
     *    correction retired is not counted alongside the replacement that supersedes it.
     *
     * Returns are deliberately not netted off: they are their own records with their own reporting,
     * and this metric answers "how much moved out of the door", matching the product-performance
     * report's existing reading of sold quantity.
     */
    public static function unitsSold(Product $product, ?Carbon $since = null): string
    {
        $total = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sale_items.product_id', $product->getKey())
            ->whereNull('sale_items.superseded_by_correction_id')
            ->where('sales.status', 'completed')
            ->where('sales.created_at', '>=', $since ?? now()->subDays(self::SOLD_WINDOW_DAYS))
            ->sum('sale_items.quantity');

        return Quantity::trim((string) $total);
    }
}
