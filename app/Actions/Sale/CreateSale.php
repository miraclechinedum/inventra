<?php

namespace App\Actions\Sale;

use App\Enums\InventoryMovementType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SalePaymentType;
use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use App\Support\PaymentNumber;
use App\Support\SaleNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateSale
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, array $data): Sale
    {
        $quantities = $this->aggregateQuantities($data['products']);
        $productIds = array_keys($quantities);
        sort($productIds, SORT_NUMERIC);

        return DB::transaction(function () use ($actor, $data, $productIds, $quantities): Sale {
            $customer = Customer::query()->lockForUpdate()->findOrFail($data['customer_id']);

            if (! $customer->is_active) {
                throw ValidationException::withMessages(['customer_id' => 'The selected customer is inactive.']);
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

            $discount = '0.00';
            $total = bcsub($subtotal, $discount, 2);
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
            $sale->sale_number = 'PENDING-'.Str::random(20);
            $sale->customer_id = $customer->id;
            $sale->customer_code_snapshot = $customer->customer_code;
            $sale->customer_name_snapshot = $customer->full_name;
            $sale->customer_phone_snapshot = $customer->phone;
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
            $sale->sale_number = SaleNumber::fromId($sale->id);
            $sale->save();

            foreach ($lines as $line) {
                /** @var Product $product */
                $product = $line['product'];
                $item = new SaleItem;
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
                $payment->payment_number = 'PENDING-'.Str::random(20);
                $payment->sale_id = $sale->id;
                $payment->customer_id = $customer->id;
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

            $this->audit->record('sale_created', $sale, $actor, newValues: $sale->getAttributes(), metadata: [
                'item_count' => count($lines),
            ]);

            return $sale;
        });
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
