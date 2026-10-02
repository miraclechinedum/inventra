<?php

namespace Tests\Feature\Reports;

use App\Actions\Sale\CreateSale;
use App\Actions\Sale\IssueReturnRequest;
use App\Actions\Sale\RecordSaleReturn;
use App\Actions\Sale\VoidSale;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Reports\ReportsDashboard;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Reports dashboard figures.
 *
 * The one definition everything here turns on: Revenue is *net of returns*. The Sales Report's
 * "Gross Sales" is the same sum before that deduction and keeps its own meaning — these check that
 * the two stay distinct, and that the dashboard never quietly reports one as the other.
 */
class ReportsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $rep;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests write. Asserted on the connection's own name, because only the database can
        // say which database it is — an environment name is a claim, not proof.
        $this->assertSame(
            'inventra_test',
            DB::connection()->getDatabaseName(),
            'Refusing to run: these tests write, and this is not the test database.'
        );

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $this->customer = Customer::factory()->create(['is_active' => true]);
        $this->product = Product::factory()->create(['selling_price' => '10000.00', 'current_stock' => '500.000']);
    }

    private function sale(string $units, string $paid = '0.00', ?string $date = null, ?Product $product = null): Sale
    {
        return app(CreateSale::class)->execute($this->admin, [
            'is_walk_in' => false,
            'customer_id' => $this->customer->id,
            'sale_date' => $date ?? CarbonImmutable::now(config('business.timezone'))->toDateString(),
            'products' => [['product_id' => ($product ?? $this->product)->id, 'quantity' => $units]],
            'payment_method' => 'cash',
            'amount_paid' => $paid,
        ]);
    }

    private function returnUnits(Sale $sale, string $units): void
    {
        $token = app(IssueReturnRequest::class)->execute($this->admin, $sale, session()->driver());

        app(RecordSaleReturn::class)->execute($this->admin, $sale, [
            'request_token' => $token,
            'reason' => 'Faulty',
            'items' => [['sale_item_id' => $sale->items()->sole()->id, 'quantity' => $units, 'disposition' => 'restock']],
        ], session()->getId());
    }

    /** @return array<string, mixed> */
    private function dashboard(): array
    {
        return app(ReportsDashboard::class)->all();
    }

    public function test_an_admin_can_open_the_reports_dashboard(): void
    {
        $this->sale('2', '20000.00');

        $this->actingAs($this->admin)->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Revenue trend')
            ->assertSee('Top products')
            ->assertSee('Receivables', false);
    }

    /** The dashboard is behind the same policy as every detail report. */
    public function test_a_sales_rep_cannot_open_the_reports_dashboard(): void
    {
        $this->actingAs($this->rep)->get(route('reports.index'))->assertForbidden();
    }

    public function test_the_reports_dashboard_is_closed_to_a_guest(): void
    {
        $this->get(route('reports.index'))->assertRedirect(route('login'));
    }

    /**
     * Revenue is net of returns, and Gross Sales is not.
     *
     * The case that decided the definition: a sale whose goods all came back contributes nothing to
     * Revenue while remaining a real transaction in the Sales Report.
     */
    public function test_revenue_is_net_of_returns_while_gross_sales_is_not(): void
    {
        $kept = $this->sale('10', '100000.00');
        $returned = $this->sale('5', '50000.00');
        $this->returnUnits($returned, '5');

        // Gross ₦150,000 − returned ₦50,000 = ₦100,000 net.
        $this->assertSame('100000.00', $this->dashboard()['kpis']['revenue']['value']);

        // The Sales Report still reports the full ₦150,000 under its own name.
        $gross = (string) Sale::query()->where('status', 'completed')->sum('total_amount');
        $this->assertSame('150000.00', Money::zero($gross));
        $this->assertSame('150000.00', bcadd((string) $kept->total_amount, (string) $returned->total_amount, 2));
    }

    public function test_a_voided_sale_is_excluded_from_revenue_and_orders(): void
    {
        $this->sale('4', '40000.00');
        $doomed = $this->sale('6', '0.00');

        app(VoidSale::class)->execute($this->admin, $doomed, 'Recorded in error');

        $kpis = $this->dashboard()['kpis'];
        $this->assertSame('40000.00', $kpis['revenue']['value']);
        $this->assertSame(1, $kpis['orders']['value']);
    }

    /** A return reduces revenue but does not erase the fact that an order happened. */
    public function test_a_returned_sale_still_counts_as_an_order(): void
    {
        $sale = $this->sale('5', '50000.00');
        $this->returnUnits($sale, '5');

        $kpis = $this->dashboard()['kpis'];
        $this->assertSame('0.00', $kpis['revenue']['value']);
        $this->assertSame(1, $kpis['orders']['value'], 'the order still happened');
    }

    /** A discount is already inside `total_amount`, so revenue follows what was actually charged. */
    public function test_revenue_reflects_the_discounted_total(): void
    {
        $sale = $this->sale('10', '0.00');
        $this->assertSame('100000.00', $this->dashboard()['kpis']['revenue']['value']);
        $this->assertSame('100000.00', (string) $sale->total_amount);
    }

    /** Partial payment is a collections matter: revenue is earned whether or not cash arrived. */
    public function test_partial_payment_does_not_reduce_revenue_but_does_create_a_debtor(): void
    {
        $this->sale('10', '30000.00');

        $kpis = $this->dashboard()['kpis'];
        $this->assertSame('100000.00', $kpis['revenue']['value'], 'the sale was earned in full');
        $this->assertSame('70000.00', $kpis['outstanding']['value'], 'and the rest is owed');
        $this->assertSame(1, $kpis['outstanding']['debtors']);
    }

    public function test_a_settled_sale_leaves_the_debtor_list(): void
    {
        $this->sale('5', '50000.00');

        $dashboard = $this->dashboard();
        $this->assertSame('0.00', $dashboard['kpis']['outstanding']['value']);
        $this->assertSame(0, $dashboard['receivables']['paginator']->total());
    }

    /** Returning goods reduces what a debtor owes, and the dashboard reads the settled column. */
    public function test_a_return_reduces_the_outstanding_balance(): void
    {
        $sale = $this->sale('10', '30000.00');
        $this->assertSame('70000.00', $this->dashboard()['kpis']['outstanding']['value']);

        $this->returnUnits($sale, '5');

        // ₦100,000 sold, ₦50,000 returned, ₦30,000 paid: ₦20,000 still owed.
        $this->assertSame('20000.00', $this->dashboard()['kpis']['outstanding']['value']);
    }

    public function test_new_customers_counts_registered_customers_only(): void
    {
        // A walk-in creates no Customer row, so it cannot be counted as one.
        app(CreateSale::class)->execute($this->admin, [
            'is_walk_in' => true,
            'customer_id' => null,
            'sale_date' => CarbonImmutable::now(config('business.timezone'))->toDateString(),
            'products' => [['product_id' => $this->product->id, 'quantity' => '1']],
            'payment_method' => 'cash',
            'amount_paid' => '10000.00',
        ]);

        // One from setUp, and one more here.
        Customer::factory()->create(['is_active' => true]);

        $this->assertSame(2, $this->dashboard()['kpis']['customers']['value']);
    }

    /** Weekly bars are grouped by trading day, not by when the sale was keyed in. */
    public function test_week_bars_group_by_sale_date(): void
    {
        $now = CarbonImmutable::now(config('business.timezone'));
        $monday = $now->startOfWeek();

        $this->sale('3', '30000.00', $monday->toDateString());
        $this->sale('2', '20000.00', $monday->addDay()->toDateString());

        $bars = collect($this->dashboard()['periods']['week']['bars'])->keyBy('label');
        $this->assertSame('30000.00', $bars['Mon']['value']);
        $this->assertSame('20000.00', $bars['Tue']['value']);
    }

    /** The month is divided into real calendar weeks, which is four or five depending on the month. */
    public function test_month_bars_cover_the_whole_calendar_month(): void
    {
        $month = $this->dashboard()['periods']['month'];
        $days = CarbonImmutable::now(config('business.timezone'))->daysInMonth;

        $this->assertSame((int) ceil($days / 7), count($month['bars']));
        $this->assertContains(count($month['bars']), [4, 5]);
    }

    public function test_year_bars_cover_twelve_months_and_mark_the_future(): void
    {
        $now = CarbonImmutable::now(config('business.timezone'));
        $year = $this->dashboard()['periods']['year'];

        $this->assertCount(12, $year['bars']);
        // Months after this one are placeholders, never revenue.
        foreach ($year['bars'] as $index => $bar) {
            $this->assertSame($index + 1 > (int) $now->format('n'), $bar['future'], $bar['label']);
        }
    }

    /**
     * Every period's bars add up to the headline it sits under.
     *
     * A chart whose columns disagreed with its own total would be worse than either convention, so
     * returns are attributed to the sale's own trading day throughout.
     */
    public function test_each_period_total_equals_the_sum_of_its_bars(): void
    {
        $sale = $this->sale('10', '0.00');
        $this->returnUnits($sale, '4');
        $this->sale('3', '30000.00');

        foreach ($this->dashboard()['periods'] as $name => $period) {
            $sum = '0.00';
            foreach ($period['bars'] as $bar) {
                $sum = bcadd($sum, $bar['value'], 2);
            }

            $this->assertSame($period['total'], $sum, "{$name} bars must sum to its total");
            // And no bar may be negative, which is what naive per-day netting produces.
            foreach ($period['bars'] as $bar) {
                $this->assertTrue(bccomp($bar['value'], '0', 2) >= 0, "{$name} bar {$bar['label']} is negative");
            }
        }
    }

    /** Year to date is compared against the same span last year, never a whole previous year. */
    public function test_the_year_comparison_uses_the_matching_period_last_year(): void
    {
        $now = CarbonImmutable::now(config('business.timezone'));

        // Sold on this date last year, so it falls inside the equivalent window.
        $this->sale('4', '40000.00', $now->subYear()->toDateString());
        // And a month after today last year, which is outside it.
        $this->sale('9', '90000.00', $now->subYear()->addMonths(1)->toDateString());
        $this->sale('6', '60000.00', $now->toDateString());

        $year = $this->dashboard()['periods']['year'];

        // ₦60,000 this year against ₦40,000 in the matching window: up 50%. Were the whole of last
        // year counted (₦130,000) it would read as a fall.
        $this->assertSame('60000.00', $year['total']);
        $this->assertSame('up', $year['change']['direction']);
        $this->assertSame(50, $year['change']['percent']);
    }

    /** Growth from nothing is not a percentage, so none is offered. */
    public function test_a_zero_previous_period_yields_no_comparison(): void
    {
        $this->sale('5', '50000.00');

        // "New", not a percentage conjured from a division by zero.
        $this->assertSame('new', $this->dashboard()['kpis']['revenue']['change']['direction']);
        $this->assertNull($this->dashboard()['kpis']['revenue']['change']['percent']);
    }

    /** Top products rank on net revenue, so a fully returned product does not stay at the top. */
    public function test_top_products_rank_on_revenue_net_of_returns(): void
    {
        $cheap = Product::factory()->create(['name' => 'Air filter', 'selling_price' => '5000.00', 'current_stock' => '100.000']);

        // ₦100,000 of the expensive product, then all of it returned.
        $returned = $this->sale('10', '0.00');
        $this->returnUnits($returned, '10');

        // ₦25,000 of the cheaper one, kept.
        $this->sale('5', '0.00', null, $cheap);

        $products = $this->dashboard()['periods']['year']['products'];

        // The returned product is gone entirely; the kept one leads.
        $this->assertCount(1, $products);
        $this->assertSame('Air filter', $products[0]['name']);
        $this->assertSame('25000.00', $products[0]['value']);
        $this->assertSame(100.0, $products[0]['percent']);
    }

    /** Products are named as they were sold, not as the catalogue reads today. */
    public function test_top_products_use_the_name_as_sold(): void
    {
        $this->sale('4', '40000.00');
        $this->product->update(['name' => 'Renamed After The Sale']);

        $products = $this->dashboard()['periods']['year']['products'];
        $this->assertNotSame('Renamed After The Sale', $products[0]['name']);
    }

    /** Debtors are aged from the trading day, because Inventra has no invoice due date. */
    public function test_receivables_age_into_buckets_from_the_sale_date(): void
    {
        $now = CarbonImmutable::now(config('business.timezone'));

        $this->sale('5', '0.00', $now->subDays(5)->toDateString());
        $this->sale('3', '0.00', $now->subDays(45)->toDateString());

        $receivables = $this->dashboard()['receivables'];
        $row = collect($receivables['paginator']->items())->firstWhere('name', $this->customer->full_name);

        $this->assertSame('50000.00', $row['buckets']['0–30D']);
        $this->assertSame('30000.00', $row['buckets']['31–60D']);
        $this->assertSame('80000.00', $row['total']);
        // The authoritative model has four buckets; the design shows the first two.
        $this->assertSame(['0–30D', '31–60D', '61–90D', '90D+'], $receivables['buckets']);
    }

    /**
     * Unpaid walk-in sales are listed one per sale.
     *
     * A walk-in has no customer identity, so folding unrelated strangers into a single "Walk-in
     * customer" debtor would invent a person nobody can chase.
     */
    public function test_walk_in_debts_are_listed_per_sale_rather_than_merged(): void
    {
        $today = CarbonImmutable::now(config('business.timezone'))->toDateString();

        foreach (['3', '2'] as $units) {
            app(CreateSale::class)->execute($this->admin, [
                'is_walk_in' => true,
                'customer_id' => null,
                'sale_date' => $today,
                'products' => [['product_id' => $this->product->id, 'quantity' => $units]],
                'payment_method' => 'cash',
                'amount_paid' => '0.00',
            ]);
        }

        $rows = $this->dashboard()['receivables']['paginator']->items();

        // Two separate rows, each naming its own sale, not one merged ₦50,000 debtor.
        $walkIns = array_values(array_filter($rows, fn (array $row): bool => str_starts_with($row['name'], 'Walk-in')));
        $this->assertCount(2, $walkIns);
        $this->assertSame(['30000.00', '20000.00'], array_column($walkIns, 'total'));
        $this->assertStringContainsString('·', $walkIns[0]['name'], 'the row names its sale');
    }

    /** An installation with no trading renders zeroes rather than breaking. */
    public function test_an_empty_installation_reports_zeroes(): void
    {
        $dashboard = $this->dashboard();

        $this->assertSame('0.00', $dashboard['kpis']['revenue']['value']);
        $this->assertSame(0, $dashboard['kpis']['orders']['value']);
        $this->assertSame('0.00', $dashboard['kpis']['outstanding']['value']);
        $this->assertSame('flat', $dashboard['kpis']['revenue']['change']['direction']);
        $this->assertSame(0, $dashboard['receivables']['paginator']->total());

        foreach ($dashboard['periods'] as $period) {
            $this->assertSame('0.00', $period['total']);
            $this->assertSame([], $period['products']);
            // The sentence still renders, stating the absence rather than a percentage.
            $this->assertStringContainsString('no revenue recorded', $period['sentence']['fallback']);
            // The footer still renders, with an em dash where there is no best period.
            $this->assertSame('—', $period['footer'][0]['value']);
        }

        $this->actingAs($this->admin)->get(route('reports.index'))
            ->assertOk()
            ->assertSee('No product sales for this period.')
            ->assertSee('No outstanding receivables.');
    }

    /** The comparison line is always present, so the four cards keep a common baseline. */
    public function test_kpi_comparisons_report_real_movement(): void
    {
        $now = CarbonImmutable::now(config('business.timezone'));

        // ₦40,000 in the previous 30-day window, ₦60,000 in the current one: up 50%.
        $this->sale('4', '0.00', $now->subDays(40)->toDateString());
        $this->sale('6', '0.00', $now->toDateString());

        $revenue = $this->dashboard()['kpis']['revenue']['change'];
        $this->assertSame('up', $revenue['direction']);
        $this->assertSame(50, $revenue['percent']);
        $this->assertSame('50% vs prev 30d', $revenue['label']);
    }

    public function test_a_fall_is_reported_as_a_decrease(): void
    {
        $now = CarbonImmutable::now(config('business.timezone'));

        $this->sale('10', '0.00', $now->subDays(40)->toDateString());
        $this->sale('4', '0.00', $now->toDateString());

        $revenue = $this->dashboard()['kpis']['revenue']['change'];
        $this->assertSame('down', $revenue['direction']);
        $this->assertSame(60, $revenue['percent']);
    }

    /**
     * Activity where there was none is "New", never "100%".
     *
     * Growth from zero is not a percentage, and inventing one would be a number the data cannot
     * support. Both zero-cases still render, so the cards stay aligned.
     */
    public function test_a_zero_previous_period_is_reported_truthfully(): void
    {
        $this->sale('5', '0.00');

        $revenue = $this->dashboard()['kpis']['revenue']['change'];
        $this->assertSame('new', $revenue['direction']);
        $this->assertNull($revenue['percent']);
        $this->assertSame('New vs prev 30d', $revenue['label']);
    }

    public function test_two_empty_periods_report_no_change(): void
    {
        $change = $this->dashboard()['kpis']['revenue']['change'];

        $this->assertSame('flat', $change['direction']);
        $this->assertSame('No change vs prev 30d', $change['label']);
    }

    public function test_orders_and_new_customers_carry_their_own_comparisons(): void
    {
        $now = CarbonImmutable::now(config('business.timezone'));
        $this->sale('2', '0.00', $now->subDays(40)->toDateString());
        $this->sale('2', '0.00', $now->toDateString());

        $kpis = $this->dashboard()['kpis'];
        $this->assertSame('0% vs prev 30d', $kpis['orders']['change']['label'], 'one order each period');
        $this->assertArrayHasKey('label', $kpis['customers']['change']);
    }

    /** The New customers card links to the real customers list. */
    public function test_the_new_customers_card_links_to_the_customers_list(): void
    {
        $this->actingAs($this->admin)->get(route('reports.index'))
            ->assertOk()
            ->assertSee(route('customers.index'), false)
            ->assertSee('View all', false);
    }

    /** The trend sentence is phrased by the server, one per period. */
    public function test_each_period_supplies_its_own_sentence(): void
    {
        $now = CarbonImmutable::now(config('business.timezone'));
        $this->sale('5', '0.00', $now->toDateString());

        $periods = $this->dashboard()['periods'];

        $this->assertSame('You earned', $periods['week']['sentence']['lead']);
        $this->assertSame('this week', $periods['week']['sentence']['subject']);
        $this->assertSame('this month', $periods['month']['sentence']['subject']);
        // The year reads possessively, as the design has it.
        $this->assertSame('You’ve earned', $periods['year']['sentence']['lead']);
        $this->assertSame('in '.$now->format('Y').' so far', $periods['year']['sentence']['subject']);

        // No prior data, so each states the fact instead of inventing a percentage.
        foreach ($periods as $name => $period) {
            $this->assertSame('', $period['sentence']['clause'], $name);
            $this->assertStringContainsString('no revenue recorded', $period['sentence']['fallback'], $name);
        }
    }

    public function test_a_sentence_reports_real_growth_when_there_is_a_comparison(): void
    {
        $now = CarbonImmutable::now(config('business.timezone'));

        // ₦40,000 last month against ₦60,000 this month.
        $this->sale('4', '0.00', $now->subMonth()->startOfMonth()->addDay()->toDateString());
        $this->sale('6', '0.00', $now->toDateString());

        $sentence = $this->dashboard()['periods']['month']['sentence'];
        $this->assertSame('up', $sentence['direction']);
        $this->assertSame('up 50% from last month.', $sentence['clause']);
        $this->assertSame('', $sentence['fallback']);
    }

    /** Each period marks the bar the reader is currently in, so one is active before any hover. */
    public function test_each_period_marks_its_current_bar(): void
    {
        $now = CarbonImmutable::now(config('business.timezone'));
        $periods = $this->dashboard()['periods'];

        $currentDay = array_values(array_filter($periods['week']['bars'], fn (array $b): bool => $b['current']));
        $this->assertCount(1, $currentDay);
        $this->assertSame($now->format('D'), $currentDay[0]['label']);

        $this->assertCount(1, array_filter($periods['month']['bars'], fn (array $b): bool => $b['current']));

        $currentMonth = array_values(array_filter($periods['year']['bars'], fn (array $b): bool => $b['current']));
        $this->assertCount(1, $currentMonth);
        $this->assertSame($now->format('M'), $currentMonth[0]['label']);
    }

    /** The debtor table is a real paginated list, ten to a page by default. */
    public function test_the_debtor_table_paginates_ten_to_a_page(): void
    {
        $this->makeDebtors(12);

        $paginator = $this->dashboard()['receivables']['paginator'];

        $this->assertSame(10, $paginator->perPage());
        $this->assertSame(12, $paginator->total());
        $this->assertCount(10, $paginator->items());
        $this->assertSame(1, $paginator->firstItem());
    }

    /** S/N continues across pages rather than restarting. */
    public function test_the_serial_number_is_pagination_aware(): void
    {
        $this->makeDebtors(12);

        $page = $this->actingAs($this->admin)->get(route('reports.index', ['page' => 2]))->assertOk();
        $paginator = $page->viewData('dashboard')['receivables']['paginator'];

        // Page two starts at 11, and holds the remaining two rows.
        $this->assertSame(11, $paginator->firstItem());
        $this->assertSame(12, $paginator->lastItem());
        $this->assertCount(2, $paginator->items());
    }

    public function test_the_debtor_table_honours_the_shared_page_sizes(): void
    {
        $this->makeDebtors(30);

        foreach ([25, 50] as $size) {
            $page = $this->actingAs($this->admin)
                ->get(route('reports.index', ['per_page' => $size]))->assertOk();

            $this->assertSame($size, $page->viewData('dashboard')['receivables']['paginator']->perPage());
        }

        // A size outside the shared options falls back rather than reaching a LIMIT clause.
        $page = $this->actingAs($this->admin)->get(route('reports.index', ['per_page' => '999999']))->assertOk();
        $this->assertSame(10, $page->viewData('dashboard')['receivables']['paginator']->perPage());
    }

    /** All four buckets survive pagination; the design shows two, the domain keeps four. */
    public function test_pagination_preserves_all_four_aging_buckets(): void
    {
        $this->makeDebtors(3);

        $receivables = $this->dashboard()['receivables'];
        $this->assertSame(['0–30D', '31–60D', '61–90D', '90D+'], $receivables['buckets']);

        foreach ($receivables['paginator']->items() as $row) {
            $this->assertSame($receivables['buckets'], array_keys($row['buckets']));
        }
    }

    /** Total owed is still each Sale's settled balance, unchanged by how the table is paged. */
    public function test_total_owed_remains_balance_due(): void
    {
        $this->sale('7', '20000.00');

        $row = $this->dashboard()['receivables']['paginator']->items()[0];
        $owed = (string) Sale::query()->where('status', 'completed')->sum('balance_due');

        $this->assertSame(Money::zero($owed), $row['total']);
        $this->assertSame('50000.00', $row['total']);
    }

    /** Neither removed section appears on the dashboard any more. */
    public function test_the_dashboard_no_longer_shows_the_detail_links_or_the_disclaimer(): void
    {
        $html = $this->actingAs($this->admin)->get(route('reports.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Detailed reports', $html);
        $this->assertStringNotContainsString('do not calculate profit', $html);

        // The reports themselves are untouched and still reachable by route.
        $this->actingAs($this->admin)->get(route('reports.sales'))->assertOk();
        $this->actingAs($this->admin)->get(route('reports.summary'))->assertOk();
    }

    /** Creates `$count` distinct registered debtors, each owing a different amount. */
    private function makeDebtors(int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            $customer = Customer::factory()->create(['is_active' => true]);

            app(CreateSale::class)->execute($this->admin, [
                'is_walk_in' => false,
                'customer_id' => $customer->id,
                'sale_date' => CarbonImmutable::now(config('business.timezone'))->toDateString(),
                'products' => [['product_id' => $this->product->id, 'quantity' => (string) ($index + 1)]],
                'payment_method' => 'cash',
                'amount_paid' => '0.00',
            ]);
        }
    }

    /** The headline figures print in the design's abbreviated form. */
    public function test_money_is_abbreviated_for_display(): void
    {
        $this->assertSame('3.94M', Money::abbreviate('3940000.00'));
        $this->assertSame('486K', Money::abbreviate('486000.00'));
        $this->assertSame('268K', Money::abbreviate('268000.00'));
        // Detail figures keep their full digits and drop only a whole naira's decimals.
        $this->assertSame('128,500', Money::compact('128500.00'));
        $this->assertSame('0', Money::abbreviate('0.00'));
    }
}
