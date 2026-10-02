<?php

namespace Tests\Feature\Tenancy;

use App\Actions\Expense\RecordExpense;
use App\Actions\Purchase\ReceivePurchase;
use App\Actions\Sale\CorrectSale;
use App\Actions\Sale\CreateSale;
use App\Actions\Sale\RecordSalePayment;
use App\Actions\Sale\RecordSaleRefund;
use App\Actions\Sale\RecordSaleReturn;
use App\Actions\Sale\RequestDraftSaleDiscount;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\InventoryMovement;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\SaleCorrection;
use App\Models\SaleDiscountRequest;
use App\Models\SaleDraft;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SalePaymentRequest;
use App\Models\SaleRefund;
use App\Models\SaleRefundRequest;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\SaleReturnRequest;
use App\Reports\BusinessReports;
use App\Reports\ReportFilters;
use App\Tenancy\BusinessContextException;
use App\Tenancy\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\BuildsTransactionWorld;
use Tests\TestCase;

/**
 * Two businesses trading side by side through the real actions. B's world is A's at ten times the
 * money, so any cross-business leak in a list, an aggregate or a document route shows up as a
 * wrong number rather than a coincidence.
 */
class TransactionIsolationTest extends TestCase
{
    use BuildsTransactionWorld, RefreshDatabase;

    private Business $a;

    private Business $b;

    /** @var array<string, mixed> */
    private array $worldA;

    /** @var array<string, mixed> */
    private array $worldB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Business::factory()->create(['name' => 'Alpha Spares']);
        $this->b = Business::factory()->create(['name' => 'Bravo Hardware']);
        $this->worldA = $this->world($this->a, 'Ada', '50.00');
        $this->worldB = $this->world($this->b, 'Bola', '500.00');
    }

    /* --------------------------------------------------------------- ownership */

    public function test_every_document_takes_the_business_of_what_owns_it(): void
    {
        foreach ([[$this->a, $this->worldA], [$this->b, $this->worldB]] as [$business, $world]) {
            foreach (['sale', 'paidSale', 'walkIn'] as $key) {
                $this->assertSame($business->id, $world[$key]->business_id, $key);
            }

            $this->assertSame($business->id, $world['walkIn']->business_id, 'A walk-in sale belongs to its seller\'s business');

            foreach ([
                'sale_items' => 'sale_id', 'sale_payments' => 'sale_id', 'sale_returns' => 'sale_id', 'sale_refunds' => 'sale_id',
            ] as $table => $column) {
                $this->assertSame([$business->id], DB::table($table)->whereIn($column, [$world['sale']->id, $world['paidSale']->id, $world['walkIn']->id])->distinct()->pluck('business_id')->all(), $table);
            }

            $this->assertSame([$business->id], DB::table('sale_return_items')->where('sale_return_id', $world['return']->id)->distinct()->pluck('business_id')->all());
            $this->assertSame([$business->id], DB::table('purchase_items')->where('purchase_id', $world['purchase']->id)->distinct()->pluck('business_id')->all());
            $this->assertSame($business->id, DB::table('expenses')->where('id', $world['expense']->id)->value('business_id'));
            $this->assertSame($business->id, DB::table('sale_drafts')->where('id', $world['draft']->sale_draft_id)->value('business_id'));
            $this->assertSame($business->id, $world['draft']->business_id);

            // Every movement a document caused shares the document's and the product's business.
            $this->assertSame(0, DB::table('inventory_movements as m')->join('sales as s', function ($join): void {
                $join->on('s.id', '=', 'm.reference_id')->where('m.reference_type', Sale::class);
            })->whereColumn('s.business_id', '<>', 'm.business_id')->count());
        }
    }

    public function test_document_numbers_may_repeat_across_businesses_but_not_within_one(): void
    {
        $shared = [
            'sales' => ['sale_number', $this->worldA['sale']->id, $this->worldB['sale']->id, $this->worldA['paidSale']->id],
            'sale_payments' => ['payment_number', $this->worldA['payment']->id, $this->worldB['payment']->id, $this->worldA['initialPayment']],
            'sale_returns' => ['return_number', $this->worldA['return']->id, $this->worldB['return']->id, null],
            'sale_refunds' => ['refund_number', $this->worldA['refund']->id, $this->worldB['refund']->id, null],
            'purchases' => ['purchase_number', $this->worldA['purchase']->id, $this->worldB['purchase']->id, null],
            'expenses' => ['expense_number', $this->worldA['expense']->id, $this->worldB['expense']->id, null],
        ];

        foreach ($shared as $table => [$column, $inA, $inB, $secondInA]) {
            $number = DB::table($table)->where('id', $inA)->value($column);
            DB::table($table)->where('id', $inB)->update([$column => $number]);
            $this->assertSame($number, DB::table($table)->where('id', $inB)->value($column), "{$table}.{$column} may repeat in another business");

            if ($secondInA !== null) {
                try {
                    DB::table($table)->where('id', $secondInA)->update([$column => $number]);
                    $this->fail("{$table}.{$column} must be unique within a business.");
                } catch (QueryException $exception) {
                    $this->assertSame('23000', $exception->errorInfo[0]);
                }
            }
        }

        // The opaque public identifier stays unique across the whole platform.
        $this->expectException(QueryException::class);
        DB::table('sales')->where('id', $this->worldB['sale']->id)->update(['public_id' => $this->worldA['sale']->public_id]);
    }

    /* ------------------------------------------------------------ lists & routes */

    public function test_lists_show_only_the_businesss_own_documents(): void
    {
        $admin = $this->worldA['admin'];

        $this->actingAs($admin)->get(route('sales.index'))->assertOk()->assertSee('Ada')->assertDontSee('Bola');
        $this->actingAs($admin)->get(route('sale-payments.index'))->assertOk()->assertSee($this->worldA['payment']->payment_number)->assertDontSee($this->worldB['payment']->payment_number);
        $this->actingAs($admin)->get(route('returns.index'))->assertOk()->assertSee($this->worldA['return']->return_number)->assertDontSee($this->worldB['return']->return_number);
        $this->actingAs($admin)->get(route('refunds.index'))->assertOk()->assertSee($this->worldA['refund']->refund_number)->assertDontSee($this->worldB['refund']->refund_number);
        $this->actingAs($admin)->get(route('purchases.index'))->assertOk()->assertSee('Ada Supply')->assertDontSee('Bola Supply');
        $this->actingAs($admin)->get(route('expenses.index'))->assertOk()->assertSee('Ada Rent')->assertDontSee('Bola Rent');
        $this->actingAs($admin)->get(route('discounts.index'))->assertOk()->assertDontSee('Bola discount reason');

        $export = $this->actingAs($admin)->get(route('sales.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString($this->worldA['sale']->sale_number, $export);
        $this->assertStringNotContainsString($this->worldB['sale']->sale_number, $export);
    }

    public function test_every_document_route_is_not_found_for_another_business(): void
    {
        $b = $this->worldB;
        $sale = $b['sale'];
        $snapshot = DB::table('sales')->where('id', $sale->id)->first();

        foreach ([
            ['get', route('sales.show', $sale)], ['get', route('sales.lines', $sale)],
            ['get', route('sales.receipt', $sale)], ['get', route('sales.receipt.pdf', $sale)],
            ['get', route('sales.activity', $sale)],
            ['post', route('sales.payments.store', $sale)], ['get', route('sales.payments.show', [$sale, $b['payment']])],
            ['get', route('sales.payments.receipt', [$sale, $b['payment']])], ['post', route('sales.void', $sale)],
            ['get', route('sales.corrections.create', $sale)], ['get', route('sales.corrections.panel', $sale)],
            ['post', route('sales.corrections.store', $sale)], ['get', route('sales.corrections.index', $sale)],
            ['get', route('sales.returns.create', $sale)], ['get', route('sales.returns.panel', $sale)],
            ['get', route('sales.returns.preview', $sale)], ['post', route('sales.returns.store', $sale)],
            ['get', route('sales.refunds.create', $sale)], ['post', route('sales.refunds.store', $sale)],
            ['get', route('returns.show', $b['return'])], ['get', route('returns.receipt', $b['return'])],
            ['get', route('refunds.show', $b['refund'])], ['get', route('refunds.receipt', $b['refund'])],
            ['get', route('purchases.show', $b['purchase'])], ['get', route('purchases.receipt', $b['purchase'])],
            ['get', route('expenses.show', $b['expense'])], ['get', route('expenses.receipt', $b['expense'])],
            ['get', route('discounts.show', $b['draft'])], ['post', route('discounts.approve', $b['draft'])],
            ['post', route('discounts.decline', $b['draft'])],
        ] as [$method, $url]) {
            $this->actingAs($this->worldA['admin'])->{$method}($url, ['reason' => 'Cross-business attempt', 'amount' => '1'])->assertNotFound();
        }

        $this->assertEquals($snapshot, DB::table('sales')->where('id', $sale->id)->first(), 'B\'s sale must be untouched');
        $this->assertSame('pending', DB::table('sale_discount_requests')->where('id', $b['draft']->id)->value('status'));
    }

    /* --------------------------------------------------------- relationship injection */

    public function test_a_sale_cannot_name_another_businesss_customer_product_or_draft(): void
    {
        $a = $this->worldA;
        $payload = ['customer_id' => $a['customer']->id, 'is_walk_in' => '0', 'sale_date' => now()->toDateString(), 'payment_method' => 'cash', 'amount_paid' => '0',
            'products' => [['product_id' => $a['product']->id, 'quantity' => '1']]];

        foreach ([
            'customer_id' => array_merge($payload, ['customer_id' => $this->worldB['customer']->id]),
            'products.0.product_id' => array_merge($payload, ['products' => [['product_id' => $this->worldB['product']->id, 'quantity' => '1']]]),
            'sale_draft_id' => array_merge($payload, ['sale_draft_id' => $this->worldB['draft']->sale_draft_id]),
            'business_id' => array_merge($payload, ['business_id' => $this->b->id]),
        ] as $field => $attempt) {
            $this->actingAs($a['admin'])->post(route('sales.store'), $attempt)->assertSessionHasErrors($field);
        }

        $this->inBusiness($this->a, function () use ($a): void {
            try {
                app(CreateSale::class)->execute($a['admin'], ['customer_id' => $a['customer']->id, 'payment_method' => 'cash', 'amount_paid' => '0',
                    'products' => [['product_id' => $this->worldB['product']->id, 'quantity' => '1']]]);
                $this->fail('The action must refuse another business\'s product.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('products', $exception->errors());
            }
        });
    }

    public function test_drafts_corrections_returns_and_refunds_refuse_cross_business_references(): void
    {
        $a = $this->worldA;
        $b = $this->worldB;

        // Unpaid, so a customer correction is permitted and only the cross-business reference can refuse it.
        $unpaid = $this->inBusiness($this->a, fn () => $this->sell($a['admin'], $a['customer'], $a['product'], '1', '0'));

        $this->inBusiness($this->a, function () use ($a, $b, $unpaid): void {
            $attempts = [
                'draft customer' => fn () => app(RequestDraftSaleDiscount::class)->execute($a['admin'], [
                    'amount' => '1.00', 'reason' => 'Loyal customer discount', 'is_walk_in' => false, 'customer_id' => $b['customer']->id,
                    'sale_date' => now()->toDateString(), 'products' => [['product_id' => $a['product']->id, 'quantity' => '1']],
                ]),
                'correction product' => fn () => app(CorrectSale::class)->execute($a['admin'], $unpaid, [
                    'reason' => 'Wrong product was recorded', 'customer_id' => $a['customer']->id,
                    'products' => [['product_id' => $b['product']->id, 'quantity' => '1']],
                ]),
                'correction customer' => fn () => app(CorrectSale::class)->execute($a['admin'], $unpaid, [
                    'reason' => 'Wrong customer was recorded', 'customer_id' => $b['customer']->id,
                    'products' => [['product_id' => $a['product']->id, 'quantity' => '1']],
                ]),
                'return line' => fn () => app(RecordSaleReturn::class)->execute($a['admin'], $a['paidSale'], [
                    'request_token' => $this->token(SaleReturnRequest::class, $a['admin'], $a['paidSale']), 'reason' => 'Damaged',
                    'items' => [['sale_item_id' => $b['paidItem']->id, 'quantity' => '1', 'disposition' => 'restock']],
                ], 'isolation'),
                'refund return' => fn () => app(RecordSaleRefund::class)->execute($a['admin'], $a['paidSale'], [
                    'request_token' => $this->token(SaleRefundRequest::class, $a['admin'], $a['paidSale']), 'amount' => '1.00',
                    'payment_method' => 'cash', 'reason' => 'Refund', 'sale_return_id' => $b['return']->id,
                ], 'isolation'),
            ];

            foreach ($attempts as $label => $attempt) {
                try {
                    $attempt();
                    $this->fail("{$label}: a cross-business reference must be refused.");
                } catch (ValidationException|ModelNotFoundException) {
                    $this->addToAssertionCount(1);
                }
            }
        });

        $this->assertSame(1, DB::table('sale_drafts')->where('business_id', $this->a->id)->count());
        $this->assertSame(0, DB::table('sale_corrections')->count());
        $this->assertSame(1, DB::table('sale_returns')->where('business_id', $this->a->id)->count());
        $this->assertSame(1, DB::table('sale_refunds')->where('business_id', $this->a->id)->count());
    }

    public function test_purchases_and_expenses_refuse_another_businesss_supplier_product_and_category(): void
    {
        $a = $this->worldA;
        $b = $this->worldB;

        $this->inBusiness($this->a, function () use ($a, $b): void {
            foreach ([
                'supplier' => fn () => app(ReceivePurchase::class)->execute($a['admin'], ['request_token' => $this->purchaseToken($a['admin']),
                    'supplier_id' => $b['supplier']->id, 'items' => [['product_id' => $a['product']->id, 'quantity' => '1', 'unit_cost' => '1']]], 'isolation'),
                'product' => fn () => app(ReceivePurchase::class)->execute($a['admin'], ['request_token' => $this->purchaseToken($a['admin']),
                    'supplier_id' => $a['supplier']->id, 'items' => [['product_id' => $b['product']->id, 'quantity' => '1', 'unit_cost' => '1']]], 'isolation'),
                'expense category' => fn () => app(RecordExpense::class)->execute($a['admin'], ['request_token' => $this->expenseToken($a['admin']),
                    'expense_category_id' => $b['expenseCategory']->id, 'amount' => '1.00', 'payment_method' => 'cash', 'description' => 'x',
                    'incurred_at' => now()->toDateString()], 'isolation'),
            ] as $label => $attempt) {
                try {
                    $attempt();
                    $this->fail("{$label}: a cross-business reference must be refused.");
                } catch (ValidationException|ModelNotFoundException) {
                    $this->addToAssertionCount(1);
                }
            }
        });

        $this->actingAs($a['admin'])->post(route('purchases.store'), ['supplier_id' => $b['supplier']->id, 'items' => [['product_id' => $a['product']->id, 'quantity' => '1', 'unit_cost' => '1']]])
            ->assertSessionHasErrors('supplier_id');
        $this->actingAs($a['admin'])->post(route('expenses.store'), ['expense_category_id' => $b['expenseCategory']->id, 'amount' => '1.00', 'payment_method' => 'cash', 'incurred_at' => now()->toDateString()])
            ->assertSessionHasErrors('expense_category_id');

        $this->assertSame(1, DB::table('purchases')->where('business_id', $this->a->id)->count());
        $this->assertSame(1, DB::table('expenses')->where('business_id', $this->a->id)->count());
    }

    public function test_the_database_refuses_every_cross_business_link(): void
    {
        $a = $this->worldA;
        $b = $this->worldB;
        // A second B draft with no pending request, so re-pointing A's request at it passes the
        // table's own guard checks and only the cross-business foreign key can refuse it.
        $spare = (array) DB::table('sale_drafts')->where('id', $b['draft']->sale_draft_id)->first();
        unset($spare['id']);
        $spareDraft = DB::table('sale_drafts')->insertGetId(['public_id' => (string) Str::ulid()] + $spare);

        $attempts = [
            'sale → customer' => ['sales', $a['sale']->id, ['customer_id' => $b['customer']->id]],
            'sale → seller' => ['sales', $a['sale']->id, ['sold_by' => $b['admin']->id]],
            'line → product' => ['sale_items', $a['item']->id, ['product_id' => $b['product']->id]],
            'line → sale' => ['sale_items', $a['item']->id, ['sale_id' => $b['sale']->id]],
            'payment → sale' => ['sale_payments', $a['payment']->id, ['sale_id' => $b['sale']->id]],
            'return → sale' => ['sale_returns', $a['return']->id, ['sale_id' => $b['paidSale']->id]],
            'return line → sale line' => ['sale_return_items', $a['returnItem'], ['sale_item_id' => $b['paidItem']->id]],
            'refund → return' => ['sale_refunds', $a['refund']->id, ['sale_return_id' => $b['return']->id]],
            'draft → customer' => ['sale_drafts', $a['draft']->sale_draft_id, ['customer_id' => $b['customer']->id]],
            'discount → draft' => ['sale_discount_requests', $a['draft']->id, ['sale_draft_id' => $spareDraft, 'pending_draft_guard' => $spareDraft]],
            'purchase → supplier' => ['purchases', $a['purchase']->id, ['supplier_id' => $b['supplier']->id]],
            'purchase line → product' => ['purchase_items', $a['purchaseItem'], ['product_id' => $b['product']->id]],
            'expense → category' => ['expenses', $a['expense']->id, ['expense_category_id' => $b['expenseCategory']->id]],
        ];

        foreach ($attempts as $label => [$table, $id, $change]) {
            try {
                DB::table($table)->where('id', $id)->update($change);
                $this->fail("MySQL must refuse {$label} across businesses.");
            } catch (QueryException $exception) {
                // 1452: the foreign key refused it, not some unrelated constraint.
                $this->assertSame(1452, $exception->errorInfo[1], $label);
            }
        }
    }

    public function test_a_stock_movement_cannot_record_another_businesss_document(): void
    {
        $this->expectException(LogicException::class);

        $this->inBusiness($this->a, function (): void {
            $movement = new InventoryMovement;
            foreach (['business_id' => $this->a->id, 'product_id' => $this->worldA['product']->id, 'type' => 'adjustment', 'quantity_change' => '1',
                'quantity_before' => '0', 'quantity_after' => '1', 'reference_type' => Sale::class, 'reference_id' => $this->worldB['sale']->id] as $key => $value) {
                $movement->{$key} = $value;
            }
            $movement->save();
        });
    }

    /* ----------------------------------------------------- aggregates & reports */

    public function test_reports_are_computed_per_business(): void
    {
        $alpha = $this->metrics($this->a);
        $bravo = $this->metrics($this->b);

        // A: two registered sales of 100 and a walk-in of 50, 40 + 20 + 100 + 50 collected, 40 still owed.
        $this->assertSame(['250.00', '210.00', '40.00', '70.00', '300.00', 3], [
            $alpha['Gross Sales'], $alpha['Customer Collections'], $alpha['Current Outstanding Receivables'],
            $alpha['Operating Expenses'], $alpha['Inventory Purchases'], $alpha['Sales'],
        ]);
        $this->assertSame(['2500.00', '2100.00', '400.00', '700.00', '3000.00', 3], [
            $bravo['Gross Sales'], $bravo['Customer Collections'], $bravo['Current Outstanding Receivables'],
            $bravo['Operating Expenses'], $bravo['Inventory Purchases'], $bravo['Sales'],
        ]);

        $this->assertSame(0, $this->inBusiness($this->a, fn () => app(BusinessReports::class)->ledgerIntegrityMismatches()));
    }

    /* ------------------------------------------------------ scope & defense */

    public function test_every_transaction_model_fails_closed_without_a_business(): void
    {
        app(CurrentBusiness::class)->forget();
        $refused = 0;

        foreach ([Sale::class, SaleDraft::class, SaleItem::class, SaleCorrection::class, SalePayment::class, SaleDiscountRequest::class,
            SaleReturn::class, SaleReturnItem::class, SaleRefund::class, Purchase::class, PurchaseItem::class, Expense::class] as $model) {
            try {
                $model::query()->count();
            } catch (BusinessContextException) {
                $refused++;
            }
        }

        $this->assertSame(12, $refused);
    }

    public function test_policies_deny_a_record_of_another_business_even_when_it_is_resolved_directly(): void
    {
        $saleB = Sale::acrossBusinesses()->findOrFail($this->worldB['sale']->id);
        $adminA = $this->worldA['admin'];

        foreach (['view', 'recordPayment', 'correct', 'void', 'viewAudit'] as $ability) {
            $this->assertFalse(Gate::forUser($adminA)->allows($ability, $saleB), $ability);
        }

        $this->assertFalse(Gate::forUser($adminA)->allows('view', Purchase::acrossBusinesses()->findOrFail($this->worldB['purchase']->id)));
        $this->assertFalse(Gate::forUser($adminA)->allows('view', Expense::acrossBusinesses()->findOrFail($this->worldB['expense']->id)));
        $this->assertTrue(Gate::forUser($this->worldB['admin'])->allows('view', $saleB));

        // An action handed B's sale refuses before any token is even read.
        $this->expectException(AuthorizationException::class);
        $this->inBusiness($this->a, fn () => app(RecordSalePayment::class)->execute($adminA, $saleB, [
            'request_token' => $this->token(SalePaymentRequest::class, $this->worldB['admin'], $saleB), 'amount' => '1.00', 'payment_method' => 'cash', 'note' => null,
        ], 'isolation'));
    }

    /** @return array<string, string|int> */
    private function metrics(Business $business): array
    {
        $today = CarbonImmutable::now(config('business.timezone'))->toDateString();
        $filters = new ReportFilters($today, $today, '', '', '', '', '', '');

        return $this->inBusiness($business, fn () => collect(app(BusinessReports::class)->summary($filters)['metrics'])
            ->mapWithKeys(fn (array $metric): array => [$metric['label'] => $metric['value']])->all());
    }
}
