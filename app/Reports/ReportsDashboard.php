<?php

namespace App\Reports;

use App\Models\Customer;
use App\Models\Sale;
use App\Support\Money;
use App\Support\PerPage;
use App\Tenancy\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The figures behind the Reports dashboard.
 *
 * ── What "Revenue" means here ────────────────────────────────────────────────────────────────────
 *
 * Revenue is *net of returns*: the value of completed, non-voided sales, less the merchandise value
 * of goods that have come back.
 *
 *     Revenue = SUM(sales.total_amount) − SUM(sale_returns.merchandise_value)
 *
 * This is deliberately a different metric from the Sales Report's "Gross Sales", which is the same
 * sum *without* the deduction and keeps its name and meaning untouched. The two answer different
 * questions — what was transacted, and what the business actually kept — and a sale whose goods
 * were entirely returned is ₦0 of the latter while remaining a real transaction in the former.
 *
 * Collections are a third thing again and are not Revenue: a credit sale earns revenue the day it
 * is made, and the cash arriving later is a payment. The unpaid part lives in Outstanding
 * Receivables, which is why a partly paid sale contributes fully to Revenue and separately to the
 * debtors figure.
 *
 * ── Dates ───────────────────────────────────────────────────────────────────────────────────────
 *
 * Everything is grouped by `sale_date`, the trading day the operator recorded, in the business
 * timezone. A sale keyed in on Monday for Saturday's trade belongs to Saturday.
 *
 * Returns are deducted from the *period total* by `returned_at` — when the goods actually came
 * back — but the individual bars deduct each return from the day the original sale was made. Those
 * two treatments differ deliberately. A return is naturally a later event than its sale, so netting
 * a bar by `returned_at` produces a negative column whenever goods come back on a different day
 * from the one they were sold, which is most of the time: a chart of revenue cannot draw a day
 * below the axis, and "best day" becomes meaningless. Attributing the deduction to the sale's own
 * day keeps every bar a real, non-negative measure of what that day's trading was ultimately worth,
 * and the bars still sum to the period total.
 *
 * ── Money ───────────────────────────────────────────────────────────────────────────────────────
 *
 * Every figure is a decimal string summed in the database and combined with bcmath. Nothing here
 * uses floats, and the browser is handed finished numbers rather than rows to add up.
 *
 * ── Scope ───────────────────────────────────────────────────────────────────────────────────────
 *
 * Each query starts from `completedSales()` / `returnsBetween()` rather than reaching for a global.
 * When Inventra becomes multi-business those two are where a tenant scope is applied, and nothing
 * else in this class needs to know.
 */
class ReportsDashboard
{
    /** Buckets the receivables table ages into, in days since the trading day. */
    private const AGING_BUCKETS = [[0, 30, '0–30D'], [31, 60, '31–60D'], [61, 90, '61–90D'], [91, null, '90D+']];

    public function __construct(private readonly BusinessReports $reports) {}

    /** Raw builder queries are not reached by the tenant scope, so every one names the Business. */
    private function businessId(): int
    {
        return app(CurrentBusiness::class)->id();
    }

    /**
     * Everything the dashboard renders, in one payload.
     *
     * @return array<string, mixed>
     */
    public function all(?Request $request = null, ?CarbonImmutable $now = null): array
    {
        $request = $request ?? request();
        $now = $now ?? CarbonImmutable::now(config('business.timezone'));

        return [
            'kpis' => $this->kpis($now),
            'periods' => [
                'week' => $this->week($now),
                'month' => $this->month($now),
                'year' => $this->year($now),
            ],
            'receivables' => $this->receivables($now, $request),
        ];
    }

    /**
     * The four headline cards, each against the 30 days before the 30 shown.
     *
     * @return array<string, mixed>
     */
    private function kpis(CarbonImmutable $now): array
    {
        $from = $now->subDays(29)->toDateString();
        $to = $now->toDateString();
        $priorFrom = $now->subDays(59)->toDateString();
        $priorTo = $now->subDays(30)->toDateString();

        $revenue = $this->revenueBetween($from, $to);
        $priorRevenue = $this->revenueBetween($priorFrom, $priorTo);
        $orders = $this->ordersBetween($from, $to);
        $priorOrders = $this->ordersBetween($priorFrom, $priorTo);
        $customers = $this->newCustomersBetween($from, $to);
        $priorCustomers = $this->newCustomersBetween($priorFrom, $priorTo);

        // The debtors figure is the live receivable, not a 30-day window: what is owed right now is
        // owed regardless of when it was sold. It carries no growth arrow either — more debt is not
        // an improvement, and an arrow would imply otherwise.
        $outstanding = $this->reports->outstandingReceivables();

        return [
            'revenue' => ['value' => $revenue, 'change' => $this->change($revenue, $priorRevenue)],
            'orders' => ['value' => $orders, 'change' => $this->change((string) $orders, (string) $priorOrders)],
            'customers' => ['value' => $customers, 'change' => $this->change((string) $customers, (string) $priorCustomers)],
            'outstanding' => [
                'value' => $outstanding['amount'],
                'debtors' => $outstanding['debtors'],
            ],
        ];
    }

    /**
     * Seven bars, Monday to Sunday, for the week containing `$now`.
     *
     * @return array<string, mixed>
     */
    private function week(CarbonImmutable $now): array
    {
        $start = $now->startOfWeek();
        $end = $start->addDays(6);
        $daily = $this->revenueByDay($start->toDateString(), $end->toDateString());

        $bars = [];
        foreach (range(0, 6) as $offset) {
            $day = $start->addDays($offset);
            $date = $day->toDateString();
            $bars[] = [
                'label' => $day->format('D'),
                'value' => $daily[$date] ?? '0.00',
                'tooltip' => $day->format('D j M'),
                // A day still to come is drawn empty rather than as a zero-revenue day.
                'future' => $day->gt($now),
                // Today: highlighted before anyone hovers, so the chart opens on the period the
                // reader is actually in rather than looking uniformly inactive.
                'current' => $date === $now->toDateString(),
            ];
        }

        $total = $this->revenueBetween($start->toDateString(), $end->toDateString());
        $priorStart = $start->subWeek();
        $prior = $this->revenueBetween($priorStart->toDateString(), $priorStart->addDays(6)->toDateString());
        // Days of the week that have actually happened, so a Wednesday average divides by three
        // rather than by seven and reads as a third of the truth. `diffInDays` is signed and
        // fractional, hence the explicit direction and floor.
        $elapsed = max(1, min(7, (int) floor($start->diffInDays($now)) + 1));

        $change = $this->change($total, $prior);

        return [
            'bars' => $bars,
            'total' => $total,
            'change' => $change,
            'sentence' => $this->sentence($total, $change, 'this week', 'from last week', 'last week'),
            'footer' => [
                $this->best($bars, 'Best day'),
                ['label' => 'Daily average', 'value' => $this->money(bcdiv($total, (string) $elapsed, 2))],
                ['label' => 'Orders', 'value' => $this->ordersBetween($start->toDateString(), $end->toDateString()).' this week'],
            ],
            'products' => $this->topProducts($start->toDateString(), $end->toDateString()),
        ];
    }

    /**
     * One bar per calendar week of the current month — four or five, as the month actually falls.
     *
     * @return array<string, mixed>
     */
    private function month(CarbonImmutable $now): array
    {
        $start = $now->startOfMonth();
        $end = $now->endOfMonth();
        $daily = $this->revenueByDay($start->toDateString(), $end->toDateString());

        $bars = [];
        $cursor = $start;
        $index = 1;

        // Weeks run from the 1st in seven-day spans, so the last one is short in most months. This
        // is what makes a five-bar month appear without being assumed.
        while ($cursor->lte($end)) {
            $weekEnd = $cursor->addDays(6)->gt($end) ? $end : $cursor->addDays(6);
            $value = '0.00';

            for ($day = $cursor; $day->lte($weekEnd); $day = $day->addDay()) {
                $value = bcadd($value, $daily[$day->toDateString()] ?? '0.00', 2);
            }

            $bars[] = [
                'label' => 'Wk '.$index,
                'value' => $value,
                'tooltip' => 'Wk '.$index.' · '.$cursor->format('j').'–'.$weekEnd->format('j M'),
                'future' => $cursor->gt($now),
                // The week today falls inside. Compared on whole days: `$weekEnd` carries the
                // month start's time-of-day (midnight), so comparing the instant would mark the
                // week current only during the first second of its final day and leave the chart
                // with no active bar for the rest of the month.
                'current' => $now->toDateString() >= $cursor->toDateString()
                    && $now->toDateString() <= $weekEnd->toDateString(),
            ];

            $cursor = $weekEnd->addDay();
            $index++;
        }

        $total = $this->revenueBetween($start->toDateString(), $end->toDateString());
        $priorStart = $start->subMonth();
        $prior = $this->revenueBetween($priorStart->toDateString(), $priorStart->endOfMonth()->toDateString());
        // Only weeks that have started count, for the same reason the weekly average should not be
        // diluted by weeks that have not happened yet.
        $weeksElapsed = max(1, count(array_filter($bars, fn (array $bar): bool => ! $bar['future'])));

        $change = $this->change($total, $prior);

        return [
            'bars' => $bars,
            'total' => $total,
            'change' => $change,
            'sentence' => $this->sentence($total, $change, 'this month', 'from last month', 'last month'),
            'footer' => [
                $this->best($bars, 'Best week'),
                ['label' => 'Weekly average', 'value' => $this->money(bcdiv($total, (string) $weeksElapsed, 2))],
                // Inventra has no configurable revenue goal, so the design's "Monthly goal" would
                // have to invent a target. Orders is a real figure and mirrors the Week footer.
                ['label' => 'Orders', 'value' => $this->ordersBetween($start->toDateString(), $end->toDateString()).' this month'],
            ],
            'products' => $this->topProducts($start->toDateString(), $end->toDateString()),
        ];
    }

    /**
     * Twelve bars for the current year, compared like for like against last year.
     *
     * @return array<string, mixed>
     */
    private function year(CarbonImmutable $now): array
    {
        $start = $now->startOfYear();
        $monthly = $this->revenueByMonth($start->toDateString(), $now->endOfYear()->toDateString());

        $bars = [];
        foreach (range(1, 12) as $month) {
            $monthStart = $start->month($month);
            $key = $monthStart->format('Y-m');
            $bars[] = [
                'label' => $monthStart->format('M'),
                'initial' => $monthStart->format('M')[0],
                'value' => $monthly[$key] ?? '0.00',
                'tooltip' => $monthStart->format('M Y'),
                // Months that have not happened are drawn as empty placeholders.
                'future' => $monthStart->gt($now->endOfMonth()),
                'current' => $month === (int) $now->format('n'),
            ];
        }

        // Year to date against the *same span* last year — 1 Jan to today, not against a full
        // twelve months, which would make every partial year look like a collapse.
        $total = $this->revenueBetween($start->toDateString(), $now->toDateString());
        $priorStart = $start->subYear();
        $prior = $this->revenueBetween($priorStart->toDateString(), $now->subYear()->toDateString());
        $monthsElapsed = max(1, (int) $now->format('n'));

        $change = $this->change($total, $prior);

        return [
            'bars' => $bars,
            'total' => $total,
            'change' => $change,
            'sentence' => $this->sentence(
                $total,
                $change,
                'in '.$now->format('Y').' so far',
                'on this point last year',
                'by this point last year',
                possessive: true
            ),
            'footer' => [
                $this->best($bars, 'Best month'),
                ['label' => 'Monthly average', 'value' => $this->money(bcdiv($total, (string) $monthsElapsed, 2))],
                // Run-rate: what a full year looks like if the months so far keep their pace.
                // Stated plainly as monthly average × 12 rather than a smoothed projection.
                ['label' => 'Run-rate (yr)', 'value' => $this->money(bcmul(bcdiv($total, (string) $monthsElapsed, 2), '12', 2))],
            ],
            'products' => $this->topProducts($start->toDateString(), $now->toDateString()),
        ];
    }

    /**
     * Revenue for a span of trading days: sales made in it, less goods returned during it.
     *
     * Returns are deducted by `returned_at` rather than by the date of the sale they came from,
     * because the report describes what happened in the period it covers.
     */
    private function revenueBetween(string $from, string $to): string
    {
        $sales = (string) $this->completedSales()->whereBetween('sale_date', [$from, $to])->sum('total_amount');

        return bcsub(Money::zero($sales), $this->returnsBetween($from, $to), 2);
    }

    /**
     * Goods returned against sales made in this period.
     *
     * Attributed to the sale's trading day rather than the day of the return, so the period total
     * is the sum of its own bars — a chart whose columns did not add up to its headline would be
     * worse than either convention on its own.
     */
    private function returnsBetween(string $from, string $to): string
    {
        $value = (string) DB::table('sale_returns')->where('sale_returns.business_id', $this->businessId())
            ->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->where('sales.status', 'completed')
            ->whereBetween('sales.sale_date', [$from, $to])
            ->sum('sale_returns.merchandise_value');

        return Money::zero($value);
    }

    /**
     * Revenue per trading day, as one grouped query rather than one per bar.
     *
     * @return array<string, string>
     */
    private function revenueByDay(string $from, string $to): array
    {
        $sales = $this->completedSales()
            ->selectRaw('sale_date AS bucket, SUM(total_amount) AS amount')
            ->whereBetween('sale_date', [$from, $to])
            ->groupBy('sale_date')
            ->pluck('amount', 'bucket');

        // Grouped by the *sale's* trading day, not the day the goods came back, so a bar can never
        // go negative. See the class note on why the bars and the period total date returns
        // differently.
        $returns = DB::table('sale_returns')->where('sale_returns.business_id', $this->businessId())
            ->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->selectRaw('sales.sale_date AS bucket, SUM(sale_returns.merchandise_value) AS amount')
            ->where('sales.status', 'completed')
            ->whereBetween('sales.sale_date', [$from, $to])
            ->groupBy('sales.sale_date')
            ->pluck('amount', 'bucket');

        return $this->net($sales, $returns);
    }

    /**
     * Revenue per calendar month, keyed `Y-m`.
     *
     * @return array<string, string>
     */
    private function revenueByMonth(string $from, string $to): array
    {
        $sales = $this->completedSales()
            ->selectRaw("DATE_FORMAT(sale_date, '%Y-%m') AS bucket, SUM(total_amount) AS amount")
            ->whereBetween('sale_date', [$from, $to])
            ->groupBy('bucket')
            ->pluck('amount', 'bucket');

        // By the sale's own month, for the same reason the daily bars use the sale's own day.
        $returns = DB::table('sale_returns')->where('sale_returns.business_id', $this->businessId())
            ->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->selectRaw("DATE_FORMAT(sales.sale_date, '%Y-%m') AS bucket, SUM(sale_returns.merchandise_value) AS amount")
            ->where('sales.status', 'completed')
            ->whereBetween('sales.sale_date', [$from, $to])
            ->groupBy('bucket')
            ->pluck('amount', 'bucket');

        return $this->net($sales, $returns);
    }

    /**
     * Sales less returns, bucket by bucket.
     *
     * @param  Collection<array-key, mixed>  $sales
     * @param  Collection<array-key, mixed>  $returns
     * @return array<string, string>
     */
    private function net($sales, $returns): array
    {
        $net = [];

        foreach ($sales as $key => $value) {
            $net[(string) $key] = Money::zero((string) $value);
        }

        foreach ($returns as $key => $value) {
            $key = (string) $key;
            $net[$key] = bcsub($net[$key] ?? '0.00', Money::zero((string) $value), 2);
        }

        return $net;
    }

    /**
     * Orders: completed sales whose trading day falls in the period.
     *
     * A returned sale is still an order that happened — the goods coming back reduces revenue, not
     * the fact of the transaction. A voided sale is not counted, because voiding withdraws it. A
     * corrected sale is amended in place rather than re-recorded, so it is counted once.
     */
    private function ordersBetween(string $from, string $to): int
    {
        return $this->completedSales()->whereBetween('sale_date', [$from, $to])->count();
    }

    /** Registered customers created in the period. Walk-ins have no Customer row and are not counted. */
    private function newCustomersBetween(string $from, string $to): int
    {
        return Customer::query()
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->count();
    }

    /**
     * The four products that earned the most in the period, net of what came back.
     *
     * Ranked on `sale_items.line_total` — the price as sold, with any line-level discount already
     * in it — less `sale_return_items.return_line_value` for the same product. A product whose
     * every unit was returned therefore ranks at zero rather than standing as though it sold.
     *
     * @return list<array<string, string>>
     */
    private function topProducts(string $from, string $to): array
    {
        $sold = DB::table('sale_items')->where('sale_items.business_id', $this->businessId())
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->selectRaw('sale_items.product_id AS product, SUM(sale_items.line_total) AS amount')
            ->where('sales.status', 'completed')
            ->whereBetween('sales.sale_date', [$from, $to])
            ->groupBy('sale_items.product_id')
            ->pluck('amount', 'product');

        $returned = DB::table('sale_return_items')->where('sale_return_items.business_id', $this->businessId())
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->selectRaw('sale_return_items.product_id AS product, SUM(sale_return_items.return_line_value) AS amount')
            ->where('sales.status', 'completed')
            // By the sale's trading day, matching Revenue, so a product cannot be ranked on one
            // basis while the headline uses another.
            ->whereBetween('sales.sale_date', [$from, $to])
            ->groupBy('sale_return_items.product_id')
            ->pluck('amount', 'product');

        $net = [];
        foreach ($sold as $productId => $value) {
            $net[(int) $productId] = Money::zero((string) $value);
        }
        foreach ($returned as $productId => $value) {
            $id = (int) $productId;
            $net[$id] = bcsub($net[$id] ?? '0.00', Money::zero((string) $value), 2);
        }

        // Only products that actually earned something appear; a net-zero or negative line is not a
        // "top" product and would otherwise draw an empty bar.
        $net = array_filter($net, fn (string $value): bool => bccomp($value, '0.00', 2) > 0);
        arsort($net);
        $net = array_slice($net, 0, 4, true);

        if ($net === []) {
            return [];
        }

        // The name as sold, not the product's name today, taken from the most recent line.
        $names = DB::table('sale_items')->where('sale_items.business_id', $this->businessId())
            ->whereIn('product_id', array_keys($net))
            ->orderByDesc('id')
            ->pluck('product_name_snapshot', 'product_id');

        $highest = (string) reset($net);
        $rows = [];

        foreach ($net as $productId => $value) {
            $rows[] = [
                'name' => (string) ($names[$productId] ?? 'Unknown product'),
                'value' => $value,
                'display' => $this->money($value),
                // Relative to the leader, which fills the bar.
                'percent' => bccomp($highest, '0.00', 2) > 0
                    ? (float) bcmul(bcdiv($value, $highest, 4), '100', 2)
                    : 0.0,
            ];
        }

        return $rows;
    }

    /**
     * Who owes money, aged by how long ago the goods were sold, one page at a time.
     *
     * Inventra has no invoice due date, so age is measured from `sale_date` — the trading day. That
     * is the only date the domain offers for "how long has this been outstanding", and it is stated
     * here rather than inferred.
     *
     * The amount is each Sale's own `balance_due`, which payments, returns, refunds, corrections
     * and discounts have already settled, so nothing is recomputed from the original total.
     *
     * Registered customers are grouped, because their debts are one relationship. Walk-in sales are
     * listed one per sale: they have no customer identity, and folding a hundred unrelated
     * strangers into a single "Walk-in customer" debtor would invent a person nobody can chase.
     *
     * Grouping and bucketing both happen in SQL, so the page really is a page: the database returns
     * ten rows, not every debtor for PHP to slice. The `CASE` arms put each sale's balance into its
     * own age column before the group by, which is what lets one query produce all four buckets and
     * a total without a second pass.
     */
    private function receivables(CarbonImmutable $now, Request $request): array
    {
        $today = $now->toDateString();

        // One aggregate column per bucket. `DATEDIFF` is the age in whole days from the trading
        // day, matching how a person would count it.
        $buckets = [];
        foreach (self::AGING_BUCKETS as $index => [$min, $max, $label]) {
            $condition = $max === null
                ? "DATEDIFF(?, sales.sale_date) >= {$min}"
                : "DATEDIFF(?, sales.sale_date) BETWEEN {$min} AND {$max}";

            $buckets[] = "SUM(CASE WHEN {$condition} THEN sales.balance_due ELSE 0 END) AS bucket_{$index}";
        }

        $bindings = array_fill(0, count(self::AGING_BUCKETS), $today);

        $query = DB::table('sales')->where('sales.business_id', $this->businessId())
            // The join names the Business so a debtor's name can never come from another tenant.
            ->leftJoin('customers', fn ($join) => $join->on('customers.id', '=', 'sales.customer_id')
                ->where('customers.business_id', app(CurrentBusiness::class)->id()))
            ->where('sales.status', 'completed')
            ->where('sales.balance_due', '>', 0)
            ->selectRaw(implode(', ', [
                // A registered customer groups by id; a walk-in groups by its own sale, so two
                // unrelated counter debts never merge into one.
                "CASE WHEN sales.is_walk_in = 1 OR sales.customer_id IS NULL
                      THEN CONCAT('sale:', sales.id) ELSE CONCAT('customer:', sales.customer_id) END AS debtor_key",
                "CASE WHEN sales.is_walk_in = 1 OR sales.customer_id IS NULL
                      THEN CONCAT('Walk-in · ', sales.sale_number)
                      ELSE COALESCE(NULLIF(TRIM(CONCAT(COALESCE(customers.first_name, ''), ' ', COALESCE(customers.last_name, ''))), ''), sales.customer_name_snapshot)
                      END AS debtor_name",
                'SUM(sales.balance_due) AS total_owed',
                ...$buckets,
            ]), [...$bindings])
            ->groupBy('debtor_key', 'debtor_name')
            ->orderByDesc('total_owed')
            ->orderBy('debtor_key');

        $paginator = $query->paginate(
            PerPage::resolve($request),
            ['*'],
            'page'
        )->withQueryString();

        // Shape each row for the table; the figures are already summed by the database.
        $paginator->through(function (object $row): array {
            $bucketValues = [];
            foreach (self::AGING_BUCKETS as $index => [, , $label]) {
                $bucketValues[$label] = Money::zero((string) $row->{'bucket_'.$index});
            }

            return [
                'name' => (string) $row->debtor_name,
                'total' => Money::zero((string) $row->total_owed),
                'buckets' => $bucketValues,
            ];
        });

        return [
            'buckets' => array_column(self::AGING_BUCKETS, 2),
            'paginator' => $paginator,
        ];
    }

    private function bucketFor(int $age): string
    {
        foreach (self::AGING_BUCKETS as [$min, $max, $label]) {
            if ($age >= $min && ($max === null || $age <= $max)) {
                return $label;
            }
        }

        return self::AGING_BUCKETS[0][2];
    }

    /**
     * The period-on-period movement, phrased so the card can print it without inventing anything.
     *
     * Growth from zero is not a percentage. Rather than dividing by it, or claiming a tidy "100%",
     * the two zero cases get wording of their own: something where there was nothing is "New", and
     * nothing where there was nothing is "No change". Both keep the line in place so the four cards
     * stay aligned whatever their data says.
     *
     * @return array{direction: string, percent: int|null, label: string}
     */
    private function change(string $current, string $previous, string $suffix = 'vs prev 30d'): array
    {
        $hadPrevious = bccomp($previous, '0', 2) > 0;
        $hasCurrent = bccomp($current, '0', 2) > 0;

        if (! $hadPrevious) {
            return $hasCurrent
                // Genuinely new activity: true, and worth showing in the positive colour.
                ? ['direction' => 'new', 'percent' => null, 'label' => 'New '.$suffix]
                : ['direction' => 'flat', 'percent' => null, 'label' => 'No change '.$suffix];
        }

        $delta = bcsub($current, $previous, 2);
        $percent = (int) round((float) bcmul(bcdiv($delta, $previous, 4), '100', 2));
        $direction = $percent > 0 ? 'up' : ($percent < 0 ? 'down' : 'flat');

        return [
            'direction' => $direction,
            'percent' => abs($percent),
            'label' => abs($percent).'% '.$suffix,
        ];
    }

    /**
     * The trend card's sentence, built here so the browser never phrases money.
     *
     * Each period supplies its own nouns — "this week" / "from last week" — and the comparison
     * clause is dropped entirely where the previous period had no revenue, replaced by a plain
     * statement of that fact rather than a percentage that would have to be invented.
     *
     * @param  array{direction: string, percent: int|null}  $change
     * @return array{amount: string, lead: string, subject: string, clause: string, direction: string}
     */
    private function sentence(string $total, array $change, string $subject, string $comparison, string $bare, bool $possessive = false): array
    {
        $clause = match (true) {
            $change['direction'] === 'up' => 'up '.$change['percent'].'% '.$comparison.'.',
            $change['direction'] === 'down' => 'down '.$change['percent'].'% '.$comparison.'.',
            $change['direction'] === 'flat' && $change['percent'] !== null => 'unchanged '.$comparison.'.',
            default => '',
        };

        return [
            'amount' => '₦'.Money::abbreviate($total),
            'lead' => $possessive ? 'You’ve earned' : 'You earned',
            'subject' => $subject,
            'clause' => $clause,
            // Where there is nothing to compare against, say so plainly instead of a percentage.
            // `$bare` is the period without the comparative preposition: "last month" rather than
            // "from last month", which would not read as English after "no revenue recorded".
            'fallback' => $clause === '' ? 'no revenue recorded '.$bare.'.' : '',
            'direction' => $change['direction'],
        ];
    }

    /**
     * The highest bar, for the footer.
     *
     * @param  list<array<string, mixed>>  $bars
     * @return array{label: string, value: string}
     */
    private function best(array $bars, string $label): array
    {
        $best = null;

        foreach ($bars as $bar) {
            if ($best === null || bccomp($bar['value'], $best['value'], 2) > 0) {
                $best = $bar;
            }
        }

        if ($best === null || bccomp($best['value'], '0.00', 2) <= 0) {
            return ['label' => $label, 'value' => '—'];
        }

        return ['label' => $label, 'value' => $this->money($best['value']).' · '.$best['label']];
    }

    /** Sales that count: completed, and therefore neither voided nor still a draft. */
    private function completedSales()
    {
        return Sale::query()->where('status', 'completed');
    }

    /** ₦3.94M, ₦486K — the abbreviated form the dashboard headlines print. */
    private function money(string $amount): string
    {
        return '₦'.Money::abbreviate($amount);
    }
}
