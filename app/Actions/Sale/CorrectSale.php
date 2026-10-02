<?php

namespace App\Actions\Sale;

use App\Enums\InventoryMovementType;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleCorrection;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use App\Support\SaleCorrectionEligibility;
use App\Support\SaleFinancials;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrects a recording mistake on a completed Sale.
 *
 * Three rules shape everything here:
 *
 *  1. Nothing historical is rewritten. Superseded `sale_items` rows are stamped, not edited or
 *     deleted; replacement lines are appended. Old `inventory_movements` rows are never touched —
 *     stock is reconciled with new `correction` movements that state their own before and after.
 *     `sale_payments` is not read for writing at all.
 *  2. Money is derived, never supplied. The caller sends lines; the subtotal, total, balance,
 *     payment status and refundable credit are all recomputed on the server from those lines and
 *     from SaleFinancials, which reads the locked payment/return/refund rows.
 *  3. A correction is not a return. Eligibility refuses any Sale that already has a return, refund
 *     or approved discount reconciled against its current figures.
 */
class CorrectSale
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{reason: string, customer_id?: int|string|null, notes?: string|null, products: array<int, array{product_id: int|string, quantity: string}>}  $data
     */
    public function execute(User $actor, Sale $sale, array $data): SaleCorrection
    {
        $quantities = $this->aggregateQuantities($data['products']);
        $productIds = array_keys($quantities);
        sort($productIds, SORT_NUMERIC);

        return DB::transaction(function () use ($actor, $sale, $data, $productIds, $quantities): SaleCorrection {
            $locked = Sale::query()->lockForUpdate()->findOrFail($sale->id);

            if (($blocked = SaleCorrectionEligibility::blockedReason($locked)) !== null) {
                throw ValidationException::withMessages(['sale' => $blocked]);
            }

            $before = SaleFinancials::lockedState($locked);
            $existing = $locked->items()->orderBy('product_id')->get();

            // Products from both the old and the new line sets must be locked: reducing a quantity
            // returns stock to a product that may no longer appear on the Sale at all.
            $affectedIds = collect($productIds)->merge($existing->pluck('product_id'))->unique()->sort()->values();
            $products = Product::withTrashed()
                ->whereIn('id', $affectedIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== $affectedIds->count()) {
                throw ValidationException::withMessages(['products' => 'A product referenced by this Sale is unavailable.']);
            }

            $lines = $this->priceLines($productIds, $quantities, $products, $existing);
            $subtotal = $this->subtotal($lines);
            $discount = Money::round((string) $locked->discount_amount);

            // The database enforces total = subtotal - discount and total >= 0; refusing here gives
            // the user a sentence instead of a constraint violation.
            if (bccomp($subtotal, $discount, 2) < 0) {
                throw ValidationException::withMessages([
                    'products' => 'The corrected items come to '.Money::format($subtotal)
                        .', which is less than the discount of '.Money::format($discount).' already on this Sale.',
                ]);
            }

            $total = bcsub($subtotal, $discount, 2);
            $correction = $this->recordCorrection($actor, $locked, $data, $before, $existing->count(), count($lines), $subtotal, $total);

            // Retire the old lines and append the new ones.
            foreach ($existing as $item) {
                $item->supersedeBy($correction);
            }

            foreach ($lines as $line) {
                $this->appendItem($locked, $correction, $line);
            }

            $this->reconcileStock($actor, $locked, $correction, $existing, $quantities, $products);

            $attributes = ['subtotal' => $subtotal, 'total_amount' => $total];
            $attributes += $this->customerChange($locked, $data);

            if (array_key_exists('notes', $data)) {
                $attributes['notes'] = $data['notes'];
            }

            // total_amount must be in place before SaleFinancials derives what follows from it.
            $locked->setAttribute('total_amount', $total);
            $after = SaleFinancials::lockedState($locked);
            $locked->applyCorrection($attributes, $after);

            $this->stampOutcome($correction, $after, $locked);
            $this->audit->record('sale_corrected', $locked, $actor,
                oldValues: [
                    'subtotal' => $correction->subtotal_before,
                    'total_amount' => $correction->total_before,
                    'balance_due' => $correction->balance_before,
                    'refundable_credit' => $correction->refundable_credit_before,
                    'payment_status' => $correction->payment_status_before,
                    'customer_id' => $correction->customer_id_before,
                ],
                newValues: [
                    'subtotal' => $subtotal,
                    'total_amount' => $total,
                    'balance_due' => $after['balance'],
                    'refundable_credit' => $after['credit'],
                    'payment_status' => $after['status']->value,
                    'amount_paid' => Money::round((string) $locked->amount_paid),
                    'customer_id' => $locked->customer_id,
                    'sale_number' => $locked->sale_number,
                ],
                metadata: ['reason' => $data['reason'], 'item_count' => count($lines)],
                explicitDiff: true,
            );

            return $correction->fresh();
        });
    }

    /**
     * Prices the corrected lines. A line that was already on the Sale keeps the unit price it was
     * sold at — a correction fixes what was recorded, it does not silently reprice goods to today's
     * catalogue. A line that is genuinely new is priced from the product.
     *
     * @param  array<int, string>  $quantities
     * @return array<int, array<string, mixed>>
     */
    private function priceLines(array $productIds, array $quantities, $products, $existing): array
    {
        $originalPrices = $existing->keyBy('product_id');
        $lines = [];

        foreach ($productIds as $productId) {
            $product = $products->get($productId);
            $quantity = $quantities[$productId];
            $previous = $originalPrices->get($productId);

            if ($previous === null && ! $product->is_active) {
                throw ValidationException::withMessages(['products' => "{$product->name} is inactive and cannot be added."]);
            }

            $unitPrice = $previous !== null
                ? Money::round((string) $previous->unit_price)
                : Money::round((string) $product->selling_price);

            $lines[] = [
                'product' => $product,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => Money::round(bcmul($unitPrice, $quantity, 5)),
            ];
        }

        return $lines;
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function subtotal(array $lines): string
    {
        $subtotal = '0.00';

        foreach ($lines as $line) {
            $subtotal = bcadd($subtotal, $line['line_total'], 2);

            if (bccomp($subtotal, '9999999999999.99', 2) > 0) {
                throw ValidationException::withMessages(['products' => 'The corrected total exceeds the supported monetary limit.']);
            }
        }

        return $subtotal;
    }

    /**
     * Moves stock by the net difference per product and records why. An old movement row is never
     * edited: the Sale originally did take that stock, and the correction is a separate event.
     *
     * @param  array<int, string>  $quantities
     */
    private function reconcileStock(User $actor, Sale $sale, SaleCorrection $correction, $existing, array $quantities, $products): void
    {
        $previous = [];

        foreach ($existing as $item) {
            $previous[$item->product_id] = bcadd($previous[$item->product_id] ?? '0', (string) $item->quantity, 3);
        }

        $productIds = collect(array_keys($previous))->merge(array_keys($quantities))->unique()->sort()->values();

        foreach ($productIds as $productId) {
            $was = $previous[$productId] ?? '0.000';
            $now = $quantities[$productId] ?? '0.000';
            $delta = bcsub($now, $was, 3);

            if (bccomp($delta, '0', 3) === 0) {
                continue;
            }

            $product = $products->get($productId);
            $movementChange = bcsub('0', $delta, 3);
            $stockBefore = (string) $product->current_stock;
            $stockAfter = bcadd($stockBefore, $movementChange, 3);

            if (bccomp($stockAfter, '0', 3) < 0) {
                throw ValidationException::withMessages([
                    'products' => "Correcting this Sale would take {$product->name} below zero stock.",
                ]);
            }

            $movement = new InventoryMovement;
            $movement->business_id = $product->business_id;
            $movement->product_id = $product->id;
            $movement->type = InventoryMovementType::Correction;
            $movement->quantity_change = $movementChange;
            $movement->quantity_before = $stockBefore;
            $movement->quantity_after = $stockAfter;
            $movement->reference_type = $sale->getMorphClass();
            $movement->reference_id = $sale->id;
            $movement->reason = 'Sale correction '.$correction->id.': '.$correction->reason;
            $movement->performed_by = $actor->id;
            $movement->save();

            $product->current_stock = $stockAfter;
            $product->updated_by = $actor->id;
            $product->save();
        }
    }

    private function appendItem(Sale $sale, SaleCorrection $correction, array $line): void
    {
        $product = $line['product'];
        $item = new SaleItem;
        $item->business_id = $sale->business_id;
        $item->sale_id = $sale->id;
        $item->sale_correction_id = $correction->id;
        $item->product_id = $product->id;
        $item->product_sku_snapshot = $product->sku;
        $item->product_name_snapshot = $product->name;
        $item->unit_snapshot = $product->unit->value;
        $item->quantity = $line['quantity'];
        $item->unit_price = $line['unit_price'];
        $item->line_total = $line['line_total'];
        $item->created_at = now();
        $item->save();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function customerChange(Sale $sale, array $data): array
    {
        if (! array_key_exists('customer_id', $data) || $data['customer_id'] === null
            || (int) $data['customer_id'] === (int) $sale->customer_id) {
            return [];
        }

        // A walk-in has no customer to correct, and giving it one would break the `is_walk_in` /
        // `customer_id` CHECK that keeps the two in step. The database would refuse it anyway; this
        // refuses it in language an operator can act on. Turning a walk-in into a registered sale is
        // a re-recording decision, not a typo fix.
        if ($sale->isWalkIn()) {
            throw ValidationException::withMessages([
                'customer_id' => 'A walk-in Sale has no customer to change. Void it and record it again against the customer if it was rung up as a walk-in by mistake.',
            ]);
        }

        if (! SaleCorrectionEligibility::permitsCustomerChange($sale)) {
            throw ValidationException::withMessages([
                'customer_id' => 'The customer cannot be changed once a payment has been recorded against this Sale, because the payment is attributed to the original customer and payment records are never rewritten.',
            ]);
        }

        $customer = Customer::query()->lockForUpdate()->find($data['customer_id']);

        if ($customer === null || ! $customer->is_active) {
            throw ValidationException::withMessages(['customer_id' => 'The selected customer is unavailable.']);
        }

        return [
            'customer_id' => $customer->id,
            'customer_code_snapshot' => $customer->customer_code,
            'customer_name_snapshot' => $customer->full_name,
            'customer_phone_snapshot' => $customer->phone,
        ];
    }

    /** @param array<string, mixed> $before */
    private function recordCorrection(
        User $actor,
        Sale $sale,
        array $data,
        array $before,
        int $itemsBefore,
        int $itemsAfter,
        string $subtotal,
        string $total,
    ): SaleCorrection {
        $correction = new SaleCorrection;
        foreach ([
            'business_id' => $sale->business_id,
            'sale_id' => $sale->id,
            'reason' => $data['reason'],
            'corrected_by' => $actor->id,
            'corrected_by_name_snapshot' => $actor->name,
            'corrected_at' => now(),
            'subtotal_before' => Money::round((string) $sale->subtotal),
            'subtotal_after' => $subtotal,
            'total_before' => Money::round((string) $sale->total_amount),
            'total_after' => $total,
            'balance_before' => $before['balance'],
            // Provisional; stamped with the derived values once the Sale has been updated.
            'balance_after' => $before['balance'],
            'refundable_credit_before' => $before['credit'],
            'refundable_credit_after' => $before['credit'],
            'payment_status_before' => $sale->payment_status->value,
            'payment_status_after' => $sale->payment_status->value,
            'item_count_before' => $itemsBefore,
            'item_count_after' => $itemsAfter,
            'customer_id_before' => $sale->customer_id,
            'customer_id_after' => $sale->customer_id,
        ] as $key => $value) {
            $correction->$key = $value;
        }
        $correction->save();

        return $correction;
    }

    /**
     * Writes the outcome onto the correction row. The model is immutable through Eloquent by
     * design, so this single completing write goes through the query builder rather than opening a
     * mutation path that later code could reuse.
     *
     * @param  array<string, mixed>  $after
     */
    private function stampOutcome(SaleCorrection $correction, array $after, Sale $sale): void
    {
        DB::table('sale_corrections')->where('id', $correction->id)->update([
            'balance_after' => $after['balance'],
            'refundable_credit_after' => $after['credit'],
            'payment_status_after' => $after['status']->value,
            'customer_id_after' => $sale->customer_id,
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<int, array{product_id: int|string, quantity: string}>  $lines
     * @return array<int, string>
     */
    private function aggregateQuantities(array $lines): array
    {
        $quantities = [];

        foreach ($lines as $line) {
            $id = (int) $line['product_id'];
            $quantities[$id] = bcadd($quantities[$id] ?? '0', (string) $line['quantity'], 3);

            if (bccomp($quantities[$id], '999999999999.999', 3) > 0) {
                throw ValidationException::withMessages(['products' => 'An aggregated product quantity is too large.']);
            }
        }

        foreach ($quantities as $id => $quantity) {
            if (bccomp($quantity, '0', 3) <= 0) {
                unset($quantities[$id]);
            }
        }

        if ($quantities === []) {
            throw ValidationException::withMessages([
                'products' => 'A correction must leave at least one item on the Sale. To withdraw a Sale entirely, void it instead.',
            ]);
        }

        return $quantities;
    }
}
