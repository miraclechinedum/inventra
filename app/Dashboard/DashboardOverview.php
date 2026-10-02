<?php

namespace App\Dashboard;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Settings\BusinessSettings;
use App\Support\Money;
use App\Tenancy\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The headline dashboard: four KPIs, a revenue trend, the low-stock panel and recent sales.
 *
 * Every figure is a database aggregate over decimal columns, never a float computed in PHP, and
 * every period figure keys off `sale_date` — the trading day the Sales Report uses — rather than
 * `created_at`, so the dashboard and the reports cannot disagree about what a month contained.
 * Voided sales are excluded everywhere by the same `status = completed` filter the reports apply.
 *
 * Two definitions are stated here rather than assumed, because neither previously existed:
 *
 *  - Inventory value is cost price × stock on hand, over active, non-deleted products. That is what
 *    the stock cost the business; valuing it at selling price would book unrealised margin as an
 *    asset.
 *  - The fourth KPI reports OUTSTANDING SALES, not "pending follow-ups". Inventra has no follow-up
 *    concept — no column, model or route — so the card reports a real number under its real name
 *    rather than dressing an unrelated figure in the design's label.
 */
class DashboardOverview
{
    /** Rows the dashboard previews. Both are display limits; the counts beside them are real totals. */
    public const LOW_STOCK_PREVIEW = 4;

    public const RECENT_SALES_LIMIT = 5;

    public function __construct(private readonly BusinessSettings $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $today = CarbonImmutable::now(config('business.timezone'));
        $management = $user->role !== UserRole::SalesRep;

        return [
            'currency' => $this->settings->currencySymbol(),
            'kpis' => $this->kpis($user, $today, $management),
            'revenue' => $this->revenue($user, $today, $management),
            'lowStock' => $management ? $this->lowStock() : null,
            'recentSales' => $this->recentSales($user, $management),
        ];
    }

    /* ------------------------------------------------------------------------ KPIs */

    /**
     * @return array<string, mixed>
     */
    private function kpis(User $user, CarbonImmutable $today, bool $management): array
    {
        // One row, three aggregates: value, low count and whether any product exists at all. The
        // last is what tells an empty inventory apart from one that simply has nothing low.
        // Raw builder query: the tenant scope does not reach it, so it names the Business.
        $stock = DB::table('products')
            ->where('business_id', app(CurrentBusiness::class)->id())
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->selectRaw('COALESCE(SUM(cost_price * current_stock), 0) value')
            ->selectRaw('COALESCE(SUM(current_stock <= reorder_level), 0) low')
            ->selectRaw('COUNT(*) total')
            ->first();

        $salesToday = $this->salesTotalBetween($user, $today->toDateString(), $today->toDateString(), $management);
        $yesterday = $today->subDay()->toDateString();
        $salesYesterday = $this->salesTotalBetween($user, $yesterday, $yesterday, $management);

        $outstanding = $this->outstandingSales($user, $management);

        return [
            'inventoryValue' => Money::zero((string) $stock?->value),
            'inventoryIsEmpty' => (int) ($stock?->total ?? 0) === 0,
            'salesToday' => $salesToday,
            'salesTodayTrend' => $this->trend($salesYesterday, $salesToday),
            'lowStockCount' => (int) ($stock?->low ?? 0),
            'outstandingCount' => (int) $outstanding->sale_count,
            'outstandingValue' => Money::zero((string) $outstanding->total),
        ];
    }

    private function outstandingSales(User $user, bool $management): object
    {
        return $this->scopedSales($user, $management)
            ->where('balance_due', '>', 0)
            ->selectRaw('COALESCE(SUM(balance_due), 0) total, COUNT(*) sale_count')
            ->first();
    }

    /* --------------------------------------------------------------------- revenue */

    /**
     * This calendar month's revenue, split into the weeks the month actually contains.
     *
     * Weeks run from the 1st in seven-day spans, so a month yields four or five buckets and the
     * last one is usually short. The number of buckets is whatever the month needs — forcing four
     * would silently drop the tail of a 31-day month from the chart while the total above it still
     * counted those days.
     *
     * @return array<string, mixed>
     */
    private function revenue(User $user, CarbonImmutable $today, bool $management): array
    {
        $start = $today->startOfMonth();
        $end = $today->endOfMonth();

        $daily = $this->dailyTotals($user, $start->toDateString(), $end->toDateString(), $management);

        $bars = [];
        $cursor = $start;
        $index = 1;

        while ($cursor->lte($end)) {
            $weekEnd = $cursor->addDays(6)->gt($end) ? $end : $cursor->addDays(6);
            $value = '0.00';

            for ($day = $cursor; $day->lte($weekEnd); $day = $day->addDay()) {
                $value = bcadd($value, $daily[$day->toDateString()] ?? '0.00', 2);
            }

            $bars[] = [
                'label' => 'Wk '.$index,
                'value' => $value,
                // Compared on whole days: `$weekEnd` carries the month start's time-of-day, so an
                // instant comparison would mark the week current for only its first second.
                'current' => $today->toDateString() >= $cursor->toDateString()
                    && $today->toDateString() <= $weekEnd->toDateString(),
                'tooltip' => 'Wk '.$index.' · '.$cursor->format('j').'–'.$weekEnd->format('j M'),
            ];

            $cursor = $weekEnd->addDay();
            $index++;
        }

        $total = array_reduce($bars, static fn (string $carry, array $bar): string => bcadd($carry, $bar['value'], 2), '0.00');
        $previousStart = $start->subMonth();
        $previous = $this->salesTotalBetween($user, $previousStart->toDateString(), $previousStart->endOfMonth()->toDateString(), $management);

        return [
            'bars' => $bars,
            'total' => $total,
            'previous' => $previous,
            'trend' => $this->trend($previous, $total),
            'ceiling' => $this->ceiling($bars),
            'hasSales' => bccomp($total, '0.00', 2) > 0,
        ];
    }

    /**
     * The y-axis top: the tallest bar rounded up to a round number, so gridlines land on values a
     * reader recognises. Never zero, or every bar would divide by it.
     */
    private function ceiling(array $bars): string
    {
        $max = array_reduce($bars, static fn (string $carry, array $bar): string => bccomp($bar['value'], $carry, 2) > 0 ? $bar['value'] : $carry, '0.00');

        if (bccomp($max, '0.00', 2) <= 0) {
            return '1000.00';
        }

        // Round up to a step the axis can divide into four clean labels.
        //
        // The step is a 1-2-5 multiple of a power of ten — the series people read axes in — so the
        // four gridlines land on values like 300K/600K/900K/1.2M rather than 375K/750K/1.12M. An
        // earlier version divided the power of ten by four directly, which produced exactly those
        // awkward thirds.
        $magnitude = bcpow('10', (string) max(0, strlen(explode('.', $max)[0]) - 1));

        foreach (['1', '2', '2.5', '5', '10'] as $multiple) {
            $step = bcmul($magnitude, $multiple, 2);
            // Four steps must reach the tallest bar, and the step itself must stay divisible by 4
            // cleanly enough for the labels to read well.
            $ceiling = bcmul($step, '4', 2);

            if (bccomp($ceiling, $max, 2) >= 0) {
                return $ceiling;
            }
        }

        return bcmul($magnitude, '40', 2);
    }

    /* ---------------------------------------------------------------- low stock */

    /**
     * @return array<string, mixed>
     */
    private function lowStock(): array
    {
        $anyProduct = Product::query()->active()->exists();

        $products = Product::query()->active()->lowStock()
            ->orderBy('current_stock')->orderBy('id')
            ->limit(self::LOW_STOCK_PREVIEW)
            ->get(['id', 'public_id', 'name', 'sku', 'unit', 'current_stock', 'reorder_level']);

        return [
            'inventoryIsEmpty' => ! $anyProduct,
            'count' => Product::query()->active()->lowStock()->count(),
            'products' => $products,
        ];
    }

    /* -------------------------------------------------------------- recent sales */

    private function recentSales(User $user, bool $management)
    {
        // `withCount` rather than loading the lines: the table shows how many items a sale had, and
        // fetching every SaleItem to count them in PHP would be an N+1 across the whole list.
        //
        // Ordered exactly as the Sales list orders itself — trading day first, then the primary key
        // to break ties. `sale_date` is a date, so several sales a day share one value; without the
        // second key MySQL could return them in any order and "the latest five" would drift between
        // requests. The LIMIT is applied by the database, so only five rows are ever hydrated.
        return $this->scopedSaleModels($user, $management)
            ->withCount('items')
            ->latest('sale_date')->latest('id')
            ->limit(self::RECENT_SALES_LIMIT)
            ->get(['id', 'public_id', 'sale_number', 'customer_id', 'is_walk_in',
                'customer_name_snapshot', 'total_amount', 'payment_status', 'sale_date']);
    }

    /* ------------------------------------------------------------------ helpers */

    /** Completed, non-voided sales — scoped to the seller for a Sales Representative. */
    private function scopedSales(User $user, bool $management)
    {
        return DB::table('sales')->where('sales.business_id', app(CurrentBusiness::class)->id())
            ->where('status', 'completed')
            ->when(! $management, fn ($query) => $query->where('sold_by', $user->getKey()));
    }

    private function scopedSaleModels(User $user, bool $management)
    {
        return Sale::query()
            ->where('status', 'completed')
            ->when(! $management, fn ($query) => $query->where('sold_by', $user->getKey()));
    }

    private function salesTotalBetween(User $user, string $from, string $to, bool $management): string
    {
        return Money::zero((string) $this->scopedSales($user, $management)
            ->whereBetween('sale_date', [$from, $to])
            ->sum('total_amount'));
    }

    /**
     * @return array<string, string> date => total
     */
    private function dailyTotals(User $user, string $from, string $to, bool $management): array
    {
        return $this->scopedSales($user, $management)
            ->whereBetween('sale_date', [$from, $to])
            ->groupBy('sale_date')
            ->pluck(DB::raw('COALESCE(SUM(total_amount), 0)'), 'sale_date')
            ->map(static fn ($total): string => Money::zero((string) $total))
            ->all();
    }

    /**
     * Percentage change, or null when there is nothing honest to compare against.
     *
     * A previous period of zero has no percentage: "up 100%" from nothing is meaningless and
     * dividing by it would crash. Null means the caller renders no trend at all rather than
     * inventing one, which is the whole point.
     *
     * @return array{direction: string, percent: string}|null
     */
    private function trend(string $previous, string $current): ?array
    {
        if (bccomp($previous, '0.00', 2) <= 0) {
            return null;
        }

        $change = bcsub($current, $previous, 2);
        $percent = bcdiv(bcmul($change, '100', 4), $previous, 1);

        return [
            'direction' => bccomp($change, '0.00', 2) >= 0 ? 'up' : 'down',
            'percent' => ltrim($percent, '-'),
        ];
    }
}
