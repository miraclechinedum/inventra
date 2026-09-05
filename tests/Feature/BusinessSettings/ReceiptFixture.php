<?php

namespace Tests\Feature\BusinessSettings;

use App\Actions\Customer\CreateCustomer;
use App\Actions\Expense\CreateExpenseCategory;
use App\Actions\Expense\RecordExpense;
use App\Actions\Inventory\CreateCategory;
use App\Actions\Inventory\CreateProduct;
use App\Actions\Purchase\ReceivePurchase;
use App\Actions\Sale\CreateSale;
use App\Actions\Sale\RecordSalePayment;
use App\Actions\Sale\RecordSaleRefund;
use App\Actions\Sale\RecordSaleReturn;
use App\Actions\Supplier\CreateSupplier;
use App\Models\ExpenseRequest;
use App\Models\PurchaseRequest;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SalePaymentRequest;
use App\Models\SaleRefund;
use App\Models\SaleRefundRequest;
use App\Models\SaleReturn;
use App\Models\SaleReturnRequest;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Builds one of every receipt surface so business-identity integration can be asserted on all of them. */
class ReceiptFixture
{
    /** @return array<string, string> label => receipt url */
    public function build(TestCase $test, User $admin): array
    {
        $customer = app(CreateCustomer::class)->execute($admin, [
            'first_name' => 'Receipt', 'last_name' => 'Customer', 'phone' => '0803'.random_int(1000000, 9999999),
            'email' => null, 'address' => null, 'city' => null, 'notes' => null, 'whatsapp_opt_in' => false,
        ]);
        $category = app(CreateCategory::class)->execute($admin, ['name' => 'Receipt Cat', 'description' => null]);
        $product = app(CreateProduct::class)->execute($admin, [
            'category_id' => $category->id, 'name' => 'Receipt Product', 'sku' => 'RCPT-1', 'description' => null,
            'cost_price' => '1000.00', 'selling_price' => '2500.00', 'initial_stock' => '50',
            'reorder_level' => '2', 'unit' => 'piece', 'is_active' => true,
        ]);

        $sale = app(CreateSale::class)->execute($admin, [
            'customer_id' => $customer->id, 'products' => [['product_id' => $product->id, 'quantity' => '2']],
            'payment_method' => 'cash', 'amount_paid' => '0', 'notes' => null,
        ]);
        $item = SaleItem::query()->where('sale_id', $sale->id)->firstOrFail();

        app(RecordSalePayment::class)->execute($admin, $sale->fresh(), [
            'request_token' => $this->token(new SalePaymentRequest, $admin, $sale),
            'amount' => '5000.00', 'payment_method' => 'cash', 'note' => null,
        ], 'receipts');
        $payment = SalePayment::query()->where('sale_id', $sale->id)->latest('id')->firstOrFail();

        app(RecordSaleReturn::class)->execute($admin, $sale->fresh(), [
            'request_token' => $this->token(new SaleReturnRequest, $admin, $sale), 'reason' => 'Receipt fixture',
            'items' => [['sale_item_id' => $item->id, 'quantity' => '2.000', 'disposition' => 'non_restock']],
        ], 'receipts');
        $return = SaleReturn::query()->where('sale_id', $sale->id)->firstOrFail();

        app(RecordSaleRefund::class)->execute($admin, $sale->fresh(), [
            'request_token' => $this->token(new SaleRefundRequest, $admin, $sale),
            'amount' => '1000.00', 'payment_method' => 'cash', 'reason' => 'Receipt fixture',
        ], 'receipts');
        $refund = SaleRefund::query()->where('sale_id', $sale->id)->firstOrFail();

        $supplier = app(CreateSupplier::class)->execute($admin, ['name' => 'Receipt Supplier']);
        $purchase = app(ReceivePurchase::class)->execute($admin, [
            'request_token' => $this->token(new PurchaseRequest, $admin), 'supplier_id' => $supplier->id,
            'items' => [['product_id' => $product->id, 'quantity' => '3.000', 'unit_cost' => '900.00']],
        ], 'receipts');

        $expenseCategory = app(CreateExpenseCategory::class)->execute($admin, ['name' => 'Receipt Expenses']);
        $expense = app(RecordExpense::class)->execute($admin, [
            'request_token' => $this->token(new ExpenseRequest, $admin),
            'expense_category_id' => $expenseCategory->id, 'amount' => '2500.00', 'payment_method' => 'cash',
            'description' => 'Receipt fixture', 'incurred_at' => now(config('business.timezone'))->toDateString(),
        ], 'receipts');

        return [
            'sale' => route('sales.receipt', $sale),
            'payment' => route('sales.payments.receipt', [$sale, $payment]),
            'return' => route('returns.receipt', $return),
            'refund' => route('refunds.receipt', $refund),
            'purchase' => route('purchases.receipt', $purchase),
            'expense' => route('expenses.receipt', $expense),
        ];
    }

    private function token(object $request, User $actor, ?Sale $sale = null): string
    {
        $token = Str::random(64);
        foreach (array_filter([
            'token_hash' => hash('sha256', $token), 'sale_id' => $sale?->id, 'actor_id' => $actor->id,
            'session_id' => 'receipts', 'expires_at' => now()->addMinutes(30),
        ], fn ($value) => $value !== null) as $key => $value) {
            $request->$key = $value;
        }
        $request->save();

        return $token;
    }
}
