<?php

namespace App\Support;

use App\Models\Sale;

/**
 * What a proposed Return would do to a Sale, stated before it is recorded.
 *
 * This exists so the side panel can tell the operator the truth about a partially paid Sale.
 * "Refund ₦168,000" is the wrong thing to say when the customer only ever handed over ₦40,000:
 * returning the goods cancels what they still owe first, and only money actually taken can come
 * back. RecordSaleReturn already splits the merchandise value exactly that way —
 *
 *     $reduction = min($merchandiseValue, $balanceOutstanding)   what they no longer owe
 *     $credit    = $merchandiseValue - $reduction                what can be refunded
 *
 * — and this reproduces that split from the same `SaleFinancials::lockedState` figures, so the
 * preview and the recorded outcome cannot disagree.
 *
 * It is a *preview*. Nothing here writes, locks or decides: the action re-reads every figure under
 * a row lock and recomputes the split at the moment it commits, because the balance can move
 * between drawing this panel and submitting it. The browser is shown a number; the server settles
 * the money.
 */
final class ReturnSettlementPreview
{
    /**
     * @param  array<int, array{quantity: string, unit_price: string}>  $lines
     * @return array{merchandise: string, reduction: string, credit: string}
     */
    public static function for(Sale $sale, array $lines): array
    {
        $merchandise = '0.00';

        foreach ($lines as $line) {
            // The price as sold, never the Product's price today — the same `unit_price` column
            // RecordSaleReturn multiplies, rounded the same way.
            $merchandise = bcadd($merchandise, Money::round(bcmul($line['unit_price'], $line['quantity'], 5)), 2);
        }

        return self::split($sale, $merchandise);
    }

    /**
     * The whole settlement, as the panel needs to state it.
     *
     * Everything the summary sentence is built from, decided here rather than in the browser: the
     * money split, the unit counts, and the sentence itself. The browser contributes what the
     * operator chose — which lines, how many, restock or not — and is told what that means.
     *
     * The arithmetic stays in bcmath on decimal strings, which is how every other money value in
     * Inventra is computed. Doing the same sum in JavaScript would introduce a second, floating
     * point definition of the split that could disagree with the ledger over fractions of a naira.
     *
     * Still only a preview. RecordSaleReturn re-reads the balance under a row lock and recomputes
     * this split at the moment it commits, because the balance can move while the panel is open.
     *
     * @param  array<int, array{quantity: string, unit_price: string}>  $lines
     * @return array{
     *     merchandise: string, reduction: string, credit: string,
     *     units: string, restock_units: string, non_restock_units: string,
     *     restock: bool, sentence: string, clauses: list<array{label: string, amount: string}>
     * }
     */
    public static function describe(Sale $sale, array $lines, bool $restock): array
    {
        $split = self::for($sale, $lines);

        $units = '0';
        foreach ($lines as $line) {
            $units = bcadd($units, $line['quantity'], 3);
        }

        $settlement = $split + [
            'units' => Quantity::trim($units),
            // Units either all go back on the shelf or none do: the disposition is one choice for
            // the whole return, exactly as the form submits it per line.
            'restock_units' => $restock ? Quantity::trim($units) : '0',
            'non_restock_units' => $restock ? '0' : Quantity::trim($units),
            'restock' => $restock,
        ];

        return $settlement + [
            'sentence' => self::sentence($settlement),
            'clauses' => self::clauses($settlement),
        ];
    }

    /**
     * The summary sentence: financial effect first, inventory effect last.
     *
     * Only effects that actually happen are named. "Refund ₦0" tells the operator nothing and
     * "Refund" on a sale the customer never paid for would be a plain untruth, so each clause
     * appears only when its amount is above zero.
     *
     * @param  array{credit: string, reduction: string, units: string, restock: bool}  $settlement
     */
    private static function sentence(array $settlement): string
    {
        return implode(' · ', array_map(
            fn (array $clause): string => trim($clause['label'].' '.$clause['amount']),
            self::clauses($settlement)
        ));
    }

    /**
     * The sentence as labelled clauses, so the panel can emphasise the amounts without being handed
     * markup to render unescaped.
     *
     * @param  array{credit: string, reduction: string, units: string, restock: bool}  $settlement
     * @return list<array{label: string, amount: string}>
     */
    private static function clauses(array $settlement): array
    {
        $clauses = [];

        if (bccomp($settlement['credit'], '0.00', 2) > 0) {
            $clauses[] = ['label' => 'Refund', 'amount' => '₦'.Money::compact($settlement['credit'])];
        }

        if (bccomp($settlement['reduction'], '0.00', 2) > 0) {
            $clauses[] = [
                // Capitalised only when it opens the sentence.
                'label' => $clauses === [] ? 'Reduce balance' : 'reduce balance',
                'amount' => '₦'.Money::compact($settlement['reduction']),
            ];
        }

        // Nothing selected yet: say so rather than producing a sentence about nothing.
        if ($clauses === []) {
            return [['label' => 'Select an item to see the effect.', 'amount' => '']];
        }

        $units = $settlement['units'];
        $noun = $units === '1' ? 'unit' : 'units';
        $clauses[] = $settlement['restock']
            ? ['label' => 'restock', 'amount' => $units.' '.$noun]
            : ['label' => '', 'amount' => $units.' '.$noun.' not restocked'];

        return $clauses;
    }

    /**
     * The settlement split for a given merchandise value.
     *
     * @return array{merchandise: string, reduction: string, credit: string}
     */
    public static function split(Sale $sale, string $merchandise): array
    {
        // Read without locking: this is a preview, and the action re-reads under a lock.
        $state = SaleFinancials::lockedState($sale);
        $reduction = bccomp($merchandise, $state['balance'], 2) > 0 ? $state['balance'] : $merchandise;

        return [
            'merchandise' => $merchandise,
            'reduction' => $reduction,
            'credit' => bcsub($merchandise, $reduction, 2),
        ];
    }
}
