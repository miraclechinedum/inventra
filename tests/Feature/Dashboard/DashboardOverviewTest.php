<?php

namespace Tests\Feature\Dashboard;

use App\Dashboard\DashboardOverview;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The headline dashboard.
 *
 * The rule these tests exist to hold is that nothing on this screen is invented. Every figure is an
 * aggregate over real rows, keyed off `sale_date` so the dashboard and the Sales Report cannot
 * disagree, and where a truthful figure is unavailable — a trend with no comparison period, a
 * follow-up concept the domain does not have — the screen says nothing rather than something
 * plausible.
 */
class DashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'inventra_test',
            DB::connection()->getDatabaseName(),
            'Refusing to run: these tests write, and this is not the test database.'
        );

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('business.timezone'));
    }

    private function overview(?User $user = null): array
    {
        return app(DashboardOverview::class)->for($user ?? $this->admin);
    }

    private function page(?User $user = null): string
    {
        return $this->actingAs($user ?? $this->admin)->get(route('dashboard'))->assertOk()->getContent();
    }

    /** A completed sale on a given trading day. */
    private function sale(string $total, ?string $date = null, array $attributes = []): Sale
    {
        $sale = Sale::factory()->create(array_merge([
            'status' => SaleStatus::Completed,
            'sale_date' => $date ?? $this->today()->toDateString(),
            // The schema enforces total = subtotal - discount, so both are set together.
            'subtotal' => $total,
            'discount_amount' => '0.00',
            'total_amount' => $total,
            'amount_paid' => $total,
            'balance_due' => '0.00',
            'payment_status' => PaymentStatus::Paid,
            'sold_by' => $this->admin->id,
        ], $attributes));

        $this->addItem($sale);

        return $sale;
    }

    /** One sale line. Inserted directly: there is no SaleItem factory, and the dashboard only
     *  counts these rows, so the snapshots need to be present rather than meaningful. */
    private function addItem(Sale $sale): void
    {
        $product = Product::factory()->create();

        DB::table('sale_items')->insert([
            'business_id' => $sale->business_id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_sku_snapshot' => $product->sku,
            'product_name_snapshot' => $product->name,
            'unit_snapshot' => $product->unit,
            'quantity' => '1.000',
            'unit_price' => '1000.00',
            'line_total' => '1000.00',
            'created_at' => now(),
        ]);
    }

    // ── Access ──────────────────────────────────────────────────────────────────────────────────

    public function test_a_guest_is_redirected_and_every_role_can_load_the_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));

        foreach ([UserRole::Admin, UserRole::Manager, UserRole::SalesRep] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('dashboard'))->assertOk();
            auth()->logout();
        }
    }

    // ── KPI: inventory value ────────────────────────────────────────────────────────────────────

    /**
     * Inventory value is cost × stock on hand.
     *
     * Asserted against a figure computed by hand rather than by repeating the query: valuing stock
     * at selling price instead would book unrealised margin as an asset, and this is the test that
     * would catch such a change.
     */
    public function test_inventory_value_is_cost_price_times_stock_on_hand(): void
    {
        Product::factory()->create(['cost_price' => '1000.00', 'current_stock' => '3.000', 'selling_price' => '9999.00']);
        Product::factory()->create(['cost_price' => '250.50', 'current_stock' => '4.000', 'selling_price' => '9999.00']);

        // (1000 × 3) + (250.50 × 4) = 4002.00
        $this->assertSame('4002.00', $this->overview()['kpis']['inventoryValue']);
    }

    public function test_inactive_and_deleted_products_are_excluded_from_the_valuation(): void
    {
        Product::factory()->create(['cost_price' => '100.00', 'current_stock' => '2.000']);
        Product::factory()->create(['cost_price' => '500.00', 'current_stock' => '9.000', 'is_active' => false]);
        Product::factory()->create(['cost_price' => '700.00', 'current_stock' => '9.000'])->delete();

        $this->assertSame('200.00', $this->overview()['kpis']['inventoryValue']);
    }

    public function test_an_empty_inventory_values_at_zero_rather_than_failing(): void
    {
        $overview = $this->overview();

        $this->assertSame('0.00', $overview['kpis']['inventoryValue']);
        $this->assertTrue($overview['kpis']['inventoryIsEmpty']);
    }

    // ── KPI: sales today ────────────────────────────────────────────────────────────────────────

    /** Sales Today keys off `sale_date`, the trading day — never `created_at`. */
    public function test_sales_today_uses_the_trading_day_not_the_creation_timestamp(): void
    {
        // Keyed in today, but traded yesterday: it must not count toward today.
        $this->sale('5000.00', $this->today()->subDay()->toDateString());
        $this->sale('7500.00', $this->today()->toDateString());

        $this->assertSame('7500.00', $this->overview()['kpis']['salesToday']);
    }

    public function test_a_voided_sale_is_excluded_from_sales_today(): void
    {
        $this->sale('4000.00');
        $this->sale('9000.00', null, ['status' => SaleStatus::Voided]);

        $this->assertSame('4000.00', $this->overview()['kpis']['salesToday']);
    }

    // ── KPI: low stock and outstanding ──────────────────────────────────────────────────────────

    public function test_the_low_stock_count_uses_the_shared_reorder_definition(): void
    {
        Product::factory()->create(['current_stock' => '2.000', 'reorder_level' => '5.000']);
        Product::factory()->create(['current_stock' => '5.000', 'reorder_level' => '5.000']);
        Product::factory()->create(['current_stock' => '40.000', 'reorder_level' => '5.000']);
        Product::factory()->create(['current_stock' => '1.000', 'reorder_level' => '5.000', 'is_active' => false]);

        // At-or-below counts; healthy and inactive do not.
        $this->assertSame(2, $this->overview()['kpis']['lowStockCount']);
    }

    /**
     * The fourth KPI reports outstanding sales, a real figure.
     *
     * Inventra has no "pending follow-ups" concept, so the card is not allowed to show one. This
     * asserts both halves: the real number is right, and the invented label is absent.
     */
    public function test_the_fourth_kpi_reports_real_outstanding_sales_not_invented_follow_ups(): void
    {
        $this->sale('5000.00', null, ['amount_paid' => '3000.00', 'balance_due' => '2000.00', 'payment_status' => PaymentStatus::Partial]);
        $this->sale('8000.00', null, ['amount_paid' => '0.00', 'balance_due' => '8000.00', 'payment_status' => PaymentStatus::Unpaid]);
        $this->sale('1000.00');

        $this->assertSame(2, $this->overview()['kpis']['outstandingCount']);

        $html = $this->page();
        $this->assertStringContainsString('Outstanding Sales', $html);
        $this->assertStringNotContainsString('Pending Follow-Ups', $html);
    }

    // ── Trend ───────────────────────────────────────────────────────────────────────────────────

    /**
     * No comparison period, no percentage.
     *
     * "Up 100% from nothing" is meaningless, and dividing by zero would crash. Null here is what
     * makes the view render no trend at all rather than inventing one.
     */
    public function test_no_trend_is_claimed_when_the_previous_period_had_no_sales(): void
    {
        $this->sale('5000.00');

        $this->assertNull($this->overview()['kpis']['salesTodayTrend']);
        // And nothing trend-shaped reaches the page.
        $this->assertStringNotContainsString('%</span>', $this->page());
    }

    public function test_a_real_trend_is_calculated_from_both_periods(): void
    {
        $this->sale('1000.00', $this->today()->subDay()->toDateString());
        $this->sale('1500.00', $this->today()->toDateString());

        $trend = $this->overview()['kpis']['salesTodayTrend'];

        $this->assertSame('up', $trend['direction']);
        $this->assertSame('50.0', $trend['percent']);
    }

    public function test_a_fall_is_reported_as_a_positive_number_in_the_down_direction(): void
    {
        $this->sale('1000.00', $this->today()->subDay()->toDateString());
        $this->sale('400.00', $this->today()->toDateString());

        $trend = $this->overview()['kpis']['salesTodayTrend'];

        $this->assertSame('down', $trend['direction']);
        $this->assertSame('60.0', $trend['percent'], 'the sign is carried by the direction, not the number');
    }

    // ── Revenue chart ───────────────────────────────────────────────────────────────────────────

    /** The month is split into the weeks it actually contains, not forced into four. */
    public function test_the_month_is_split_into_the_weeks_it_actually_contains(): void
    {
        $bars = $this->overview()['revenue']['bars'];
        $expected = (int) ceil($this->today()->endOfMonth()->day / 7);

        $this->assertCount($expected, $bars);
        $this->assertSame('Wk 1', $bars[0]['label']);
        // Exactly one week contains today.
        $this->assertCount(1, array_filter($bars, fn (array $bar): bool => $bar['current']));
    }

    public function test_revenue_is_bucketed_into_the_week_its_trading_day_falls_in(): void
    {
        $start = $this->today()->startOfMonth();
        $this->sale('1000.00', $start->toDateString());
        $this->sale('2500.00', $start->addDays(8)->toDateString());

        $bars = $this->overview()['revenue']['bars'];

        $this->assertSame('1000.00', $bars[0]['value'], 'the 1st belongs to week one');
        $this->assertSame('2500.00', $bars[1]['value'], 'the 9th belongs to week two');
        $this->assertSame('3500.00', $this->overview()['revenue']['total']);
    }

    public function test_a_voided_sale_never_reaches_the_chart(): void
    {
        $this->sale('1000.00', $this->today()->startOfMonth()->toDateString());
        $this->sale('9000.00', $this->today()->startOfMonth()->toDateString(), ['status' => SaleStatus::Voided]);

        $this->assertSame('1000.00', $this->overview()['revenue']['total']);
    }

    /** The y-axis ceiling is never zero, or every bar height would divide by it. */
    public function test_the_chart_ceiling_is_positive_even_with_no_revenue(): void
    {
        $this->assertTrue(bccomp($this->overview()['revenue']['ceiling'], '0.00', 2) > 0);
    }

    // ── States ──────────────────────────────────────────────────────────────────────────────────

    public function test_the_empty_business_state_offers_the_real_record_sale_route(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('No sales yet', $html);
        $this->assertStringContainsString('Record your first sale to see your dashboard come to life.', $html);
        $this->assertStringContainsString('Your inventory is empty', $html);
        $this->assertStringContainsString('Add your first product to start tracking stock.', $html);
        $this->assertStringContainsString('No sales recorded yet', $html);
        $this->assertStringContainsString(route('sales.create'), $html);
        $this->assertStringContainsString(route('inventory.products.create'), $html);
    }

    public function test_inventory_with_nothing_low_reports_all_good(): void
    {
        Product::factory()->create(['current_stock' => '40.000', 'reorder_level' => '5.000']);

        $html = $this->page();

        $this->assertStringContainsString('All good', $html);
        $this->assertStringContainsString('All products are above their reorder levels.', $html);
        $this->assertStringNotContainsString('Your inventory is empty', $html);
    }

    /** The preview is bounded, but the count beside it is the real total. */
    public function test_the_low_stock_panel_previews_a_few_rows_but_counts_them_all(): void
    {
        foreach (range(1, 7) as $index) {
            Product::factory()->create([
                'name' => 'Low Product '.$index,
                'current_stock' => '1.000', 'reorder_level' => '9.000',
            ]);
        }

        $overview = $this->overview();
        $this->assertSame(7, $overview['lowStock']['count']);
        $this->assertCount(DashboardOverview::LOW_STOCK_PREVIEW, $overview['lowStock']['products']);

        $html = $this->page();
        $this->assertStringContainsString('Showing 7 low-stock products.', $html);
        // "View all" reaches the inventory list's real low-stock filter.
        $this->assertStringContainsString(route('inventory.index', ['stock' => 'low']), $html);
    }

    // ── Recent sales ────────────────────────────────────────────────────────────────────────────

    public function test_recent_sales_render_real_customers_items_amounts_and_statuses(): void
    {
        $customer = Customer::factory()->create(['first_name' => 'Ada', 'last_name' => 'Obi']);
        $sale = $this->sale('42000.00', null, [
            'customer_id' => $customer->id, 'is_walk_in' => false,
            'customer_name_snapshot' => 'Ada Obi',
        ]);
        $this->addItem($sale);

        $html = $this->page();

        $this->assertStringContainsString('Ada Obi', $html);
        $this->assertStringContainsString('AO', $html);
        $this->assertStringContainsString('2 items', $html);
        $this->assertStringContainsString('42,000.00', $html);
        $this->assertStringContainsString('Paid', $html);
    }

    public function test_a_walk_in_sale_is_labelled_rather_than_given_a_fake_customer(): void
    {
        $this->sale('9800.00', null, [
            'customer_id' => null, 'is_walk_in' => true,
            // The column is NOT NULL; the view keys the label off `is_walk_in`, not this string.
            'customer_name_snapshot' => 'Walk-in',
        ]);

        $this->assertStringContainsString('Walk-in customer', $this->page());
    }

    public function test_a_single_item_sale_reads_in_the_singular(): void
    {
        $this->sale('1000.00');

        $this->assertStringContainsString('1 item', $this->page());
    }

    /**
     * The card shows at most the latest five, newest first.
     *
     * Six sales are created on six distinct trading days, so "latest" is unambiguous: the oldest
     * must be absent entirely, not merely pushed down the list.
     */
    public function test_exactly_the_latest_five_sales_are_rendered_newest_first(): void
    {
        $numbers = [];
        foreach (range(1, 6) as $index) {
            // Day 1 is the oldest; day 6 the newest.
            $sale = $this->sale('1000.00', $this->today()->subDays(6 - $index)->toDateString());
            $numbers[$index] = $sale->sale_number;
        }

        $html = $this->page();

        // Five rows, no more.
        $this->assertSame(5, substr_count($html, '<td class="dash-sn">'));

        // The oldest is excluded outright.
        $this->assertStringNotContainsString(route('sales.show', Sale::query()->where('sale_number', $numbers[1])->sole()), $html);

        // And the remaining five appear newest first.
        $positions = [];
        foreach ([6, 5, 4, 3, 2] as $index) {
            $url = route('sales.show', Sale::query()->where('sale_number', $numbers[$index])->sole());
            $positions[] = strpos($html, $url);
            $this->assertNotFalse(end($positions), "sale {$index} must be rendered");
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'rows must run newest to oldest');
    }

    /** S/N numbers the displayed position, and never leaks a primary key. */
    public function test_the_serial_column_numbers_the_displayed_rows(): void
    {
        foreach (range(1, 6) as $index) {
            $this->sale('1000.00', $this->today()->subDays(6 - $index)->toDateString());
        }

        $html = $this->page();

        $this->assertStringContainsString('<th class="dash-sn">S/N</th>', $html);

        preg_match_all('/<td class="dash-sn">(\d+)<\/td>/', $html, $serials);
        $this->assertSame(['1', '2', '3', '4', '5'], $serials[1]);

        // The newest sale carries S/N 1 — the serial is a position, not an id.
        $newest = Sale::query()->latest('sale_date')->latest('id')->first();
        $this->assertGreaterThan(5, $newest->id, 'this fixture must not let an id coincide with a serial');
        $this->assertStringNotContainsString('<td class="dash-sn">'.$newest->id.'</td>', $html);
    }

    /** Fewer than five sales renders only what exists. */
    public function test_fewer_than_five_sales_render_only_the_records_that_exist(): void
    {
        $this->sale('1000.00', $this->today()->subDay()->toDateString());
        $this->sale('2000.00', $this->today()->toDateString());

        $html = $this->page();

        preg_match_all('/<td class="dash-sn">(\d+)<\/td>/', $html, $serials);
        $this->assertSame(['1', '2'], $serials[1]);
    }

    /** The card links to the full list and never paginates itself. */
    public function test_view_all_points_at_the_sales_page_and_the_card_has_no_pagination(): void
    {
        foreach (range(1, 6) as $index) {
            $this->sale('1000.00', $this->today()->subDays($index)->toDateString());
        }

        $html = $this->page();

        $this->assertStringContainsString(route('sales.index'), $html);
        $this->assertStringContainsString('View all', $html);
        // No paginator reaches the dashboard card.
        $this->assertStringNotContainsString('Showing 1', $html);
        $this->assertStringNotContainsString('Rows per page', $html);
    }

    /** Sales actions address the sale by its route binding, never by a bare numeric id. */
    public function test_row_actions_use_the_public_sale_identifier(): void
    {
        $sale = $this->sale('1000.00');

        $html = $this->page();

        $this->assertStringContainsString(route('sales.show', $sale), $html);
        // The numeric key is not the address.
        $this->assertStringNotContainsString('/sales/'.$sale->id.'"', $html);
    }

    public function test_recent_sales_are_bounded_and_exclude_voided_sales(): void
    {
        foreach (range(1, 6) as $index) {
            $this->sale('1000.00', $this->today()->subDays($index)->toDateString());
        }
        $this->sale('9999.00', null, ['status' => SaleStatus::Voided]);

        $recent = $this->overview()['recentSales'];

        $this->assertCount(DashboardOverview::RECENT_SALES_LIMIT, $recent);
        $this->assertEmpty($recent->where('status', SaleStatus::Voided));
    }

    // ── Role scoping ────────────────────────────────────────────────────────────────────────────

    /** A Sales Representative sees only their own trade, and no low-stock panel. */
    public function test_a_sales_representative_sees_only_their_own_sales(): void
    {
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $this->sale('50000.00', null, ['sold_by' => $this->admin->id]);
        $this->sale('7000.00', null, ['sold_by' => $rep->id]);

        $overview = $this->overview($rep);

        $this->assertSame('7000.00', $overview['kpis']['salesToday']);
        $this->assertNull($overview['lowStock'], 'the low-stock panel is management-only');
        $this->assertCount(1, $overview['recentSales']);
    }

    /** The notification bell and its preview are not offered to a Sales Representative. */
    public function test_a_sales_representative_gets_no_notification_dropdown(): void
    {
        $html = $this->page(User::factory()->create(['role' => UserRole::SalesRep]));

        $this->assertStringNotContainsString('topbar-notification-panel', $html);
    }

    // ── Honesty ─────────────────────────────────────────────────────────────────────────────────

    /**
     * None of the Figma's sample values may appear unless the database produced them.
     *
     * The screenshots are a design reference, not a fixture: a hard-coded "₦4.82M" or "Emeka Obi"
     * would look correct in a screenshot and be a lie in production.
     */
    public function test_no_figma_sample_value_is_hard_coded(): void
    {
        $html = $this->page();

        foreach ([
            '4.82M', '186,400', '3.94M', '6.4%', '12%', 'Emeka Obi', 'Ngozi Eze',
            'Tunde Bakare', 'Camry oil filter', 'Brake pads', 'Wiper blade', 'Battery 12V',
            'John Doe', '10293',
        ] as $sample) {
            $this->assertStringNotContainsString($sample, $html, "{$sample} must come from data, never the markup");
        }
    }

    /** Hostile snapshot text is escaped, not executed. */
    public function test_hostile_customer_names_are_escaped(): void
    {
        $sale = $this->sale('1000.00');
        // Written after creation: the factory derives this snapshot from the customer it makes, so
        // passing it as an attribute is silently overwritten.
        DB::table('sales')->where('id', $sale->id)
            ->update(['customer_name_snapshot' => '<script>alert(1)</script>', 'is_walk_in' => false]);

        $html = $this->page();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('alert(1)', $html, 'the text is still shown, just inert');
        $this->assertStringContainsString('&lt;', $html);
    }

    // ── Performance ─────────────────────────────────────────────────────────────────────────────

    /**
     * The dashboard's cost does not grow with the data.
     *
     * In particular the recent-sales item counts come from a single `withCount`, not one query per
     * row — the classic N+1 this table would otherwise introduce.
     */
    public function test_the_query_count_stays_bounded_as_data_grows(): void
    {
        Product::factory()->count(10)->create(['current_stock' => '1.000', 'reorder_level' => '9.000']);
        foreach (range(1, 12) as $index) {
            $this->sale('1000.00', $this->today()->subDays($index % 20)->toDateString());
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $this->page();
        DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

        $this->assertLessThan(30, $queries, "The dashboard ran {$queries} queries; it must stay bounded.");
    }

    public function test_loading_the_dashboard_writes_nothing(): void
    {
        $this->sale('1000.00');
        $before = DB::table('sales')->get()->toJson();

        $this->page();

        $this->assertSame($before, DB::table('sales')->get()->toJson());
    }
}
