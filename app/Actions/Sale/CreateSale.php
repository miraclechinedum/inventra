<?php

namespace App\Actions\Sale;

use App\Actions\WhatsAppAutomation\WhatsAppAutomationTriggers;
use App\Enums\DiscountRequestStatus;
use App\Enums\InventoryMovementType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SalePaymentType;
use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDraft;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use App\Support\PaymentNumber;
use App\Support\SaleNumber;
use App\Tenancy\CurrentBusiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateSale
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly WhatsAppAutomationTriggers $whatsapp,
        private readonly CurrentBusiness $currentBusiness,
    ) {}

    public function execute(User $actor, array $data): Sale
    {
        $quantities = $this->aggregateQuantities($data['products']);
        $productIds = array_keys($quantities);
        sort($productIds, SORT_NUMERIC);

        // The seller's own Business; every customer, product and draft below is read inside it.
        $business = $this->currentBusiness->forActor($actor);

        $sale = DB::transaction(function () use ($actor, $data, $productIds, $quantities, $business): Sale {
            // A walk-in is a genuine absence of a customer, not a placeholder row. The Sale still
            // carries readable identity snapshots so receipts, returns and reports stay legible.
            $walkIn = (bool) ($data['is_walk_in'] ?? false);
            $customer = null;

            if (! $walkIn) {
                $customer = Customer::query()->lockForUpdate()->findOrFail($data['customer_id']);

                if (! $customer->is_active) {
                    throw ValidationException::withMessages(['customer_id' => 'The selected customer is inactive.']);
                }
            }

            $products = Product::query()
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== count($productIds)) {
                throw ValidationException::withMessages(['products' => 'One or more products are unavailable.']);
            }

            $lines = [];
            $subtotal = '0.00';

            foreach ($productIds as $productId) {
                $product = $products->get($productId);
                $quantity = $quantities[$productId];

                if (! $product->is_active) {
                    throw ValidationException::withMessages(['products' => "{$product->name} is inactive."]);
                }

                $after = bcsub($product->current_stock, $quantity, 3);

                if (bccomp($after, '0', 3) < 0) {
                    throw ValidationException::withMessages(['products' => "Insufficient stock for {$product->name}."]);
                }

                $lineTotal = $this->lineTotal($product->selling_price, $quantity);
                $subtotal = bcadd($subtotal, $lineTotal, 2);

                if (bccomp($subtotal, '9999999999999.99', 2) > 0) {
                    throw ValidationException::withMessages(['products' => 'The sale total exceeds the supported monetary limit.']);
                }
                $lines[] = compact('product', 'quantity', 'after', 'lineTotal');
            }

            // An approved discount is spent here or not at all. The approval is bound to a cart
            // fingerprint, so a basket edited after approval no longer matches and the approval is
            // refused rather than silently applied to different goods.
            $draft = $this->resolveApprovedDraft($actor, $data, $lines, $subtotal);
            $discount = $draft === null ? '0.00' : Money::round((string) $draft['amount']);
            $total = bcsub($subtotal, $discount, 2);

            if (bccomp($total, '0.00', 2) < 0) {
                throw ValidationException::withMessages(['products' => 'The approved discount exceeds the sale total.']);
            }
            $amountPaid = bcadd($data['amount_paid'], '0', 2);

            if (bccomp($amountPaid, $total, 2) > 0) {
                throw ValidationException::withMessages(['amount_paid' => 'Amount paid cannot exceed the sale total.']);
            }

            $balance = bcsub($total, $amountPaid, 2);
            $paymentStatus = match (true) {
                bccomp($balance, '0', 2) === 0 => PaymentStatus::Paid,
                bccomp($amountPaid, '0', 2) === 0 => PaymentStatus::Unpaid,
                default => PaymentStatus::Partial,
            };

            $sale = new Sale;
            $sale->business_id = $business->getKey();
            // Assigned once, up front. The old scheme derived the number from the primary key and
            // so needed a placeholder and a second save; a random reference needs neither.
            $sale->sale_number = SaleNumber::generate();
            $sale->is_walk_in = $walkIn;
            $sale->customer_id = $customer?->id;
            $sale->customer_code_snapshot = $customer?->customer_code ?? Sale::WALK_IN_CODE;
            $sale->customer_name_snapshot = $customer?->full_name ?? Sale::WALK_IN_NAME;
            $sale->customer_phone_snapshot = $customer?->phone;
            // When the trade happened, which a user may legitimately backdate. `created_at` remains
            // the moment the row was written and is what audit chronology continues to use.
            $sale->sale_date = $data['sale_date'] ?? now()->toDateString();
            $sale->sold_by_name_snapshot = $actor->name;
            $sale->status = SaleStatus::Completed;
            $sale->payment_method = PaymentMethod::from($data['payment_method']);
            $sale->payment_status = $paymentStatus;
            $sale->subtotal = $subtotal;
            $sale->discount_amount = $discount;
            $sale->total_amount = $total;
            $sale->amount_paid = $amountPaid;
            $sale->balance_due = $balance;
            $sale->notes = $data['notes'] ?? null;
            $sale->sold_by = $actor->id;
            $sale->save();

            foreach ($lines as $line) {
                /** @var Product $product */
                $product = $line['product'];
                $item = new SaleItem;
                $item->business_id = $sale->business_id;
                $item->sale_id = $sale->id;
                $item->product_id = $product->id;
                $item->product_sku_snapshot = $product->sku;
                $item->product_name_snapshot = $product->name;
                $item->unit_snapshot = $product->unit->value;
                $item->quantity = $line['quantity'];
                $item->unit_price = $product->selling_price;
                $item->line_total = $line['lineTotal'];
                $item->save();

                $movement = new InventoryMovement;
                $movement->business_id = $product->business_id;
                $movement->product_id = $product->id;
                $movement->type = InventoryMovementType::Sale;
                $movement->quantity_change = bcsub('0', $line['quantity'], 3);
                $movement->quantity_before = $product->current_stock;
                $movement->quantity_after = $line['after'];
                $movement->reference_type = $sale->getMorphClass();
                $movement->reference_id = $sale->id;
                $movement->reason = 'Sale '.$sale->sale_number;
                $movement->performed_by = $actor->id;
                $movement->save();

                $product->current_stock = $line['after'];
                $product->updated_by = $actor->id;
                $product->save();
            }

            if (bccomp($amountPaid, '0.00', 2) > 0) {
                $payment = new SalePayment;
                $payment->business_id = $sale->business_id;
                $payment->payment_number = 'PENDING-'.Str::random(20);
                $payment->sale_id = $sale->id;
                $payment->customer_id = $customer?->id;
                $payment->amount = $amountPaid;
                $payment->payment_method = $sale->payment_method;
                $payment->payment_type = SalePaymentType::Initial;
                $payment->recorded_by = $actor->id;
                $payment->recorded_by_name_snapshot = $actor->name;
                $payment->paid_at = $sale->created_at;
                $payment->note = null;
                $payment->cumulative_paid_after = $amountPaid;
                $payment->balance_after = $balance;
                $payment->payment_status_after = $paymentStatus;
                $payment->initial_sale_guard = $sale->id;
                $payment->save();
                $payment->payment_number = PaymentNumber::fromId($payment->id);
                $payment->save();

                $this->audit->record('sale_initial_payment_recorded', $payment, $actor, newValues: $payment->getAttributes());
            }

            if ($draft !== null) {
                $draft['model']->markConsumed($sale);
            }

            $this->audit->record('sale_created', $sale, $actor, newValues: $sale->getAttributes(), metadata: [
                'item_count' => count($lines),
                'is_walk_in' => $walkIn,
                'sale_draft_id' => $draft['model']->id ?? null,
            ]);

            return $sale;
        });

        // The sale is committed and irreversible. The post-purchase automation is triggered
        // afterwards so the provider stays entirely outside the sale transaction: the scheduler
        // sends it later, and the trigger swallows every failure, so a WhatsApp problem can never
        // affect a recorded sale.
        //
        // This replaces the former automatic WhatsApp *receipt*. There is deliberately only one
        // call here: two would mean a customer receiving two messages for one purchase.
        $this->whatsapp->salePaid($sale);

        return $sale;
    }

    /**
     * Resolves an approved pre-sale discount, if the request carries one, and proves it still
     * applies to the cart actually being recorded.
     *
     * Every one of these checks matters, because the draft id arrives from the browser:
     *   - the draft is locked, so two concurrent submissions cannot both spend it;
     *   - it must belong to the user recording the sale;
     *   - it must not already have been consumed by another Sale;
     *   - its latest decision must be an approval, not a pending or declined request;
     *   - and its fingerprint must still match the cart in hand, so editing the basket after
     *     approval invalidates the approval instead of discounting different goods.
     *
     * The approved amount is read from the request row, never from the submitted payload.
     *
     * @param  array<int, array{product: Product, quantity: string, after: string, lineTotal: string}>  $lines
     * @return array{model: SaleDraft, amount: string}|null
     */
    private function resolveApprovedDraft(User $actor, array $data, array $lines, string $subtotal): ?array
    {
        $draftId = $data['sale_draft_id'] ?? null;

        if ($draftId === null || $draftId === '') {
            return null;
        }

        $draft = SaleDraft::query()->lockForUpdate()->find($draftId);

        if ($draft === null || (int) $draft->created_by !== (int) $actor->id) {
            throw ValidationException::withMessages(['products' => 'That discount approval is not available for this sale.']);
        }

        if ($draft->isConsumed()) {
            throw ValidationException::withMessages(['products' => 'That discount approval has already been used.']);
        }

        $request = $draft->latestDecidedRequest();

        if ($request === null || $request->status !== DiscountRequestStatus::Approved) {
            throw ValidationException::withMessages(['products' => 'No approved discount is available for this sale.']);
        }

        // Re-fingerprinted from what this Sale is actually about to write — the lines, the buyer and
        // the trading day — rather than from anything the approved request remembers. Nothing about
        // the approved context is taken on trust; it is only ever compared against.
        $current = array_map(
            fn (array $line): array => ['product_id' => $line['product']->id, 'quantity' => $line['quantity']],
            $lines,
        );

        $walkIn = (bool) ($data['is_walk_in'] ?? false);
        $customerId = $walkIn ? null : ($data['customer_id'] ?? null);
        $saleDate = (string) ($data['sale_date'] ?? '');

        if (! $draft->matches($current, $walkIn, $customerId, $saleDate)) {
            throw ValidationException::withMessages([
                'products' => 'The sale changed after the discount was approved. Request a new discount for the updated cart, customer and date.',
            ]);
        }

        $amount = Money::round((string) $request->requested_amount);

        if (bccomp($amount, $subtotal, 2) > 0) {
            throw ValidationException::withMessages([
                'products' => 'The approved discount exceeds the current sale total.',
            ]);
        }

        return ['model' => $draft, 'amount' => $amount];
    }

    private function aggregateQuantities(array $lines): array
    {
        $quantities = [];

        foreach ($lines as $line) {
            $id = (int) $line['product_id'];
            $quantities[$id] = bcadd($quantities[$id] ?? '0', $line['quantity'], 3);

            if (bccomp($quantities[$id], '999999999999.999', 3) > 0) {
                throw ValidationException::withMessages(['products' => 'An aggregated product quantity is too large.']);
            }
        }

        return $quantities;
    }

    private function lineTotal(string $unitPrice, string $quantity): string
    {
        return Money::round(bcmul($unitPrice, $quantity, 5));
    }
}
