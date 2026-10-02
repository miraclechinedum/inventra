<?php

namespace App\Actions\Sale;

use App\Enums\DiscountRequestStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SaleDiscountRequest;
use App\Models\SaleDraft;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\CartSnapshot;
use App\Support\Money;
use App\Tenancy\CurrentBusiness;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Raises a discount request for a cart that has not been sold yet.
 *
 * This is the pre-sale counterpart to RequestSaleDiscount. It writes a `sale_drafts` row — a frozen
 * copy of the cart plus its fingerprint — and points a normal discount request at it. Nothing is
 * sold: no stock moves, no payment is taken, no inventory movement is written. The draft exists
 * purely to give the Admin a specific basket to decide on.
 *
 * Prices are read from `products` here only to compute the subtotal the Admin is shown. They are
 * read again, under lock, when the Sale is actually recorded, so an approval never freezes a price.
 */
class RequestDraftSaleDiscount
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CurrentBusiness $currentBusiness,
    ) {}

    /**
     * @param  array{amount: string, reason: string, is_walk_in: bool, customer_id?: int|string|null, sale_date: string, products: array<int, array{product_id: int|string, quantity: string}>}  $data
     */
    public function execute(User $actor, array $data): SaleDiscountRequest
    {
        $lines = CartSnapshot::canonical($data['products']);

        if ($lines === []) {
            throw ValidationException::withMessages(['products' => 'Add at least one product before requesting a discount.']);
        }

        $business = $this->currentBusiness->forActor($actor);

        return DB::transaction(function () use ($actor, $data, $lines, $business): SaleDiscountRequest {
            $productIds = array_column($lines, 'product_id');
            $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');

            if ($products->count() !== count($productIds)) {
                throw ValidationException::withMessages(['products' => 'One or more products are unavailable.']);
            }

            $subtotal = '0.00';
            $snapshot = [];

            foreach ($lines as $line) {
                $product = $products->get($line['product_id']);

                if (! $product->is_active) {
                    throw ValidationException::withMessages(['products' => "{$product->name} is inactive."]);
                }

                $lineTotal = Money::round(bcmul((string) $product->selling_price, $line['quantity'], 4));
                $subtotal = bcadd($subtotal, $lineTotal, 2);

                // Names and prices are recorded so the Admin sees what was asked about even if the
                // catalogue changes; the Sale itself re-reads all of it.
                $snapshot[] = [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'quantity' => $line['quantity'],
                    'unit_price' => (string) $product->selling_price,
                    'line_total' => $lineTotal,
                ];
            }

            $amount = Money::round($data['amount']);

            if (bccomp($amount, '0.00', 2) <= 0) {
                throw ValidationException::withMessages(['amount' => 'The discount must be greater than zero.']);
            }

            if (bccomp($amount, $subtotal, 2) > 0) {
                throw ValidationException::withMessages([
                    'amount' => 'The discount cannot exceed the cart total of '.Money::format($subtotal).'.',
                ]);
            }

            // The buyer and trading day are bound into the fingerprint, not merely recorded, so an
            // approval cannot later be spent on a different customer or a different day.
            $walkIn = (bool) ($data['is_walk_in'] ?? false);
            $customerId = $walkIn ? null : ($data['customer_id'] ?? null);
            $saleDate = (string) $data['sale_date'];

            if (! $walkIn && $customerId === null) {
                throw ValidationException::withMessages(['customer_id' => 'Select a customer or choose walk-in before requesting a discount.']);
            }

            // Read through the tenant scope, so another Business's customer is simply not there.
            if ($customerId !== null && ! Customer::query()->whereKey($customerId)->exists()) {
                throw ValidationException::withMessages(['customer_id' => 'The selected customer is invalid.']);
            }

            $draft = new SaleDraft;
            foreach ([
                'business_id' => $business->getKey(),
                'created_by' => $actor->id,
                'is_walk_in' => $walkIn,
                'customer_id' => $customerId,
                'sale_date' => $saleDate,
                'cart_snapshot' => $snapshot,
                'cart_hash' => CartSnapshot::hash($lines, $walkIn, $customerId, $saleDate),
                'subtotal_snapshot' => $subtotal,
            ] as $key => $value) {
                $draft->$key = $value;
            }
            $draft->save();

            $request = new SaleDiscountRequest;
            foreach ([
                'business_id' => $draft->business_id,
                'sale_id' => null,
                'sale_draft_id' => $draft->id,
                'requested_amount' => $amount,
                'reason' => $data['reason'],
                'status' => DiscountRequestStatus::Pending->value,
                'requested_by' => $actor->id,
                'requested_by_name_snapshot' => $actor->name,
                'requested_at' => now(),
                'pending_draft_guard' => $draft->id,
            ] as $key => $value) {
                $request->$key = $value;
            }

            try {
                $request->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'products' => 'This cart already has a discount request awaiting a decision.',
                ]);
            }

            $this->audit->record('sale_discount_requested', $request, $actor, newValues: [
                'sale_draft_id' => $draft->id,
                'requested_amount' => $amount,
                'cart_subtotal' => $subtotal,
                'is_walk_in' => $walkIn,
                'customer_id' => $customerId,
                'sale_date' => $saleDate,
                'reason' => $data['reason'],
            ]);

            return $request;
        });
    }
}
