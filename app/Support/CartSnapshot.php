<?php

namespace App\Support;

/**
 * The canonical form of a cart, and its fingerprint.
 *
 * An Admin approves a discount against one specific basket. The hash is what lets `CreateSale`
 * refuse to spend that approval on a different basket: if a quantity changed, a line was added or
 * a product was swapped, the hash changes and the approval no longer applies.
 *
 * Canonicalisation matters more than the hash function here. Lines are keyed by product id and
 * sorted, and quantities are normalised to a fixed scale, so the same basket entered in a different
 * order — or with "2" instead of "2.000" — produces the same fingerprint, while any real change to
 * what is being bought produces a different one.
 */
final class CartSnapshot
{
    /**
     * @param  array<int, array{product_id: int|string, quantity: string}>  $lines
     * @return array<int, array{product_id: int, quantity: string}> product id => normalised line
     */
    public static function canonical(array $lines): array
    {
        $merged = [];

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $quantity = (string) ($line['quantity'] ?? '0');

            if ($productId <= 0 || bccomp($quantity, '0', 3) <= 0) {
                continue;
            }

            // The same product twice is one line of the combined quantity, matching how CreateSale
            // already aggregates, so a split line and a merged line fingerprint identically.
            $merged[$productId] = bcadd($merged[$productId] ?? '0', $quantity, 3);
        }

        ksort($merged, SORT_NUMERIC);

        $canonical = [];

        foreach ($merged as $productId => $quantity) {
            $canonical[] = ['product_id' => $productId, 'quantity' => $quantity];
        }

        return $canonical;
    }

    /**
     * The full context an approval is granted against, canonicalised.
     *
     * A discount is never approved for a basket alone — it is approved for these goods, for this
     * buyer, on this trading day. Binding all three means an approval justified by "loyal fleet
     * customer" cannot be spent on somebody else, and one approved for yesterday cannot be carried
     * into today's figures.
     *
     * The buyer is recorded as an explicit marker rather than a bare id, so "walk-in" is a distinct
     * value in its own right and can never be confused with a missing or null customer.
     *
     * @param  array<int, array{product_id: int|string, quantity: string}>  $lines
     */
    public static function context(array $lines, bool $isWalkIn, int|string|null $customerId, string $saleDate): array
    {
        return [
            'lines' => self::canonical($lines),
            'buyer' => $isWalkIn ? 'walk-in' : 'customer:'.(int) $customerId,
            'sale_date' => $saleDate,
        ];
    }

    /**
     * Fingerprint of the canonical context. Equal contexts hash equally; any real change — a
     * quantity, the buyer, the trading day — does not.
     */
    public static function hash(array $lines, bool $isWalkIn = false, int|string|null $customerId = null, string $saleDate = ''): string
    {
        return hash('sha256', json_encode(
            self::context($lines, $isWalkIn, $customerId, $saleDate),
            JSON_THROW_ON_ERROR,
        ));
    }
}
