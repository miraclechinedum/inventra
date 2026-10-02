<?php

namespace Tests\Concerns;

use App\Actions\Expense\CreateExpenseCategory;
use App\Actions\Expense\RecordExpense;
use App\Actions\Purchase\ReceivePurchase;
use App\Actions\Sale\CreateSale;
use App\Actions\Sale\RecordSalePayment;
use App\Actions\Sale\RecordSaleRefund;
use App\Actions\Sale\RecordSaleReturn;
use App\Actions\Sale\RequestDraftSaleDiscount;
use App\Actions\Supplier\CreateSupplier;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\Customer;
use App\Models\ExpenseRequest;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseRequest;
use App\Models\Sale;
use App\Models\SalePaymentRequest;
use App\Models\SaleRefundRequest;
use App\Models\SaleReturnRequest;
use App\Models\User;
use App\Tenancy\CurrentBusiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A complete trading history for one Business, built only through the real actions: two registered
 * sales (one part-paid then settled further, one paid then partly returned and refunded), a walk-in
 * sale, a purchase, an expense and a pending draft discount. Prices scale with the given price, so
 * two worlds at different prices never produce the same totals by coincidence.
 */
trait BuildsTransactionWorld
{
    /** @return array<string, mixed> */
    protected function world(Business $business, string $name, string $price): array
    {
        return $this->inBusiness($business, function () use ($business, $name, $price): array {
            $admin = User::factory()->forBusiness($business)->create(['role' => UserRole::Admin]);
            $category = ProductCategory::factory()->forBusiness($business)->create(['name' => 'Filters']);
            $product = Product::factory()->forBusiness($business)->create([
                'category_id' => $category->id, 'sku' => 'SHARED-1', 'selling_price' => $price, 'current_stock' => '100.000',
            ]);
            $customer = Customer::factory()->forBusiness($business)->create(['first_name' => $name, 'phone' => '+2348031234567']);
            $supplier = app(CreateSupplier::class)->execute($admin, ['name' => "{$name} Supply"]);
            $expenseCategory = app(CreateExpenseCategory::class)->execute($admin, ['name' => "{$name} Rent"]);
            $total = bcmul($price, '2', 2);

            $sale = $this->sell($admin, $customer, $product, '2', bcdiv(bcmul($total, '0.4', 2), '1', 2));
            $payment = app(RecordSalePayment::class)->execute($admin, $sale, [
                'request_token' => $this->token(SalePaymentRequest::class, $admin, $sale), 'amount' => bcmul($total, '0.2', 2), 'payment_method' => 'cash', 'note' => null,
            ], 'isolation');
            $paidSale = $this->sell($admin, $customer, $product, '2', $total);
            $walkIn = app(CreateSale::class)->execute($admin, ['is_walk_in' => true, 'payment_method' => 'cash', 'amount_paid' => $price,
                'products' => [['product_id' => $product->id, 'quantity' => '1']]]);
            $paidItem = $paidSale->items()->sole();
            $return = app(RecordSaleReturn::class)->execute($admin, $paidSale, [
                'request_token' => $this->token(SaleReturnRequest::class, $admin, $paidSale), 'reason' => 'Damaged in transit',
                'items' => [['sale_item_id' => $paidItem->id, 'quantity' => '1', 'disposition' => 'restock']],
            ], 'isolation');
            $refund = app(RecordSaleRefund::class)->execute($admin, $paidSale, [
                'request_token' => $this->token(SaleRefundRequest::class, $admin, $paidSale), 'amount' => $price,
                'payment_method' => 'cash', 'reason' => 'Refund for the return', 'sale_return_id' => $return->id,
            ], 'isolation');
            $purchase = app(ReceivePurchase::class)->execute($admin, ['request_token' => $this->purchaseToken($admin), 'supplier_id' => $supplier->id,
                'items' => [['product_id' => $product->id, 'quantity' => '10', 'unit_cost' => bcmul($price, '0.6', 2)]]], 'isolation');
            $expense = app(RecordExpense::class)->execute($admin, ['request_token' => $this->expenseToken($admin), 'expense_category_id' => $expenseCategory->id,
                'amount' => bcmul($price, '1.4', 2), 'payment_method' => 'cash', 'description' => "{$name} fuel", 'incurred_at' => now()->toDateString()], 'isolation');
            $draft = app(RequestDraftSaleDiscount::class)->execute($admin, [
                'amount' => '1.00', 'reason' => "{$name} discount reason", 'is_walk_in' => false, 'customer_id' => $customer->id,
                'sale_date' => now()->toDateString(), 'products' => [['product_id' => $product->id, 'quantity' => '1']],
            ]);

            return [
                'admin' => $admin, 'product' => $product, 'customer' => $customer, 'supplier' => $supplier, 'expenseCategory' => $expenseCategory,
                'sale' => $sale, 'item' => $sale->items()->sole(), 'payment' => $payment,
                'initialPayment' => (int) DB::table('sale_payments')->where('sale_id', $sale->id)->where('payment_type', 'initial')->value('id'),
                'paidSale' => $paidSale, 'paidItem' => $paidItem, 'walkIn' => $walkIn, 'return' => $return,
                'returnItem' => (int) DB::table('sale_return_items')->where('sale_return_id', $return->id)->value('id'),
                'refund' => $refund, 'purchase' => $purchase,
                'purchaseItem' => (int) DB::table('purchase_items')->where('purchase_id', $purchase->id)->value('id'),
                'expense' => $expense, 'draft' => $draft,
            ];
        });
    }

    protected function sell(User $admin, Customer $customer, Product $product, string $quantity, string $paid): Sale
    {
        return app(CreateSale::class)->execute($admin, ['customer_id' => $customer->id, 'payment_method' => 'cash', 'amount_paid' => $paid,
            'products' => [['product_id' => $product->id, 'quantity' => $quantity]]]);
    }

    protected function inBusiness(Business $business, \Closure $work): mixed
    {
        return app(CurrentBusiness::class)->run($business, $work);
    }

    /** @param class-string $model */
    protected function token(string $model, User $actor, Sale $sale): string
    {
        $token = Str::random(64);
        $request = new $model;
        foreach (['token_hash' => hash('sha256', $token), 'sale_id' => $sale->id, 'actor_id' => $actor->id, 'session_id' => 'isolation', 'expires_at' => now()->addMinute()] as $key => $value) {
            $request->{$key} = $value;
        }
        $request->save();

        return $token;
    }

    protected function purchaseToken(User $actor): string
    {
        return $this->actorToken(new PurchaseRequest, $actor);
    }

    protected function expenseToken(User $actor): string
    {
        return $this->actorToken(new ExpenseRequest, $actor);
    }

    protected function actorToken(PurchaseRequest|ExpenseRequest $request, User $actor): string
    {
        $token = Str::random(64);
        $request->token_hash = hash('sha256', $token);
        $request->actor_id = $actor->id;
        $request->session_id = 'isolation';
        $request->expires_at = now()->addMinutes(30);
        $request->save();

        return $token;
    }
}
