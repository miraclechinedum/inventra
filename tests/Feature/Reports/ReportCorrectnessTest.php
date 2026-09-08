<?php

namespace Tests\Feature\Reports;

use App\Actions\Expense\CreateExpenseCategory;
use App\Actions\Expense\RecordExpense;
use App\Actions\Purchase\ReceivePurchase;
use App\Actions\Supplier\CreateSupplier;
use App\Enums\InventoryMovementType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SalePaymentType;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\ExpenseRequest;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\PurchaseRequest;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use App\Support\Money;
use App\Support\PerPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_deterministic_business_fixture_reconciles_independent_report_metrics_without_double_counting(): void
    {
        [$admin, $seller, $customer, $product, $saleA, $saleB] = $this->scenario();
        $this->actingAs($admin);

        $this->get(route('reports.sales'))->assertOk()->assertSee('₦150,000.00')->assertSee('2')->assertSee($saleA->customer_name_snapshot)->assertSee($seller->name);
        $this->get(route('reports.collections'))->assertOk()->assertSee('₦50,000.00')->assertSee('PMT-A1')->assertSee('PMT-A2')->assertSee('Cash')->assertSee('Bank transfer');
        $this->get(route('reports.receivables'))->assertOk()->assertSee('₦100,000.00')->assertSee($saleA->sale_number)->assertSee($saleB->sale_number)->assertDontSee('inconsistency detected');
        $this->get(route('reports.expenses'))->assertOk()->assertSee('₦10,000.00')->assertSee('Operating electricity')->assertDontSee('PRIVATE EXPENSE NOTE');
        $this->get(route('reports.purchases'))->assertOk()->assertSee('₦40,000.00')->assertSee('Inventory Purchases');
        $this->get(route('reports.products'))->assertOk()->assertSee('₦150,000.00')->assertSee('3.000')->assertSee('2 transactions')->assertDontSee('Cost')->assertDontSee('Margin');
        $this->get(route('reports.customers'))->assertOk()->assertSee('₦150,000.00')->assertSee('Collected ₦50,000.00')->assertSee('Outstanding ₦100,000.00');
        $this->get(route('reports.staff'))->assertOk()->assertSee($seller->name)->assertSee('₦150,000.00')->assertSee('Seller-attributed collections ₦50,000.00');
        $this->get(route('reports.summary'))->assertOk()->assertSee('₦150,000.00')->assertSee('₦50,000.00')->assertSee('₦100,000.00')->assertSee('₦10,000.00')->assertSee('₦40,000.00')->assertDontSee('Profit')->assertDontSee('Net Income');
        $this->assertSame('15.000', $product->fresh()->current_stock);
    }

    public function test_voids_filters_pagination_large_decimal_aggregates_and_snapshots_are_correct(): void
    {
        [$admin, $seller, $customer] = $this->scenario();
        $void = Sale::factory()->create(['customer_id' => $customer->id, 'sold_by' => $seller->id, 'status' => SaleStatus::Voided, 'total_amount' => '999.00', 'subtotal' => '999.00', 'amount_paid' => '999.00', 'balance_due' => '0.00', 'payment_status' => PaymentStatus::Paid]);
        for ($i = 0; $i < 29; $i++) {
            Sale::factory()->create(['customer_id' => $customer->id, 'sold_by' => $seller->id, 'total_amount' => '9999999999999.99', 'subtotal' => '9999999999999.99', 'amount_paid' => '0.00', 'balance_due' => '9999999999999.99', 'payment_status' => PaymentStatus::Unpaid]);
        }
        $seller->name = 'Renamed Seller';
        $seller->save();
        $customer->first_name = 'Renamed';
        $customer->save();
        $expectedGross = Money::format(bcadd(bcmul('9999999999999.99', '29', 2), '150000.00', 2));
        $response = $this->actingAs($admin)->get(route('reports.sales', ['staff' => $seller->id, 'customer' => $customer->id]));
        DB::table('sales')->where('id', $void->id)->update(['voided_at' => now()]);
        $response = $this->actingAs($admin)->get(route('reports.sales', ['staff' => $seller->id, 'customer' => $customer->id]));
        $response->assertOk()->assertSee('Active Sales')->assertSee('31')->assertSee('Voided in Period')->assertSee('1')->assertSee($void->sold_by_name_snapshot)->assertSee('₦'.$expectedGross);
        $this->get(route('reports.sales', ['staff' => $seller->id, 'customer' => $customer->id, 'page' => 2]))->assertOk()->assertSee('Active Sales')->assertSee('31');
    }

    public function test_inventory_direction_and_integrity_warning_use_authoritative_signed_and_ledger_data(): void
    {
        [$admin, , , $product, $saleA] = $this->scenario();
        foreach ([['sale', '-2.000'], ['sale_void', '2.000'], ['damage', '-1.500'], ['adjustment', '3.250']] as [$type, $change]) {
            $movement = new InventoryMovement;
            $movement->product_id = $product->id;
            $movement->type = InventoryMovementType::from($type);
            $movement->quantity_change = $change;
            $movement->quantity_before = '10.000';
            $movement->quantity_after = bcadd('10.000', $change, 3);
            $movement->created_at = now();
            $movement->save();
        }
        $this->actingAs($admin)->get(route('reports.inventory'))->assertOk()->assertSee('Quantity In')->assertSee('10.250')->assertSee('Quantity Out')->assertSee('3.500');
        DB::table('sales')->where('id', $saleA->id)->update(['amount_paid' => '21000.00', 'balance_due' => '79000.00']);
        $this->get(route('reports.receivables'))->assertOk()->assertSee('inconsistency detected');
        $this->get(route('reports.summary'))->assertOk()->assertSee('inconsistency detected');
    }

    public function test_receivables_are_all_current_state_and_not_limited_by_sale_creation_period(): void
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        [$admin, , , , $saleA, $saleB] = $this->scenario();
        DB::table('sales')->whereIn('id', [$saleA->id, $saleB->id])->update(['created_at' => '2026-09-15 10:00:00']);

        $this->actingAs($admin)->get(route('reports.receivables'))
            ->assertOk()->assertSee('Current Outstanding Receivables')->assertSee('₦100,000.00')
            ->assertDontSee('name="from"', false)->assertSee('not a historical period-end reconstruction');
        $this->get(route('reports.summary'))->assertOk()->assertSee('Current Outstanding Receivables')->assertSee('₦100,000.00');
    }

    public function test_voided_sale_receipts_remain_global_collections_but_not_active_performance(): void
    {
        [$admin, $seller, $customer, , $saleA] = $this->scenario();
        $void = Sale::factory()->create([
            'customer_id' => $customer->id, 'sold_by' => $seller->id, 'status' => SaleStatus::Voided,
            'subtotal' => '80000.00', 'total_amount' => '80000.00', 'amount_paid' => '10000.00',
            'balance_due' => '70000.00', 'payment_status' => PaymentStatus::Partial, 'voided_at' => now(),
        ]);
        $this->payment($void, $admin, 'PMT-VOID', '10000.00', PaymentMethod::Cash, SalePaymentType::Initial, 10000, 70000);

        $this->actingAs($admin)->get(route('reports.collections'))->assertOk()->assertSee('₦60,000.00')->assertSee('PMT-VOID')->assertSee('later voided');
        $this->get(route('reports.summary'))->assertOk()->assertSee('Customer Collections')->assertSee('₦60,000.00')->assertSee('Gross Sales')->assertSee('₦150,000.00')->assertSee('Current Outstanding Receivables')->assertSee('₦100,000.00');
        $this->get(route('reports.customers'))->assertOk()->assertSee('Collected ₦50,000.00')->assertDontSee('Collected ₦60,000.00');
        $this->get(route('reports.staff'))->assertOk()->assertSee('Seller-attributed collections ₦50,000.00')->assertDontSee('Seller-attributed collections ₦60,000.00');
        $this->assertSame('50000.00', $saleA->amount_paid);
    }

    public function test_voided_metric_uses_authoritative_void_event_business_date(): void
    {
        Carbon::setTestNow('2026-09-16 12:00:00');
        [$admin, $seller, $customer] = $this->scenario();
        $void = Sale::factory()->create(['customer_id' => $customer->id, 'sold_by' => $seller->id, 'status' => SaleStatus::Voided, 'voided_at' => '2026-09-16 01:00:00']);
        DB::table('sales')->where('id', $void->id)->update(['created_at' => '2026-09-15 12:00:00']);

        $this->actingAs($admin)->get(route('reports.sales', ['from' => '2026-09-15', 'to' => '2026-09-15']))
            ->assertOk()->assertSeeInOrder(['Voided in Period', '0']);
        $this->get(route('reports.sales', ['from' => '2026-09-16', 'to' => '2026-09-16']))
            ->assertOk()->assertSeeInOrder(['Voided in Period', '1']);
    }

    public function test_summary_low_stock_is_active_only_and_expense_methods_are_humanized(): void
    {
        [$admin] = $this->scenario();
        Product::query()->update(['is_active' => false]);
        Product::factory()->create(['is_active' => true, 'current_stock' => '0.000', 'reorder_level' => '1.000']);
        Product::factory()->create(['is_active' => false, 'current_stock' => '0.000', 'reorder_level' => '1.000']);

        $this->actingAs($admin)->get(route('reports.summary'))->assertOk()->assertSeeInOrder(['Low-stock Products', '1']);
        $this->get(route('reports.expenses'))->assertOk()->assertSeeInOrder(['By Payment Method', 'Cash']);
    }

    public function test_product_performance_preserves_renamed_snapshot_variants_without_double_counting(): void
    {
        [$admin, $seller, $customer, $product] = $this->scenario();
        $oldName = $product->name;
        $product->name = 'Renamed Live Product';
        $product->save();
        $laterSale = Sale::factory()->create(['customer_id' => $customer->id, 'sold_by' => $seller->id, 'subtotal' => '25000.00', 'total_amount' => '25000.00', 'amount_paid' => '25000.00', 'balance_due' => '0.00']);
        $this->item($laterSale, $product, '1.000', '25000.00');

        $this->actingAs($admin)->get(route('reports.products'))->assertOk()
            ->assertSee('Gross Product Sales')->assertSee('₦175,000.00')
            ->assertSee($oldName)->assertSee('Renamed Live Product')
            ->assertSee('Historical Product Snapshot')->assertSee('Current stock — same live product');
    }

    public function test_report_xss_and_sales_rep_cost_confidentiality_sentinels_do_not_leak(): void
    {
        [$admin] = $this->scenario(['customer_name' => '<script>REPORT-XSS</script>', 'supplier_name' => 'SENTINEL-SUPPLIER-COST']);
        $this->actingAs($admin)->get(route('reports.sales'))->assertOk()->assertSee('&lt;script&gt;REPORT-XSS&lt;/script&gt;', false)->assertDontSee('<script>REPORT-XSS</script>', false);
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        foreach (['reports.index', 'reports.expenses', 'reports.purchases', 'reports.summary', 'reports.staff'] as $route) {
            $this->actingAs($rep)->get(route($route))->assertForbidden()->assertDontSee('SENTINEL-SUPPLIER-COST');
        }
        foreach ([route('dashboard'), route('inventory.index'), route('sales.index'), route('customers.index')] as $url) {
            $this->get($url)->assertOk()->assertDontSee('SENTINEL-SUPPLIER-COST')->assertDontSee('40000.00');
        }
    }

    public function test_paginated_report_lists_partition_tied_rows_without_duplication_or_loss(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        [$saleIds, $paymentIds, $productIds, $customerIds] = $this->tiedReportRows($admin);
        $period = ['from' => '2026-05-14', 'to' => '2026-05-14'];

        $expectations = [
            'reports.sales' => array_reverse($saleIds),
            'reports.collections' => array_reverse($paymentIds),
            'reports.products' => $productIds,
            'reports.customers' => $customerIds,
        ];

        foreach ($expectations as $route => $expected) {
            $key = $route === 'reports.products' ? 'product_id' : 'id';
            $pages = [];
            foreach ([1, 2] as $page) {
                $rows = $this->actingAs($admin)->get(route($route, $period + ['page' => $page]))
                    ->assertOk()->viewData('rows');
                $pages[$page] = $rows->pluck($key)->map(fn ($id) => (int) $id)->all();
            }

            $size = PerPage::DEFAULT;
            $this->assertCount($size, $pages[1], "{$route} page 1 must be full");
            $this->assertCount(count($expected) - $size, $pages[2], "{$route} page 2 must hold the remainder");
            $this->assertSame([], array_intersect($pages[1], $pages[2]), "{$route} must not repeat a row across pages");
            $this->assertSame($expected, array_merge($pages[1], $pages[2]),
                "{$route} must partition tied rows in a stable, deterministic order");
        }
    }

    /**
     * Every Sale, payment and product line below carries an identical ordering value, so the
     * paginated reports can only separate pages by their secondary key.
     *
     * @return array{0: list<int>, 1: list<int>, 2: list<int>, 3: list<int>}
     */
    private function tiedReportRows(User $admin): array
    {
        $stamp = '2026-05-14 10:00:00';
        $saleIds = $paymentIds = $productIds = $customerIds = [];

        foreach (range(1, 20) as $index) {
            $customer = Customer::factory()->create();
            $product = Product::factory()->create(['current_stock' => '10.000']);
            $sale = Sale::factory()->create([
                'customer_id' => $customer->id, 'sold_by' => $admin->id, 'status' => SaleStatus::Completed,
                'subtotal' => '5000.00', 'total_amount' => '5000.00',
                'amount_paid' => '5000.00', 'balance_due' => '0.00', 'payment_status' => PaymentStatus::Paid,
            ]);
            $this->item($sale, $product, '1.000', '5000.00');
            $this->payment($sale, $admin, sprintf('PMT-TIE-%02d', $index), '5000.00', PaymentMethod::Cash, SalePaymentType::Initial, 5000, 0);

            $saleIds[] = $sale->id;
            $customerIds[] = $customer->id;
            $productIds[] = $product->id;
            $paymentIds[] = (int) DB::table('sale_payments')->where('sale_id', $sale->id)->value('id');
        }

        DB::table('sales')->whereIn('id', $saleIds)->update(['created_at' => $stamp, 'updated_at' => $stamp]);
        DB::table('sale_payments')->whereIn('id', $paymentIds)->update(['paid_at' => $stamp]);

        return [$saleIds, $paymentIds, $productIds, $customerIds];
    }

    private function scenario(array $options = []): array
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $seller = User::factory()->create(['role' => UserRole::SalesRep, 'name' => 'Seller Snapshot']);
        $customer = Customer::factory()->create(['first_name' => $options['customer_name'] ?? 'Customer', 'last_name' => 'Snapshot']);
        $product = Product::factory()->create(['name' => 'Historical Product', 'current_stock' => '10.000']);
        $saleA = Sale::factory()->create(['customer_id' => $customer->id, 'sold_by' => $seller->id, 'subtotal' => '100000.00', 'total_amount' => '100000.00', 'amount_paid' => '50000.00', 'balance_due' => '50000.00', 'payment_status' => PaymentStatus::Partial]);
        $saleB = Sale::factory()->create(['customer_id' => $customer->id, 'sold_by' => $seller->id, 'subtotal' => '50000.00', 'total_amount' => '50000.00', 'amount_paid' => '0.00', 'balance_due' => '50000.00', 'payment_status' => PaymentStatus::Unpaid]);
        $this->item($saleA, $product, '2.000', '100000.00');
        $this->item($saleA, Product::factory()->create(), '1.000', '0.00');
        $this->item($saleB, $product, '1.000', '50000.00');
        $this->payment($saleA, $admin, 'PMT-A1', '20000.00', PaymentMethod::Cash, SalePaymentType::Initial, 20000, 80000);
        $this->payment($saleA, $admin, 'PMT-A2', '30000.00', PaymentMethod::Transfer, SalePaymentType::Settlement, 50000, 50000);

        $expenseCategory = app(CreateExpenseCategory::class)->execute($admin, ['name' => 'Utilities']);
        $expenseToken = Str::random(64);
        $request = new ExpenseRequest;
        $request->token_hash = hash('sha256', $expenseToken);
        $request->actor_id = $admin->id;
        $request->session_id = 'report';
        $request->expires_at = now()->addMinute();
        $request->save();
        app(RecordExpense::class)->execute($admin, ['request_token' => $expenseToken, 'expense_category_id' => $expenseCategory->id, 'amount' => '10000.00', 'payment_method' => 'cash', 'description' => 'Operating electricity', 'note' => 'PRIVATE EXPENSE NOTE', 'incurred_at' => now(config('business.timezone'))->toDateString()], 'report');

        $supplier = app(CreateSupplier::class)->execute($admin, ['name' => $options['supplier_name'] ?? 'Supplier Snapshot']);
        $purchaseToken = Str::random(64);
        $purchaseRequest = new PurchaseRequest;
        $purchaseRequest->token_hash = hash('sha256', $purchaseToken);
        $purchaseRequest->actor_id = $admin->id;
        $purchaseRequest->session_id = 'report';
        $purchaseRequest->expires_at = now()->addMinute();
        $purchaseRequest->save();
        app(ReceivePurchase::class)->execute($admin, ['request_token' => $purchaseToken, 'supplier_id' => $supplier->id, 'items' => [['product_id' => $product->id, 'quantity' => '5.000', 'unit_cost' => '8000.00']]], 'report');

        return [$admin, $seller, $customer, $product, $saleA, $saleB];
    }

    private function item(Sale $sale, Product $product, string $quantity, string $total): void
    {
        $item = new SaleItem;
        $item->sale_id = $sale->id;
        $item->product_id = $product->id;
        $item->product_sku_snapshot = $product->sku;
        $item->product_name_snapshot = $product->name;
        $item->unit_snapshot = $product->unit->value;
        $item->quantity = $quantity;
        $item->unit_price = bccomp($quantity, '0', 3) ? bcdiv($total, $quantity, 2) : '0.00';
        $item->line_total = $total;
        $item->created_at = now();
        $item->save();
    }

    private function payment(Sale $sale, User $recorder, string $number, string $amount, PaymentMethod $method, SalePaymentType $type, int $cumulative, int $balance): void
    {
        $payment = new SalePayment;
        $payment->payment_number = $number;
        $payment->sale_id = $sale->id;
        $payment->customer_id = $sale->customer_id;
        $payment->amount = $amount;
        $payment->payment_method = $method;
        $payment->payment_type = $type;
        $payment->recorded_by = $recorder->id;
        $payment->recorded_by_name_snapshot = $recorder->name;
        $payment->paid_at = now();
        $payment->cumulative_paid_after = (string) $cumulative;
        $payment->balance_after = (string) $balance;
        $payment->payment_status_after = $balance ? PaymentStatus::Partial : PaymentStatus::Paid;
        $payment->initial_sale_guard = $type === SalePaymentType::Initial ? $sale->id : null;
        $payment->save();
    }
}
