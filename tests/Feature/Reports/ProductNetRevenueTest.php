<?php

namespace Tests\Feature\Reports;

use App\Actions\Sale\CorrectSale;
use App\Actions\Sale\CreateSale;
use App\Actions\Sale\DecideSaleDiscount;
use App\Actions\Sale\IssueReturnRequest;
use App\Actions\Sale\RecordSaleReturn;
use App\Actions\Sale\RequestSaleDiscount;
use App\Actions\Sale\VoidSale;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Reports\ProductSales;
use App\Reports\ReportsDashboard;
use App\Tenancy\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Product reporting, gross and net of the Sale-level discount.
 *
 * Top Products ranks on net product revenue (net of each line's allocated discount and of
 * returns), so its figures reconcile to the Revenue headline. Product Performance keeps gross
 * sales and shows the allocated discount and net beside it. Neither is money collected.
 */
class ProductNetRevenueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private Customer $customer;

    private Product $alpha;

    private Product $bravo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'inventra_test',
            DB::connection()->getDatabaseName(),
            'Refusing to run: these tests write, and this is not the test database.'
        );

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->manager = User::factory()->create(['role' => UserRole::Manager]);
        $this->customer = Customer::factory()->create(['is_active' => true]);
        $this->alpha = Product::factory()->create(['name' => 'Alpha Pump', 'selling_price' => '500.00', 'current_stock' => '100.000']);
        $this->bravo = Product::factory()->create(['name' => 'Bravo Hose', 'selling_price' => '300.00', 'current_stock' => '100.000']);
    }

    private function today(): string
    {
        return CarbonImmutable::now(config('business.timezone'))->toDateString();
    }

    /** @param list<array{0: Product, 1: string}> $lines */
    private function sale(array $lines, string $paid = '0.00', ?string $date = null): Sale
    {
        return app(CreateSale::class)->execute($this->admin, [
            'is_walk_in' => false, 'customer_id' => $this->customer->id, 'sale_date' => $date ?? $this->today(),
            'products' => array_map(fn (array $line): array => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
            'payment_method' => 'cash', 'amount_paid' => $paid,
        ]);
    }

    private function discount(Sale $sale, string $amount): Sale
    {
        $request = app(RequestSaleDiscount::class)->execute($this->manager, $sale->fresh(), ['amount' => $amount, 'reason' => 'Agreed goodwill on the order.']);
        app(DecideSaleDiscount::class)->approve($this->admin, $request);

        return $sale->fresh();
    }

    private function return(Sale $sale, SaleItem $item, string $quantity): void
    {
        $token = app(IssueReturnRequest::class)->execute($this->admin, $sale->fresh(), session()->driver());
        app(RecordSaleReturn::class)->execute($this->admin, $sale->fresh(), [
            'request_token' => $token, 'reason' => 'Returned',
            'items' => [['sale_item_id' => $item->id, 'quantity' => $quantity, 'disposition' => 'restock']],
        ], session()->getId());
    }

    private function figures(?string $from = null, ?string $to = null): array
    {
        return ProductSales::forPeriod($this->admin->business_id, $from ?? $this->today(), $to ?? $this->today());
    }

    /** @return array<string, string> product name => ranked amount */
    private function topProducts(): array
    {
        $month = app(ReportsDashboard::class)->all(Request::create('/'))['periods']['month']['products'];

        return collect($month)->mapWithKeys(fn (array $row): array => [$row['name'] => $row['value']])->all();
    }

    private function revenueHeadline(): string
    {
        return app(ReportsDashboard::class)->all(Request::create('/'))['kpis']['revenue']['value'];
    }

    private function productPerformance(): TestResponse
    {
        return $this->actingAs($this->admin)->get(route('reports.products', ['from' => $this->today(), 'to' => $this->today()]))->assertOk();
    }

    public function test_an_undiscounted_sale_reads_the_same_gross_and_net(): void
    {
        $this->sale([[$this->alpha, '2']], '1000.00');

        $this->assertSame(['gross' => '1000.00', 'discount' => '0.00', 'net' => '1000.00', 'returned' => '0.00', 'revenue' => '1000.00'], $this->figures()[$this->alpha->id]);
        $this->assertSame(['Alpha Pump' => '1000.00'], $this->topProducts());
    }

    public function test_a_discount_is_allocated_by_line_value_and_reconciles_to_revenue(): void
    {
        // ₦500 + ₦300 less ₦80: shares of ₦50 and ₦30.
        $this->discount($this->sale([[$this->alpha, '1'], [$this->bravo, '1']]), '80.00');

        $figures = $this->figures();
        $this->assertSame('50.00', $figures[$this->alpha->id]['discount']);
        $this->assertSame('30.00', $figures[$this->bravo->id]['discount']);
        $this->assertSame(['Alpha Pump' => '450.00', 'Bravo Hose' => '270.00'], $this->topProducts());

        $totals = ProductSales::totals($figures);
        $this->assertSame('720.00', $totals['net']);
        $this->assertSame((string) Sale::query()->sum('total_amount'), $totals['net'], 'net product sales sum to the Sale totals');
        $this->assertSame($this->revenueHeadline(), $totals['revenue'], 'and net revenue to the Revenue headline');

        $page = $this->productPerformance();
        $this->assertSame(['Gross Product Sales' => '800.00', 'Allocated Discounts' => '80.00', 'Net Product Sales' => '720.00'],
            collect($page->viewData('metrics'))->mapWithKeys(fn (array $metric): array => [$metric['label'] => $metric['value']])->all());
        $page->assertSee('Allocated discount ₦50.00')->assertSee('Net ₦450.00')->assertSee('They are not money collected');
    }

    public function test_partial_and_full_returns_of_a_discounted_sale_net_off_exactly(): void
    {
        $partial = $this->discount($this->sale([[$this->alpha, '2']]), '100.00');
        $this->return($partial, $partial->items()->sole(), '1');
        $this->assertSame('450.00', $this->figures()[$this->alpha->id]['revenue'], 'half of ₦900 came back');

        // Returned in full, a discounted Sale contributes nothing at all.
        $full = $this->discount($this->sale([[$this->bravo, '2']]), '60.00');
        $this->return($full, $full->items()->sole(), '2');
        $this->assertSame('0.00', $this->figures()[$this->bravo->id]['revenue']);
        $this->assertSame(['Alpha Pump' => '450.00'], $this->topProducts());
        $this->assertSame($this->revenueHeadline(), ProductSales::totals($this->figures())['revenue']);
    }

    public function test_voided_sales_are_excluded_and_corrected_sales_count_once(): void
    {
        $voided = $this->sale([[$this->bravo, '3']]);
        app(VoidSale::class)->execute($this->admin, $voided->fresh(), 'Recorded twice by mistake.');

        $corrected = $this->sale([[$this->alpha, '3']]);
        app(CorrectSale::class)->execute($this->admin, $corrected->fresh(), [
            'reason' => 'Recorded three by mistake.', 'products' => [['product_id' => $this->alpha->id, 'quantity' => '2']],
        ]);

        $figures = $this->figures();
        $this->assertArrayNotHasKey($this->bravo->id, $figures);
        $this->assertSame('1000.00', $figures[$this->alpha->id]['gross'], 'the superseded line is history, not a second sale');

        $row = collect($this->productPerformance()->viewData('rows')->items())->sole();
        $this->assertSame('2.000', (string) $row->quantity_sold);
        $this->assertSame('1000.00', $row->net_sales_value);
    }

    public function test_rounding_leftovers_land_deterministically_and_totals_stay_exact(): void
    {
        $charlie = Product::factory()->create(['name' => 'Charlie Seal', 'selling_price' => '100.00', 'current_stock' => '10.000']);
        $delta = Product::factory()->create(['name' => 'Delta Clamp', 'selling_price' => '100.00', 'current_stock' => '10.000']);
        $echo = Product::factory()->create(['name' => 'Echo Ring', 'selling_price' => '100.00', 'current_stock' => '10.000']);
        $this->discount($this->sale([[$charlie, '1'], [$delta, '1'], [$echo, '1']]), '0.02');

        $figures = $this->figures();
        $this->assertSame('0.02', ProductSales::totals($figures)['discount']);
        $this->assertSame('299.98', ProductSales::totals($figures)['net']);
        $this->assertSame(['0.01', '0.01', '0.00'], [$figures[$charlie->id]['discount'], $figures[$delta->id]['discount'], $figures[$echo->id]['discount']]);
    }

    public function test_the_trading_period_and_the_business_bound_every_figure(): void
    {
        $this->sale([[$this->alpha, '1']], '500.00', CarbonImmutable::now(config('business.timezone'))->subDays(40)->toDateString());
        $this->sale([[$this->bravo, '1']], '300.00');

        $other = Business::factory()->create();
        app(CurrentBusiness::class)->run($other, function () use ($other): void {
            $seller = User::factory()->forBusiness($other)->create(['role' => UserRole::Admin]);
            $product = Product::factory()->forBusiness($other)->create(['selling_price' => '9999.00', 'current_stock' => '5.000']);
            app(CreateSale::class)->execute($seller, [
                'is_walk_in' => false, 'customer_id' => Customer::factory()->forBusiness($other)->create(['is_active' => true])->id,
                'sale_date' => $this->today(), 'products' => [['product_id' => $product->id, 'quantity' => '1']],
                'payment_method' => 'cash', 'amount_paid' => '0.00',
            ]);
        });

        $this->assertSame([$this->bravo->id], array_keys($this->figures()), 'only this period, only this business');
        $this->assertSame(['Bravo Hose' => '300.00'], $this->topProducts());
    }

    public function test_net_product_sales_are_not_cash_and_reading_them_writes_nothing(): void
    {
        $this->discount($this->sale([[$this->alpha, '2']], '0.00'), '100.00');
        $before = DB::table('sales')->get()->toJson().DB::table('sale_payments')->get()->toJson().DB::table('sale_returns')->get()->toJson().DB::table('audit_logs')->count();

        $this->assertSame('900.00', ProductSales::totals($this->figures())['net']);
        $this->assertSame('0.00', bcadd((string) DB::table('sale_payments')->sum('amount'), '0', 2), 'nothing was collected');
        $this->productPerformance();
        $this->topProducts();

        $this->assertSame($before, DB::table('sales')->get()->toJson().DB::table('sale_payments')->get()->toJson().DB::table('sale_returns')->get()->toJson().DB::table('audit_logs')->count());
    }
}
