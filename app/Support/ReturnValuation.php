<?php

namespace App\Support;

use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What returned goods are worth to the customer: one definition, used by the preview and by
 * RecordSaleReturn alike, so the figure an operator is shown is the figure the ledger records.
 *
 * A discount is held on the Sale, not on its lines. It is allocated across the Sale's current
 * lines in proportion to each line's value, in fixed-precision decimal arithmetic:
 *
 *   - every line's exact share is floored to the kobo;
 *   - the kobo left over go, one each, to the lines with the largest fractional remainders, ties
 *     to the lowest line id — so the shares always sum to the discount exactly and the same Sale
 *     always allocates the same way.
 *
 * A line's net value is its total less its share. A return of part of a line is worth that part of
 * its net value, rounded half-up to the kobo and never more than what is left of it; the return
 * that exhausts a line takes exactly what is left. Partial and full returns of a line therefore
 * always add up to its net value, and the returns of a whole Sale to its discounted total.
 *
 * A Sale without a discount is valued exactly as before: each partial return at its sold price
 * times its quantity. Only the final return of a line differs, taking the exact remainder.
 *
 * The allocation is recomputed rather than stored: a discount cannot change once a Sale has a
 * return, and a discounted Sale cannot be corrected, so it is fixed by immutable figures.
 */
final class ReturnValuation
{
    /**
     * Each current line's share of the Sale's discount, keyed by sale item id.
     *
     * @param  Collection<int, SaleItem>  $items  the Sale's current lines
     * @return array<int, string>
     */
    public static function shares(Sale $sale, Collection $items): array
    {
        $totals = $items->mapWithKeys(fn (SaleItem $item): array => [(int) $item->id => Money::round((string) $item->line_total)])->all();
        $subtotal = array_reduce($totals, fn (string $sum, string $total): string => bcadd($sum, $total, 2), '0.00');
        $discount = Money::round((string) $sale->discount_amount);

        // A discount can never exceed what the lines are worth; the Sale's own CHECK holds it to its total.
        if (bccomp($discount, $subtotal, 2) > 0) {
            $discount = $subtotal;
        }

        if (bccomp($discount, '0.00', 2) === 0 || bccomp($subtotal, '0.00', 2) === 0) {
            return array_map(fn (): string => '0.00', $totals);
        }

        $shares = [];
        $remainders = [];

        foreach ($totals as $id => $total) {
            $exact = bcdiv(bcmul($discount, $total, 12), $subtotal, 12);
            $shares[$id] = bcadd($exact, '0', 2);
            $remainders[$id] = bcsub($exact, $shares[$id], 12);
        }

        $allocated = array_reduce($shares, fn (string $sum, string $share): string => bcadd($sum, $share, 2), '0.00');
        $leftoverKobo = (int) bcmul(bcsub($discount, $allocated, 2), '100', 0);

        $order = array_keys($remainders);
        usort($order, fn (int $a, int $b): int => bccomp($remainders[$b], $remainders[$a], 12) ?: $a <=> $b);

        foreach (array_slice($order, 0, $leftoverKobo) as $id) {
            $shares[$id] = bcadd($shares[$id], '0.01', 2);
        }

        return $shares;
    }

    /**
     * What returning $quantity of $item is worth, given what has already come back from it.
     *
     * @return array{value: string, gross: string, discount: string}
     */
    public static function line(SaleItem $item, string $share, string $quantity, string $priorQuantity, string $priorValue): array
    {
        $lineTotal = Money::round((string) $item->line_total);
        $net = bcsub($lineTotal, $share, 2);
        $left = bcsub($net, $priorValue, 2);
        $left = bccomp($left, '0.00', 2) < 0 ? '0.00' : $left;
        $exhausts = bccomp(bcadd($priorQuantity, $quantity, 3), (string) $item->quantity, 3) >= 0;
        $gross = Money::round(bcmul((string) $item->unit_price, $quantity, 5));
        $discounted = bccomp($share, '0.00', 2) > 0;

        if ($exhausts) {
            $value = $left;
        } else {
            $value = $discounted
                ? Money::round(bcdiv(bcmul($net, $quantity, 5), (string) $item->quantity, 5))
                : $gross;
            $value = bccomp($value, $left, 2) > 0 ? $left : $value;
        }

        // An undiscounted line is worth its gross; whatever separates the two on a discounted line
        // is the part of the discount these goods carried.
        $gross = $discounted ? $gross : $value;

        return ['value' => $value, 'gross' => $gross, 'discount' => bcsub($gross, $value, 2)];
    }

    /**
     * What has already been returned from each of these lines: quantity and value, keyed by id.
     *
     * @param  list<int>  $itemIds
     * @return array<int, array{quantity: string, value: string}>
     */
    public static function prior(int $businessId, array $itemIds, bool $lock = false): array
    {
        return DB::table('sale_return_items')
            ->where('business_id', $businessId)
            ->whereIn('sale_item_id', $itemIds)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->selectRaw('sale_item_id, COALESCE(SUM(quantity_returned), 0) quantity, COALESCE(SUM(return_line_value), 0) value')
            ->groupBy('sale_item_id')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->sale_item_id => [
                'quantity' => bcadd((string) $row->quantity, '0', 3),
                'value' => Money::round(bcadd((string) $row->value, '0', 2)),
            ]])
            ->all();
    }
}
