<?php

namespace Tests\Feature\Dashboard;

use App\Actions\Expense\CreateExpenseCategory;
use App\Actions\Expense\RecordExpense;
use App\Actions\Purchase\ReceivePurchase;
use App\Actions\Sale\RecordSaleRefund;
use App\Actions\Sale\RecordSaleReturn;
use App\Actions\Sale\VoidSale;
use App\Actions\Supplier\CreateSupplier;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SalePaymentType;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\ExpenseRequest;
use App\Models\Product;
use App\Models\PurchaseRequest;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleRefundRequest;
use App\Models\SaleReturnRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private const TIE_LIMIT = 5;

    private const TIE_ROWS = 7;

    public function test_guest_is_redirected_and_every_role_can_load_its_own_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));

        foreach ([UserRole::Admin, UserRole::Manager, UserRole::SalesRep] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('dashboard'))->assertOk()->assertSee('Operational dashboard');
            auth()->logout();
        }
    }

    public function test_an_empty_database_renders_safe_zero_values_for_every_role(): void
    {
        foreach ([UserRole::Admin, UserRole::Manager, UserRole::SalesRep] as $role) {
            $response = $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('dashboard'))->assertOk();
            $response->assertSee('₦0.00')->assertSee('Nothing needs attention right now.');
            $response->assertSee('No Sales recorded yet.');
            $this->assertStringNotContainsString('NAN', $response->getContent());
            $this->assertStringNotContainsString('Division by zero', $response->getContent());
            auth()->logout();
        }
    }

    public function test_default_period_is_the_current_business_month_and_custom_ranges_apply(): void
    {
        // 2026-09-30 23:30 UTC is already 2026-10-01 00:30 in Africa/Lagos.
        Carbon::setTestNow(Carbon::parse('2026-09-30 23:30:00', 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-30 23:30:00', 'UTC'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('Period 2026-10-01 through 2026-10-31 inclusive', $html);
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        [$sale] = $this->sale('100000.00', '0.00', '2026-05-10 09:00:00');
        $this->get(route('dashboard', ['from' => '2026-05-01', 'to' => '2026-05-31']))->assertOk()
            ->assertSee('Period 2026-05-01 through 2026-05-31 inclusive')
            ->assertSee($sale->sale_number)
            ->assertSee('₦100,000.00');
        $this->get(route('dashboard', ['from' => '2026-06-01', 'to' => '2026-06-30']))->assertOk()
            ->assertSee('Period 2026-06-01 through 2026-06-30 inclusive');
        $this->assertSame('0.00', $this->periodGrossSales($admin, '2026-06-01', '2026-06-30'));
    }

    public function test_fixture_a_return_adjusted_receivable(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        [$sale, $item] = $this->sale('100000.00', '20000.00');
        $this->recordReturn($admin, $sale, $item, '0.600');   // 0.600 x 50,000 = 30,000

        $this->assertSame('50000.00', $sale->fresh()->balance_due);
        $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertSame('100000.00', $this->metric($html, 'Gross Sales'));
        $this->assertSame('20000.00', $this->metric($html, 'Customer Collections'));
        $this->assertSame('30000.00', $this->metric($html, 'Returns Value'));
        $this->assertSame('50000.00', $this->metric($html, 'Current Outstanding Receivables'));
        $this->assertSame('0.00', $this->metric($html, 'Refundable Customer Credit'));
    }

    public function test_fixture_b_refund_keeps_collections_historical(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        [$sale, $item] = $this->sale('100000.00', '100000.00');
        $this->recordReturn($admin, $sale, $item, '0.600');
        $this->recordRefund($admin, $sale, '20000.00');

        $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertSame('100000.00', $this->metric($html, 'Gross Sales'));
        $this->assertSame('100000.00', $this->metric($html, 'Customer Collections'));
        $this->assertSame('20000.00', $this->metric($html, 'Refunds Paid'));
        $this->assertSame('0.00', $this->metric($html, 'Current Outstanding Receivables'));
        $this->assertSame('10000.00', $this->metric($html, 'Refundable Customer Credit'));
        $this->assertSame('0.00', $this->metric($html, 'Operating Expenses'));
        $this->assertSame('0.00', $this->metric($html, 'Inventory Purchases'));
        // amount_paid is historical evidence and must survive the refund untouched.
        $this->assertSame('100000.00', $sale->fresh()->amount_paid);
    }

    public function test_fixture_c_unpaid_sale(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        [$sale] = $this->sale('80000.00', '0.00');

        $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertSame('80000.00', $this->metric($html, 'Gross Sales'));
        $this->assertSame('0.00', $this->metric($html, 'Customer Collections'));
        $this->assertSame('80000.00', $this->metric($html, 'Current Outstanding Receivables'));
        $this->assertStringContainsString($sale->sale_number, $html);
    }

    public function test_fixture_d_voided_sale_follows_existing_reporting_rules(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        [$sale] = $this->sale('90000.00', '0.00');
        app(VoidSale::class)->execute($admin, $sale->fresh(), 'Dashboard void fixture');
        $this->assertSame(SaleStatus::Voided, $sale->fresh()->status);

        $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertSame('0.00', $this->metric($html, 'Gross Sales'), 'Voided Sales must leave Gross Sales');
        $this->assertSame('0', $this->metric($html, 'Sales Recorded'));
        $this->assertSame('0.00', $this->metric($html, 'Current Outstanding Receivables'), 'Voided Sales must leave receivables');
        $this->assertStringNotContainsString($sale->sale_number, $html);
    }

    public function test_receivables_alert_lists_oldest_outstanding_first_and_is_bounded(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $numbers = [];
        foreach (range(1, 8) as $index) {
            [$sale] = $this->sale('10000.00', '0.00', sprintf('2026-03-%02d 09:00:00', $index));
            $numbers[] = $sale->sale_number;
        }
        $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();
        $section = $this->section($html, 'Oldest outstanding Sales');
        foreach (array_slice($numbers, 0, 5) as $shown) {
            $this->assertStringContainsString($shown, $section);
        }
        foreach (array_slice($numbers, 5) as $hidden) {
            $this->assertStringNotContainsString($hidden, $section);
        }
        $this->assertSame('80000.00', $this->metric($html, 'Current Outstanding Receivables'));
    }

    public function test_low_stock_alert_uses_the_shared_active_definition(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $low = Product::factory()->create(['name' => 'Low Widget', 'current_stock' => '1.000', 'reorder_level' => '5.000', 'is_active' => true]);
        $zero = Product::factory()->create(['name' => 'Zero Widget', 'current_stock' => '0.000', 'reorder_level' => '5.000', 'is_active' => true]);
        $inactive = Product::factory()->create(['name' => 'Dormant Widget', 'current_stock' => '0.000', 'reorder_level' => '5.000', 'is_active' => false]);
        Product::factory()->create(['name' => 'Healthy Widget', 'current_stock' => '99.000', 'reorder_level' => '5.000', 'is_active' => true]);

        $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertSame('2', $this->metric($html, 'Low-stock Products'));
        $this->assertStringContainsString($low->name, $html);
        $this->assertStringContainsString($zero->name, $html);
        $this->assertStringNotContainsString($inactive->name, $html);
        $this->assertStringContainsString('Active Products out of stock', $html);
    }

    public function test_integrity_warning_appears_on_drift_and_repairs_nothing(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        [$sale, $item] = $this->sale('100000.00', '100000.00');
        $this->recordReturn($admin, $sale, $item, '1.000');
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertDontSee('inconsistency detected');

        $before = DB::table('sale_returns')->orderBy('id')->get()->toArray();
        // Still satisfies sales_return_financials_reconcile; only a ledger comparison can catch it.
        DB::table('sales')->where('id', $sale->id)->update([
            'returned_amount' => '40000.00', 'balance_due' => '0.00', 'refundable_credit' => '40000.00',
        ]);
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Sale aggregate and ledger inconsistency detected')
            ->assertSee('No records were changed');
        $this->assertEquals($before, DB::table('sale_returns')->orderBy('id')->get()->toArray());
        $this->assertSame('40000.00', DB::table('sales')->where('id', $sale->id)->value('returned_amount'));
    }

    public function test_recent_activity_is_bounded_and_links_to_authoritative_detail_pages(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $numbers = [];
        foreach (range(1, 7) as $index) {
            [$sale] = $this->sale('1000.00', '1000.00', sprintf('2026-04-%02d 09:00:00', $index));
            $numbers[] = $sale->sale_number;
        }
        $html = $this->actingAs($admin)->get(route('dashboard', ['from' => '2026-04-01', 'to' => '2026-04-30']))->assertOk()->getContent();
        $section = $this->section($html, 'Recent Sales');
        $this->assertSame(5, substr_count($section, 'SALE-'), 'Recent Sales must stay bounded at five rows');
        $latest = Sale::query()->latest('created_at')->first();
        $this->assertStringContainsString(route('sales.show', $latest), $section);
    }

    public function test_sales_representative_sees_only_their_own_operational_data(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Owner Admin']);
        $rep = User::factory()->create(['role' => UserRole::SalesRep, 'name' => 'Rep One']);
        $otherRep = User::factory()->create(['role' => UserRole::SalesRep, 'name' => 'Rep Two']);

        [$mine] = $this->sale('60000.00', '10000.00', null, $rep);
        [$theirs] = $this->sale('90000.00', '90000.00', null, $otherRep);
        [$adminSale, $adminItem] = $this->sale('100000.00', '100000.00', null, $admin);
        $this->recordReturn($admin, $adminSale, $adminItem, '1.000');
        $this->recordRefund($admin, $adminSale, '10000.00');
        $this->seedExpenseAndPurchase($admin);

        $html = $this->actingAs($rep)->get(route('dashboard'))->assertOk()->getContent();

        // Own figures only.
        $this->assertSame('60000.00', $this->metric($html, 'My Gross Sales'));
        $this->assertSame('10000.00', $this->metric($html, 'My Collections'));
        $this->assertSame('50000.00', $this->metric($html, 'My Outstanding Receivables'));
        $this->assertStringContainsString($mine->sale_number, $html);
        $this->assertStringNotContainsString($theirs->sale_number, $html);
        $this->assertStringNotContainsString($adminSale->sale_number, $html);

        // Company-wide surfaces must be absent entirely.
        foreach (['Operating Expenses', 'Inventory Purchases', 'Refundable Customer Credit', 'Refunds Paid',
            'Returns Value', 'Recent Collections', 'Recent Returns', 'Recent Refunds', 'Recent Expenses',
            'Recent Purchases', 'Sale aggregate and ledger', 'Locked staff', 'Active Staff'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, "Sales Rep dashboard exposed: {$forbidden}");
        }
        foreach (['SENTINEL-SUPPLIER', 'SENTINEL-EXPENSE', 'cost_price', 'unit_cost'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
        // Cost price of a stocked Product must never reach the Sales Rep dashboard.
        $product = Product::factory()->create(['current_stock' => '1.000', 'reorder_level' => '9.000', 'cost_price' => '4321.99', 'is_active' => true]);
        $this->assertStringNotContainsString('4321.99', $this->get(route('dashboard'))->assertOk()->getContent());
        $this->assertStringContainsString($product->name, $this->get(route('dashboard'))->getContent());
    }

    public function test_dashboard_escapes_hostile_snapshot_values(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        [$sale] = $this->sale('50000.00', '0.00');
        DB::table('sales')->where('id', $sale->id)->update(['customer_name_snapshot' => '<script>alert(1)</script>XSSCUST']);
        DB::table('products')->insert([
            'category_id' => Product::factory()->create()->category_id, 'sku' => 'XSS-SKU',
            'name' => '<img src=x onerror=alert(1)>XSSPROD', 'unit' => 'piece', 'cost_price' => '1.00',
            'selling_price' => '2.00', 'current_stock' => '0.000', 'reorder_level' => '5.000',
            'is_active' => true, 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function test_malformed_and_hostile_filters_never_break_the_dashboard(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);
        $probes = [
            ['from' => ['x']], ['to' => ['x']], ['from' => ['a' => ['b' => 'x']]], ['to' => ['a' => ['b' => 'x']]],
            ['from' => 'bogus'], ['to' => '2026-13-45'], ['from' => '0000-00-00', 'to' => '0000-00-00'],
            ['from' => str_repeat('9', 1000)], ['from' => '2030-01-01', 'to' => '2030-12-31'],
            ['from' => "' OR 1=1 --"], ['from' => '2026-01-01', 'to' => '2026-01-01'],
            ['page' => ['x']], ['staff' => ['x']], ['customer' => ['x']],
        ];
        foreach ($probes as $query) {
            $this->get(route('dashboard', $query))->assertOk();
        }
        // A reversed range is rejected by the shared Reporting filter rather than silently swapped.
        $this->from(route('dashboard'))->get(route('dashboard', ['from' => '2026-09-30', 'to' => '2026-09-01']))
            ->assertRedirect(route('dashboard'))->assertSessionHasErrors('from');
    }

    public function test_loading_the_dashboard_writes_nothing_for_any_role(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        [$sale, $item] = $this->sale('100000.00', '100000.00', null, $rep);
        $this->recordReturn($admin, $sale, $item, '0.500');
        $this->recordRefund($admin, $sale, '1000.00');
        $this->seedExpenseAndPurchase($admin);

        $tables = ['sales', 'sale_items', 'sale_payments', 'sale_returns', 'sale_return_items', 'sale_refunds',
            'sale_return_requests', 'sale_refund_requests', 'expenses', 'purchases', 'purchase_items', 'suppliers',
            'customers', 'products', 'inventory_movements', 'users', 'audit_logs'];
        $before = $this->fingerprint($tables);

        // Note: auth()->logout() cycles users.remember_token, so roles are switched with
        // actingAs() alone to keep the window measuring the dashboard and nothing else.
        foreach ([$admin, $manager, $rep] as $user) {
            $this->actingAs($user)->get(route('dashboard'))->assertOk();
            $this->get(route('dashboard', ['from' => '2026-01-01', 'to' => '2026-12-31']))->assertOk();
            $this->get(route('dashboard', ['from' => ['x'], 'to' => ['y']]))->assertOk();
        }

        $this->assertSame($before, $this->fingerprint($tables), 'A dashboard GET mutated business data');
    }

    public function test_query_count_stays_bounded_as_data_volume_grows(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $this->seedExpenseAndPurchase($admin);
        $seed = function (int $count) use ($admin, $rep): void {
            for ($index = 0; $index < $count; $index++) {
                $this->sale('1000.00', '1000.00', null, $index % 2 === 0 ? $admin : $rep);
                if ($index % 5 === 0) {
                    Product::factory()->create(['current_stock' => '0.000', 'reorder_level' => '5.000', 'is_active' => true]);
                    Customer::factory()->create();
                }
            }
        };

        $seed(10);
        $adminSmall = $this->queryCount($admin);
        $repSmall = $this->queryCount($rep);
        $seed(60);
        $adminLarge = $this->queryCount($admin);
        $repLarge = $this->queryCount($rep);

        $this->assertSame($adminSmall, $adminLarge, 'Admin dashboard query count grew with row count');
        $this->assertSame($repSmall, $repLarge, 'Sales Rep dashboard query count grew with row count');
        $this->assertLessThanOrEqual(30, $adminLarge, 'Admin dashboard issues too many queries');
        $this->assertLessThanOrEqual(20, $repLarge, 'Sales Rep dashboard issues too many queries');
        $this->assertGreaterThan(60, Sale::count());
    }

    public function test_every_recent_list_breaks_timestamp_ties_deterministically_by_id(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $seeded = $this->seedTiedActivity($admin);

        $renders = [];
        foreach (range(1, 3) as $attempt) {
            $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();

            foreach ($seeded as $heading => $numbers) {
                $section = $this->section($html, $heading);
                $this->assertSame(self::TIE_LIMIT, substr_count($section, 'border-b pb-2 text-sm'),
                    "{$heading} must render exactly ".self::TIE_LIMIT.' rows');

                $expected = array_reverse(array_slice($numbers, -self::TIE_LIMIT));
                $this->assertSame($expected, $this->renderedOrder($section, $numbers),
                    "{$heading} must show the newest ".self::TIE_LIMIT.' records, highest id first');

                foreach (array_slice($numbers, 0, count($numbers) - self::TIE_LIMIT) as $displaced) {
                    $this->assertStringNotContainsString($displaced.'<', $section,
                        "{$heading} must not show the displaced record {$displaced}");
                }

                $renders[$attempt][$heading] = $this->renderedOrder($section, $numbers);
            }
        }

        $this->assertSame($renders[1], $renders[2], 'Recent lists must be stable across repeated renders');
        $this->assertSame($renders[1], $renders[3], 'Recent lists must be stable across repeated renders');
    }

    public function test_sales_representative_recent_sales_break_ties_deterministically_and_stay_scoped(): void
    {
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $stamp = '2026-05-14 10:00:00';

        $mine = [];
        foreach (range(1, 25) as $index) {
            [$sale] = $this->sale('4000.00', '4000.00', $stamp, $rep);
            $mine[] = $sale->sale_number;
        }
        [$foreign] = $this->sale('4000.00', '4000.00', $stamp, $admin);

        $section = $this->section(
            $this->actingAs($rep)->get(route('dashboard'))->assertOk()->getContent(), 'My recent Sales'
        );

        $this->assertSame(self::TIE_LIMIT, substr_count($section, 'border-b pb-2 text-sm'));
        $this->assertSame(array_reverse(array_slice($mine, -self::TIE_LIMIT)), $this->renderedOrder($section, $mine));
        $this->assertStringNotContainsString($foreign->sale_number.'<', $section,
            'A Sales Representative must never see another seller\'s Sale, tie or not');
    }

    /* ------------------------------------------------------------------ helpers */

    /**
     * Seeds more rows than each recent list can show, all sharing one primary timestamp,
     * so ordering can only be resolved by the secondary id key.
     *
     * @return array<string, list<string>> section heading => domain numbers in creation order
     */
    private function seedTiedActivity(User $admin): array
    {
        $stamp = '2026-05-14 10:00:00';
        $rows = ['sales' => [], 'sale_payments' => [], 'sale_returns' => [], 'sale_refunds' => []];

        foreach (range(1, self::TIE_ROWS) as $index) {
            [$sale, $item] = $this->sale('10000.00', '10000.00', $stamp, $admin);
            $rows['sales'][] = $sale->id;
            $rows['sale_payments'][] = (int) DB::table('sale_payments')->where('sale_id', $sale->id)->value('id');
            $this->recordReturn($admin, $sale, $item, '2.000');
            $rows['sale_returns'][] = (int) DB::table('sale_returns')->where('sale_id', $sale->id)->value('id');
            $this->recordRefund($admin, $sale->fresh(), '10000.00');
            $rows['sale_refunds'][] = (int) DB::table('sale_refunds')->where('sale_id', $sale->id)->value('id');
        }

        [$rows['expenses'], $rows['purchases']] = $this->seedTiedExpensesAndPurchases($admin);

        DB::table('sale_payments')->whereIn('id', $rows['sale_payments'])->update(['paid_at' => $stamp]);
        DB::table('sale_returns')->whereIn('id', $rows['sale_returns'])->update(['returned_at' => $stamp]);
        DB::table('sale_refunds')->whereIn('id', $rows['sale_refunds'])->update(['refunded_at' => $stamp]);
        DB::table('purchases')->whereIn('id', $rows['purchases'])->update(['received_at' => $stamp]);

        $this->assertTieBaseline($rows, $stamp);

        return [
            'Recent Sales' => $this->numbers('sales', 'sale_number', $rows['sales']),
            'Recent Collections' => $this->numbers('sale_payments', 'payment_number', $rows['sale_payments']),
            'Recent Returns' => $this->numbers('sale_returns', 'return_number', $rows['sale_returns']),
            'Recent Refunds' => $this->numbers('sale_refunds', 'refund_number', $rows['sale_refunds']),
            'Recent Expenses' => $this->numbers('expenses', 'expense_number', $rows['expenses']),
            'Recent Purchases' => $this->numbers('purchases', 'purchase_number', $rows['purchases']),
        ];
    }

    /** @return array{0: list<int>, 1: list<int>} */
    private function seedTiedExpensesAndPurchases(User $admin): array
    {
        $category = app(CreateExpenseCategory::class)->execute($admin, ['name' => 'TIE-EXPENSE-CATEGORY']);
        $supplier = app(CreateSupplier::class)->execute($admin, ['name' => 'TIE-SUPPLIER']);
        $product = Product::factory()->create(['current_stock' => '50.000']);
        $expenses = $purchases = [];

        foreach (range(1, self::TIE_ROWS) as $index) {
            $expenses[] = app(RecordExpense::class)->execute($admin, [
                'request_token' => $this->requestToken(new ExpenseRequest, $admin),
                'expense_category_id' => $category->id, 'amount' => '7500.00', 'payment_method' => 'cash',
                'description' => 'Tie fixture', 'incurred_at' => '2026-05-14',
            ], 'dash')->id;

            $purchases[] = app(ReceivePurchase::class)->execute($admin, [
                'request_token' => $this->requestToken(new PurchaseRequest, $admin),
                'supplier_id' => $supplier->id,
                'items' => [['product_id' => $product->id, 'quantity' => '3.000', 'unit_cost' => '2500.00']],
            ], 'dash')->id;
        }

        return [$expenses, $purchases];
    }

    private function requestToken(ExpenseRequest|PurchaseRequest $request, User $actor): string
    {
        $token = Str::random(64);
        foreach (['token_hash' => hash('sha256', $token), 'actor_id' => $actor->id, 'session_id' => 'dash',
            'expires_at' => now()->addMinutes(30)] as $key => $value) {
            $request->$key = $value;
        }
        $request->save();

        return $token;
    }

    /**
     * Confirms the fixture actually ties: without identical primary timestamps and ascending
     * ids the tests below would pass on ordering the fix never had to resolve.
     *
     * @param  array<string, list<int>>  $rows
     */
    private function assertTieBaseline(array $rows, string $stamp): void
    {
        $columns = ['sales' => 'created_at', 'sale_payments' => 'paid_at', 'sale_returns' => 'returned_at',
            'sale_refunds' => 'refunded_at', 'expenses' => 'incurred_at', 'purchases' => 'received_at'];

        foreach ($columns as $table => $column) {
            $this->assertGreaterThan(self::TIE_LIMIT, count($rows[$table]), "{$table} fixture must overflow the list");
            $this->assertSame(array_values($rows[$table]), collect($rows[$table])->sort()->values()->all(),
                "{$table} ids must ascend with creation order");
            $this->assertCount(1, DB::table($table)->whereIn('id', $rows[$table])->distinct()->pluck($column),
                "{$table}.{$column} must be identical across the fixture for the tie to exist");
        }

        $this->assertSame($stamp, Carbon::parse(DB::table('sales')->whereIn('id', $rows['sales'])->value('created_at'))->toDateTimeString());
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function numbers(string $table, string $column, array $ids): array
    {
        $found = DB::table($table)->whereIn('id', $ids)->pluck($column, 'id');

        return array_map(fn (int $id) => (string) $found[$id], $ids);
    }

    /**
     * Reads the identities a section actually rendered, in rendered order. Numbers are matched
     * with their closing delimiter so a shorter number cannot match inside a longer one.
     *
     * @param  list<string>  $numbers
     * @return list<string>
     */
    private function renderedOrder(string $section, array $numbers): array
    {
        $positions = [];
        foreach ($numbers as $number) {
            $at = mb_strpos($section, $number.'<');
            if ($at !== false) {
                $positions[$number] = $at;
            }
        }
        asort($positions);

        return array_keys($positions);
    }

    private function metric(string $html, string $label): string
    {
        $pattern = '/<small class="text-slate-500">'.preg_quote($label, '/').'<\/small>\s*<strong[^>]*>\s*₦?([0-9,\.]+)/u';
        $this->assertMatchesRegularExpression($pattern, $html, "Metric card missing: {$label}");
        preg_match($pattern, $html, $match);

        return str_replace(',', '', $match[1]);
    }

    private function section(string $html, string $heading): string
    {
        $start = mb_strpos($html, $heading);
        $this->assertNotFalse($start, "Section missing: {$heading}");
        $end = mb_strpos($html, '</article>', $start);

        return mb_substr($html, $start, ($end === false ? mb_strlen($html) : $end) - $start);
    }

    private function periodGrossSales(User $user, string $from, string $to): string
    {
        return $this->metric($this->actingAs($user)->get(route('dashboard', compact('from', 'to')))->getContent(), 'Gross Sales');
    }

    /**
     * Counts only the dashboard's own queries. Session-driver reads and writes are excluded
     * because they legitimately differ between a cold and a warm session and say nothing
     * about dashboard cost.
     */
    private function queryCount(User $user): int
    {
        $count = 0;
        DB::listen(function ($query) use (&$count) {
            if (! str_contains($query->sql, '`sessions`')) {
                $count++;
            }
        });
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $count;
    }

    private function fingerprint(array $tables): array
    {
        return collect($tables)->mapWithKeys(fn (string $table) => [$table => [
            'count' => DB::table($table)->count(),
            'sha256' => hash('sha256', DB::table($table)->orderBy('id')->get()->toJson()),
        ]])->all();
    }

    /** @return array{0: Sale, 1: SaleItem} */
    private function sale(string $total, string $paid, ?string $createdAt = null, ?User $seller = null): array
    {
        $seller ??= User::factory()->create(['role' => UserRole::Admin]);
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['current_stock' => '50.000']);
        $balance = bcsub($total, $paid, 2);
        $sale = new Sale;
        $sale->sale_number = 'SALE-'.Str::upper(Str::random(8));
        foreach (['customer_id' => $customer->id, 'customer_code_snapshot' => $customer->customer_code,
            'customer_name_snapshot' => $customer->full_name, 'customer_phone_snapshot' => $customer->phone,
            'status' => SaleStatus::Completed, 'payment_method' => PaymentMethod::Cash,
            'payment_status' => bccomp($balance, '0', 2) === 0 ? PaymentStatus::Paid : (bccomp($paid, '0', 2) > 0 ? PaymentStatus::Partial : PaymentStatus::Unpaid),
            'subtotal' => $total, 'discount_amount' => '0.00', 'total_amount' => $total,
            'amount_paid' => $paid, 'balance_due' => $balance,
            'sold_by' => $seller->id, 'sold_by_name_snapshot' => $seller->name] as $key => $value) {
            $sale->$key = $value;
        }
        $sale->save();
        if ($createdAt !== null) {
            DB::table('sales')->where('id', $sale->id)->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);
        }
        $item = new SaleItem;
        foreach (['sale_id' => $sale->id, 'product_id' => $product->id, 'product_sku_snapshot' => $product->sku,
            'product_name_snapshot' => $product->name, 'unit_snapshot' => $product->unit->value, 'quantity' => '2.000',
            'unit_price' => bcdiv($total, '2', 2), 'line_total' => $total, 'created_at' => now()] as $key => $value) {
            $item->$key = $value;
        }
        $item->save();
        if (bccomp($paid, '0.00', 2) > 0) {
            $payment = new SalePayment;
            foreach (['payment_number' => 'PMT-'.Str::upper(Str::random(9)), 'sale_id' => $sale->id,
                'customer_id' => $customer->id, 'amount' => $paid, 'payment_method' => PaymentMethod::Cash,
                'payment_type' => SalePaymentType::Initial, 'recorded_by' => $seller->id,
                'recorded_by_name_snapshot' => $seller->name, 'paid_at' => $createdAt ?? now(),
                'cumulative_paid_after' => $paid, 'balance_after' => $balance,
                'payment_status_after' => $sale->payment_status, 'initial_sale_guard' => $sale->id] as $key => $value) {
                $payment->$key = $value;
            }
            $payment->save();
        }

        return [$sale->fresh(), $item->fresh()];
    }

    private function recordReturn(User $actor, Sale $sale, SaleItem $item, string $quantity): void
    {
        $token = Str::random(64);
        $request = new SaleReturnRequest;
        foreach (['token_hash' => hash('sha256', $token), 'sale_id' => $sale->id, 'actor_id' => $actor->id,
            'session_id' => 'dash', 'expires_at' => now()->addMinutes(30)] as $key => $value) {
            $request->$key = $value;
        }
        $request->save();
        app(RecordSaleReturn::class)->execute($actor, $sale->fresh(), [
            'request_token' => $token, 'reason' => 'Dashboard fixture',
            'items' => [['sale_item_id' => $item->id, 'quantity' => $quantity, 'disposition' => 'non_restock']],
        ], 'dash');
    }

    private function recordRefund(User $actor, Sale $sale, string $amount): void
    {
        $token = Str::random(64);
        $request = new SaleRefundRequest;
        foreach (['token_hash' => hash('sha256', $token), 'sale_id' => $sale->id, 'actor_id' => $actor->id,
            'session_id' => 'dash', 'expires_at' => now()->addMinutes(30)] as $key => $value) {
            $request->$key = $value;
        }
        $request->save();
        app(RecordSaleRefund::class)->execute($actor, $sale->fresh(), [
            'request_token' => $token, 'amount' => $amount, 'payment_method' => 'cash', 'reason' => 'Dashboard fixture',
        ], 'dash');
    }

    private function seedExpenseAndPurchase(User $admin): void
    {
        $category = app(CreateExpenseCategory::class)->execute($admin, ['name' => 'SENTINEL-EXPENSE-CATEGORY']);
        $token = Str::random(64);
        $request = new ExpenseRequest;
        foreach (['token_hash' => hash('sha256', $token), 'actor_id' => $admin->id, 'session_id' => 'dash',
            'expires_at' => now()->addMinutes(30)] as $key => $value) {
            $request->$key = $value;
        }
        $request->save();
        app(RecordExpense::class)->execute($admin, [
            'request_token' => $token, 'expense_category_id' => $category->id, 'amount' => '7500.00',
            'payment_method' => 'cash', 'description' => 'Dashboard expense', 'incurred_at' => now()->toDateString(),
        ], 'dash');

        $supplier = app(CreateSupplier::class)->execute($admin, ['name' => 'SENTINEL-SUPPLIER']);
        $purchaseToken = Str::random(64);
        $purchaseRequest = new PurchaseRequest;
        foreach (['token_hash' => hash('sha256', $purchaseToken), 'actor_id' => $admin->id, 'session_id' => 'dash',
            'expires_at' => now()->addMinutes(30)] as $key => $value) {
            $purchaseRequest->$key = $value;
        }
        $purchaseRequest->save();
        app(ReceivePurchase::class)->execute($admin, [
            'request_token' => $purchaseToken, 'supplier_id' => $supplier->id,
            'items' => [['product_id' => Product::factory()->create()->id, 'quantity' => '3.000', 'unit_cost' => '2500.00']],
        ], 'dash');
    }
}
