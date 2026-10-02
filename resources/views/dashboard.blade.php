{{--
    The headline dashboard, built to the Figma.

    Every figure here comes from DashboardOverview, which aggregates in SQL over `sale_date` — the
    trading day the Sales Report keys off — so this page and the reports cannot disagree. Nothing on
    this screen is hard-coded: with an empty database every card renders its zero or empty state.

    Two honesty notes:
    - The fourth KPI reports OUTSTANDING SALES, not the Figma's "Pending Follow-Ups". Inventra has
      no follow-up concept, so the card reports a real number under its real name.
    - Trend percentages are omitted, not zeroed, when the comparison period had no sales: there is
      no honest percentage change from nothing.
--}}
@php($canRecordSale = auth()->user()->can('create', \App\Models\Sale::class))
@php($canAddProduct = auth()->user()->can('create', \App\Models\Product::class))
{{-- Abbreviated for readability (₦4.82M), but a zero prints in full as ₦0.00: "₦0" reads like a
     placeholder where the exact figure is the point. --}}
@php($money = fn (string $amount): string => $currency.(bccomp($amount, '0.00', 2) === 0
    ? \App\Support\Money::format($amount)
    : \App\Support\Money::abbreviate($amount)))

<x-app-layout title="Dashboard">
    <div class="dash-page">
        {{-- ── First-run setup. Administrators only, answered from live data, gone once done. ── --}}
        @if ($setup)
            <section class="dash-card dash-setup" aria-labelledby="dash-setup-title">
                <header class="dash-card-head">
                    <div>
                        <h2 id="dash-setup-title">Set up {{ app(\App\Settings\BusinessSettings::class)->current()->business_name }}</h2>
                        <p class="dash-card-sub">{{ collect($setup)->where('done', true)->count() }} of {{ count($setup) }} done. You can use Inventra while you finish.</p>
                    </div>
                </header>
                <ol class="dash-setup-steps">
                    @foreach ($setup as $step)
                        <li @class(['dash-setup-step', 'is-done' => $step['done']])>
                            <span class="dash-setup-mark" aria-hidden="true">{{ $step['done'] ? '✓' : '' }}</span>
                            @if ($step['done'])
                                <span>{{ $step['label'] }}</span><span class="sr-only">(done)</span>
                            @else
                                <a href="{{ route($step['route']) }}">{{ $step['label'] }}</a>
                                @if ($step['optional'])<span class="dash-setup-optional">Optional</span>@endif
                            @endif
                        </li>
                    @endforeach
                </ol>
            </section>
        @endif

        {{-- ── KPI cards ────────────────────────────────────────────────────────────────────── --}}
        <section class="dash-kpis" aria-label="Business summary">
            <article class="dash-kpi">
                <span class="dash-kpi-head">
                    <span class="dash-kpi-icon is-blue" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M16 15h3"/></svg>
                    </span>
                </span>
                <p class="dash-kpi-label">Total Inventory Value</p>
                <strong class="dash-kpi-value">{{ $money($kpis['inventoryValue']) }}</strong>
            </article>

            <article class="dash-kpi">
                <span class="dash-kpi-head">
                    <span class="dash-kpi-icon is-green" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 3h16v18l-3-2-2 2-3-2-3 2-2-2-3 2Z"/><path d="M9 8h6M9 12h6"/></svg>
                    </span>
                    @if ($kpis['salesTodayTrend'])
                        {{-- Rendered only when yesterday actually had sales; otherwise no claim is made. --}}
                        <span class="dash-trend is-{{ $kpis['salesTodayTrend']['direction'] }}">
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                @if ($kpis['salesTodayTrend']['direction'] === 'up')<path d="m3 17 6-6 4 4 8-8"/><path d="M17 7h4v4"/>@else<path d="m3 7 6 6 4-4 8 8"/><path d="M17 17h4v-4"/>@endif
                            </svg>
                            {{ $kpis['salesTodayTrend']['direction'] === 'up' ? 'Up' : 'Down' }} {{ $kpis['salesTodayTrend']['percent'] }}%
                        </span>
                    @endif
                </span>
                <p class="dash-kpi-label">Sales Today</p>
                <strong class="dash-kpi-value">{{ $money($kpis['salesToday']) }}</strong>
            </article>

            <article class="dash-kpi">
                <span class="dash-kpi-head">
                    <span class="dash-kpi-icon is-amber" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg>
                    </span>
                    @if ($kpis['lowStockCount'] > 0)
                        <span class="dash-chip is-amber">Needs attention</span>
                    @endif
                </span>
                <p class="dash-kpi-label">Low-Stock Products</p>
                <strong @class(['dash-kpi-value', 'is-amber' => $kpis['lowStockCount'] > 0])>{{ $kpis['lowStockCount'] }}</strong>
            </article>

            {{-- The Figma's fourth card is "Pending Follow-Ups". Inventra has no follow-up concept —
                 no column, model or route — so rather than fabricating a number this reports a real
                 metric under its real name: completed sales still carrying a balance. --}}
            <article class="dash-kpi">
                <span class="dash-kpi-head">
                    <span class="dash-kpi-icon is-slate" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    </span>
                </span>
                <p class="dash-kpi-label">Outstanding Sales</p>
                <strong class="dash-kpi-value">{{ $kpis['outstandingCount'] }}</strong>
            </article>
        </section>

        {{-- ── Revenue trend + low stock ────────────────────────────────────────────────────── --}}
        <section class="dash-split">
            <article class="dash-card dash-revenue">
                @if ($revenue['hasSales'])
                    <header class="dash-card-head">
                        <div>
                            <h2>Revenue trend</h2>
                            <p class="dash-card-sub">
                                You earned <strong>{{ $money($revenue['total']) }}</strong> this month
                                @if ($revenue['trend'])
                                    — <span class="dash-inline-trend is-{{ $revenue['trend']['direction'] }}">{{ $revenue['trend']['direction'] }} {{ $revenue['trend']['percent'] }}%</span> from last month.
                                @else
                                    {{-- No previous month to compare against, so no claim is made. --}}
                                    so far.
                                @endif
                            </p>
                        </div>
                        <a href="{{ route('reports.sales') }}" class="dash-button is-primary">View Report</a>
                    </header>

                    {{-- A plain CSS/SVG-free bar chart: heights are percentages of a ceiling the
                         server computed from the real maximum, so no chart library is needed and
                         there is nothing for a CSP to block. --}}
                    <div class="dash-chart" x-data="dashboardChart">
                        <div class="dash-chart-axis" aria-hidden="true">
                            @foreach ([1, 0.75, 0.5, 0.25, 0] as $fraction)
                                <span>{{ $currency }}{{ \App\Support\Money::abbreviate(bcmul($revenue['ceiling'], (string) $fraction, 2)) }}</span>
                            @endforeach
                        </div>
                        <div class="dash-chart-plot" role="img"
                             aria-label="Revenue by week this month: {{ collect($revenue['bars'])->map(fn ($b) => $b['label'].' '.$currency.\App\Support\Money::abbreviate($b['value']))->implode(', ') }}">
                            @foreach ($revenue['bars'] as $bar)
                                @php($height = bccomp($revenue['ceiling'], '0.00', 2) > 0 ? bcdiv(bcmul($bar['value'], '100', 4), $revenue['ceiling'], 1) : '0')
                                <div class="dash-chart-col">
                                    <button type="button" @class(['dash-bar', 'is-current' => $bar['current']])
                                            style="height: {{ max(1.5, (float) $height) }}%"
                                            x-on:mouseenter="show($el)" x-on:mouseleave="hide" x-on:focus="show($el)" x-on:blur="hide"
                                            data-tip="{{ $bar['tooltip'] }}" data-amount="{{ $money($bar['value']) }}"
                                            aria-label="{{ $bar['tooltip'] }}: {{ $money($bar['value']) }}"></button>
                                    <span @class(['dash-chart-label', 'is-current' => $bar['current']])>{{ $bar['label'] }}</span>
                                </div>
                            @endforeach
                            <div class="dash-tooltip" x-ref="tip" x-cloak x-show="open" x-bind:style="style" aria-hidden="true">
                                <small x-text="tip"></small>
                                <strong x-text="amount"></strong>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="dash-empty">
                        <span class="dash-empty-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15v3M12 10v8M17 6v12"/></svg>
                        </span>
                        <strong>No sales yet</strong>
                        <p>Record your first sale to see your dashboard come to life.</p>
                        @if ($canRecordSale)
                            <a href="{{ route('sales.create') }}" class="dash-button is-primary">
                                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                                Record Sale
                            </a>
                        @endif
                    </div>
                @endif
            </article>

            {{-- ── Low on stock ─────────────────────────────────────────────────────────────── --}}
            @if ($lowStock)
                <article class="dash-card dash-low-stock">
                    @if ($lowStock['inventoryIsEmpty'])
                        <header class="dash-card-head"><h2>Low on stock</h2></header>
                        <div class="dash-empty">
                            <span class="dash-empty-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg>
                            </span>
                            <strong>Your inventory is empty</strong>
                            <p>Add your first product to start tracking stock.</p>
                            @if ($canAddProduct)
                                <a href="{{ route('inventory.products.create') }}" class="dash-button is-primary">
                                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                                    Add Product
                                </a>
                            @endif
                        </div>
                    @elseif ($lowStock['count'] === 0)
                        <header class="dash-card-head">
                            <h2>Low on stock</h2>
                            <a href="{{ route('inventory.index') }}" class="dash-button is-primary">Show all products</a>
                        </header>
                        <div class="dash-empty">
                            <span class="dash-empty-icon is-green" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/></svg>
                            </span>
                            <strong>All good</strong>
                            <p>All products are above their reorder levels.</p>
                        </div>
                    @else
                        <div class="dash-low-callout">
                            <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg>
                            <span>Showing {{ $lowStock['count'] }} low-stock {{ \Illuminate\Support\Str::plural('product', $lowStock['count']) }}.</span>
                            {{-- The inventory list already accepts stock=low, so this is a real filter. --}}
                            <a href="{{ route('inventory.index', ['stock' => 'low']) }}" class="dash-button is-primary">View all</a>
                        </div>
                        <div class="dash-low-table-wrap">
                            <table class="dash-low-table">
                                <thead>
                                    <tr><th>Product</th><th class="is-num">Stock</th><th class="is-num">Reorder</th><th>Status</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($lowStock['products'] as $product)
                                        <tr>
                                            <td><a href="{{ route('inventory.products.show', $product) }}">{{ $product->name }}</a></td>
                                            <td class="is-num is-low">{{ \App\Support\Quantity::trim((string) $product->current_stock) }}</td>
                                            <td class="is-num is-muted">{{ \App\Support\Quantity::trim((string) $product->reorder_level) }}</td>
                                            <td><span class="dash-chip is-red"><span class="dash-dot" aria-hidden="true"></span>Low</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </article>
            @endif
        </section>

        {{-- ── Recent sales ─────────────────────────────────────────────────────────────────── --}}
        <section class="dash-card dash-recent">
            <header class="dash-card-head">
                <h2>Recent sales</h2>
                <a href="{{ route('sales.index') }}" class="dash-link">View all</a>
            </header>

            @if ($recentSales->isEmpty())
                <div class="dash-empty">
                    <span class="dash-empty-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2 3h3l2.6 12.4a2 2 0 0 0 2 1.6h8.2a2 2 0 0 0 2-1.6L22 7H6"/></svg>
                    </span>
                    <strong>No sales recorded yet</strong>
                    <p>Your sales history will show up here.</p>
                    @if ($canRecordSale)
                        <a href="{{ route('sales.create') }}" class="dash-button is-primary">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                            Record Sale
                        </a>
                    @endif
                </div>
            @else
                <div class="dash-recent-wrap">
                    <table class="dash-recent-table">
                        <thead>
                            <tr>
                                {{-- S/N is the shared record-list standard every Inventra listing
                                     carries. It numbers the displayed position, never the primary
                                     key. --}}
                                <th class="dash-sn">S/N</th>
                                <th>Date</th><th>Customer</th><th>Items</th>
                                <th class="is-num">Amount</th><th>Status</th>
                                <th><span class="dash-sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentSales as $sale)
                                @php($walkIn = (bool) $sale->is_walk_in)
                                @php($name = $walkIn ? 'Walk-in customer' : ($sale->customer_name_snapshot ?: 'Customer'))
                                @php($initials = $walkIn ? 'W' : collect(explode(' ', $name))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode(''))
                                <tr>
                                    {{-- The row's displayed position. The card never paginates, so
                                         this always counts 1..5 from the newest sale. --}}
                                    <td class="dash-sn">{{ $loop->iteration }}</td>
                                    <td class="is-muted">{{ \Carbon\CarbonImmutable::parse($sale->sale_date)->format('j M') }}</td>
                                    <td>
                                        <span class="dash-customer">
                                            <span @class(['dash-avatar', 'is-walk-in' => $walkIn]) aria-hidden="true">{{ $initials }}</span>
                                            {{ $name }}
                                        </span>
                                    </td>
                                    <td class="is-muted">{{ $sale->items_count }} {{ \Illuminate\Support\Str::plural('item', $sale->items_count) }}</td>
                                    <td class="is-num">{{ $currency }}{{ \App\Support\Money::format((string) $sale->total_amount) }}</td>
                                    <td>
                                        @php($status = $sale->payment_status instanceof \App\Enums\PaymentStatus ? $sale->payment_status : \App\Enums\PaymentStatus::from((string) $sale->payment_status))
                                        {{-- Status carries a dot AND a word, so it never relies on colour alone. --}}
                                        <span class="dash-chip is-{{ $status->value }}"><span class="dash-dot" aria-hidden="true"></span>{{ ucfirst($status->value) }}</span>
                                    </td>
                                    <td class="is-actions">
                                        @can('view', $sale)
                                            <a href="{{ route('sales.show', $sale) }}" class="dash-row-action" aria-label="View sale {{ $sale->sale_number }}">
                                                <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg>
                                            </a>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
