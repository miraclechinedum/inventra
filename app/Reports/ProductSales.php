<?php

namespace App\Reports;

use App\Models\Sale;
use App\Support\ReturnValuation;
use Illuminate\Support\Facades\DB;

/**
 * Product-level sales figures, gross and net of the Sale-level discount, for a trading period.
 *
 * The definitions are those approved in docs/report-definitions-proposal.md:
 *
 *   gross sales     Σ line_total of a product's current lines: the price as sold
 *   discount        the product's lines' shares of their Sales' discounts, from the same
 *                   allocator returns use, so the shares of one Sale sum to its discount exactly
 *   net sales       gross − discount; across all products, Σ total_amount
 *   returns         Σ return_line_value for the product, already net of discount
 *   net revenue     net sales − returns; across all products, the Revenue headline
 *
 * Completed Sales only, by trading day (`sale_date`), current lines only: a corrected Sale keeps
 * its superseded lines as history, and they are never counted twice. These are values of goods
 * sold, not money collected, which the Collections report measures.
 *
 * Read-only, and named to a Business explicitly: every query here names its business.
 */
final class ProductSales
{
    /**
     * @return array<int, array{gross: string, discount: string, net: string, returned: string, revenue: string}>
     */
    public static function forPeriod(int $businessId, string $from, string $to, ?int $productId = null): array
    {
        $figures = [];
        $blank = ['gross' => '0.00', 'discount' => '0.00', 'net' => '0.00', 'returned' => '0.00', 'revenue' => '0.00'];

        $gross = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sale_items.business_id', $businessId)
            ->where('sales.status', 'completed')
            ->whereBetween('sales.sale_date', [$from, $to])
            ->whereNull('sale_items.superseded_by_correction_id')
            ->when($productId !== null, fn ($query) => $query->where('sale_items.product_id', $productId))
            ->groupBy('sale_items.product_id')
            ->selectRaw('sale_items.product_id AS product, SUM(sale_items.line_total) AS amount')
            ->pluck('amount', 'product');

        foreach ($gross as $product => $amount) {
            $figures[(int) $product] = ['gross' => bcadd((string) $amount, '0', 2)] + $blank;
        }

        foreach (self::discountShares($businessId, $from, $to, $productId) as $line) {
            $figures[$line['product']] ??= $blank;
            $figures[$line['product']]['discount'] = bcadd($figures[$line['product']]['discount'], $line['share'], 2);
        }

        $returned = DB::table('sale_return_items')
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->where('sale_return_items.business_id', $businessId)
            ->where('sales.status', 'completed')
            // By the Sale's trading day, matching Revenue, so a product is never ranked on one basis
            // while the headline uses another.
            ->whereBetween('sales.sale_date', [$from, $to])
            ->when($productId !== null, fn ($query) => $query->where('sale_return_items.product_id', $productId))
            ->groupBy('sale_return_items.product_id')
            ->selectRaw('sale_return_items.product_id AS product, SUM(sale_return_items.return_line_value) AS amount')
            ->pluck('amount', 'product');

        foreach ($returned as $product => $amount) {
            $figures[(int) $product] ??= $blank;
            $figures[(int) $product]['returned'] = bcadd((string) $amount, '0', 2);
        }

        foreach ($figures as $product => $row) {
            $figures[$product]['net'] = bcsub($row['gross'], $row['discount'], 2);
            $figures[$product]['revenue'] = bcsub($figures[$product]['net'], $row['returned'], 2);
        }

        return $figures;
    }

    /**
     * Allocated discount per product as it was sold, keyed `product_id|sku|name` the way Product
     * Performance groups its rows, so a product renamed within the period has each row's own share.
     *
     * @return array<string, string>
     */
    public static function discountsBySnapshot(int $businessId, string $from, string $to, ?int $productId = null): array
    {
        $discounts = [];

        foreach (self::discountShares($businessId, $from, $to, $productId) as $line) {
            $key = self::snapshotKey($line['product'], $line['sku'], $line['name']);
            $discounts[$key] = bcadd($discounts[$key] ?? '0.00', $line['share'], 2);
        }

        return $discounts;
    }

    public static function snapshotKey(int $productId, string $sku, string $name): string
    {
        return $productId.'|'.$sku.'|'.$name;
    }

    /**
     * Every current line of the period's discounted Sales with its share of that Sale's discount.
     * Only discounted Sales carry an allocation; every other line's share is zero.
     *
     * @return \Generator<int, array{product: int, sku: string, name: string, share: string}>
     */
    private static function discountShares(int $businessId, string $from, string $to, ?int $productId): \Generator
    {
        $sales = Sale::query()
            ->where('business_id', $businessId)
            ->where('status', 'completed')
            ->whereBetween('sale_date', [$from, $to])
            ->where('discount_amount', '>', 0)
            ->with('items')
            ->orderBy('id')
            ->lazy(200);

        foreach ($sales as $sale) {
            $shares = ReturnValuation::shares($sale, $sale->items);

            foreach ($sale->items as $item) {
                if ($productId !== null && (int) $item->product_id !== $productId) {
                    continue;
                }

                yield [
                    'product' => (int) $item->product_id,
                    'sku' => (string) $item->product_sku_snapshot,
                    'name' => (string) $item->product_name_snapshot,
                    'share' => $shares[$item->id] ?? '0.00',
                ];
            }
        }
    }

    /**
     * The same figures summed across products.
     *
     * @param  array<int, array{gross: string, discount: string, net: string, returned: string, revenue: string}>  $figures
     * @return array{gross: string, discount: string, net: string, returned: string, revenue: string}
     */
    public static function totals(array $figures): array
    {
        $totals = ['gross' => '0.00', 'discount' => '0.00', 'net' => '0.00', 'returned' => '0.00', 'revenue' => '0.00'];

        foreach ($figures as $row) {
            foreach ($totals as $key => $value) {
                $totals[$key] = bcadd($value, $row[$key], 2);
            }
        }

        return $totals;
    }
}
