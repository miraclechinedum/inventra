{{--
    The Reports dashboard.

    Four headline figures, a revenue chart that switches between week, month and year, the products
    earning the most, and who owes money.

    Every number on this page was computed by ReportsDashboard and arrives finished. Alpine chooses
    which of the three prepared periods to show and draws the bars at the heights the server gave
    them; it does not add anything up. "Revenue" here is net of returns — see that class for why
    that differs from the Sales Report's Gross Sales, which keeps its own meaning.

    The chart is plain markup: a flex row of divs with percentage heights. No chart library, nothing
    to load, nothing for a CSP to refuse, and it reflows with the card.
--}}
@php($periods = $dashboard['periods'])
@php($kpis = $dashboard['kpis'])

<x-app-layout title="Reports">
    <div class="rpt" x-data="reportsDashboard" data-periods="{{ json_encode($periods) }}">

        {{-- ── Headline figures ──────────────────────────────────────────────────────────────── --}}
        <div class="rpt-kpis">
            <div class="rpt-kpi">
                <div class="rpt-kpi-head">
                    <span>Revenue</span>
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 17l6-6 4 4 7-7"/><path d="M14 8h6v6"/></svg>
                </div>
                <p class="rpt-kpi-value">&#8358;{{ \App\Support\Money::abbreviate($kpis['revenue']['value']) }}</p>
                <x-report-change :change="$kpis['revenue']['change']" />
            </div>

            <div class="rpt-kpi">
                <div class="rpt-kpi-head">
                    <span>Orders</span>
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h3"/></svg>
                </div>
                <p class="rpt-kpi-value">{{ number_format($kpis['orders']['value']) }}</p>
                <x-report-change :change="$kpis['orders']['change']" />
            </div>

            {{-- The one card that leads somewhere, so it carries the design's blue outline. --}}
            <div class="rpt-kpi is-linked">
                <div class="rpt-kpi-head">
                    <span>New customers</span>
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>
                </div>
                <p class="rpt-kpi-value">{{ number_format($kpis['customers']['value']) }}</p>
                <div class="rpt-kpi-foot">
                    <x-report-change :change="$kpis['customers']['change']" />
                    <a href="{{ route('customers.index') }}" class="rpt-kpi-link">View all &rarr;</a>
                </div>
            </div>

            {{-- No growth arrow: more debt is not an improvement, and an arrow would imply one. --}}
            <div class="rpt-kpi">
                <div class="rpt-kpi-head"><span>Outstanding (debtors)</span></div>
                <p class="rpt-kpi-value is-owing">&#8358;{{ \App\Support\Money::abbreviate($kpis['outstanding']['value']) }}</p>
                <p class="rpt-kpi-note">
                    {{ $kpis['outstanding']['debtors'] }} {{ \Illuminate\Support\Str::plural('customer', $kpis['outstanding']['debtors']) }}
                </p>
            </div>
        </div>

        <div class="rpt-main">
            {{-- ── Revenue trend ─────────────────────────────────────────────────────────────── --}}
            <section class="rpt-card rpt-trend">
                <div class="rpt-trend-head">
                    <div>
                        <h2 class="rpt-card-title">Revenue trend</h2>
                        {{-- Every word and figure here was phrased by the server; Alpine only picks
                             which period's sentence to show. Nothing about money — not the amount,
                             not the percentage, not the wording — is decided in the browser. --}}
                        <p class="rpt-trend-sentence">
                            <span x-text="period.sentence.lead"></span>
                            <b x-text="period.sentence.amount"></b>
                            <span x-text="period.sentence.subject"></span>
                            <template x-if="period.sentence.clause">
                                <span>&mdash;
                                    <b x-bind:class="'is-' + period.sentence.direction"
                                       x-text="period.sentence.clause"></b></span>
                            </template>
                            {{-- No percentage where the previous period had no revenue: the fact is
                                 stated rather than a number invented from a division by zero. --}}
                            <template x-if="period.sentence.fallback">
                                <span>&mdash; <span x-text="period.sentence.fallback"></span></span>
                            </template>
                        </p>
                    </div>

                    <div class="rpt-tabs" role="tablist" aria-label="Reporting period">
                        <template x-for="tab in tabs" x-bind:key="tab.key">
                            <button type="button" class="rpt-tab" role="tab"
                                    x-bind:class="active === tab.key ? 'is-active' : ''"
                                    x-bind:aria-selected="active === tab.key ? 'true' : 'false'"
                                    x-on:click="choose(tab.key)"
                                    x-text="tab.label"></button>
                        </template>
                    </div>
                </div>

                <div class="rpt-chart">
                    {{-- The y-axis: five ticks from zero to the rounded ceiling the server's
                         largest bar implies. Labels only, no gridlines, as the design has it. --}}
                    <div class="rpt-axis" aria-hidden="true">
                        <template x-for="tick in ticks" x-bind:key="tick">
                            <span x-text="'₦' + tick"></span>
                        </template>
                    </div>

                    <div class="rpt-bars" role="img" x-bind:aria-label="chartLabel">
                        <template x-for="(bar, index) in period.bars" x-bind:key="index">
                            {{-- Methods, not assignments: the CSP-safe Alpine build parses a call
                                 but refuses a bare assignment expression in an attribute. --}}
                            <div class="rpt-bar-slot"
                                 x-on:mouseenter="highlight(index)"
                                 x-on:mouseleave="clearHighlight()"
                                 x-on:focusin="highlight(index)"
                                 x-on:focusout="clearHighlight()"
                                 tabindex="0"
                                 x-bind:aria-label="bar.tooltip + ': \u20A6' + bar.display">
                                {{-- The tooltip sits above the active bar. --}}
                                <div class="rpt-tip" x-show="activeIndex === index" x-cloak>
                                    <span class="rpt-tip-label" x-show="period.key !== 'week'" x-text="bar.tooltip"></span>
                                    <span x-text="'₦' + bar.display"></span>
                                </div>
                                <div class="rpt-bar"
                                     x-bind:class="bar.future ? 'is-future' : (activeIndex === index ? 'is-active' : '')"
                                     x-bind:style="'height:' + bar.height + '%'"></div>
                                <span class="rpt-bar-label"
                                      x-bind:class="activeIndex === index ? 'is-active' : ''"
                                      x-text="period.key === 'year' ? bar.initial : bar.label"></span>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="rpt-trend-foot">
                    <template x-for="(metric, index) in period.footer" x-bind:key="index">
                        <div class="rpt-metric">
                            <span class="rpt-metric-icon" x-bind:class="'is-' + index" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"
                                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l6-6 4 4 7-7"/><path d="M14 8h6v6"/></svg>
                            </span>
                            <span class="rpt-metric-body">
                                <span class="rpt-metric-label" x-text="metric.label"></span>
                                <b class="rpt-metric-value" x-text="metric.value"></b>
                            </span>
                        </div>
                    </template>
                </div>
            </section>

            {{-- ── Top products ──────────────────────────────────────────────────────────────── --}}
            <section class="rpt-card rpt-products">
                <h2 class="rpt-card-title">Top products</h2>

                {{-- Ranked on the same period as the chart, so the two always describe the same
                     span of trading. Net of returns, like Revenue. --}}
                <template x-if="period.products.length">
                    <ul class="rpt-product-list">
                        <template x-for="product in period.products" x-bind:key="product.name">
                            <li class="rpt-product">
                                <span class="rpt-product-head">
                                    <span class="rpt-product-name" x-text="product.name"></span>
                                    <b class="rpt-product-value" x-text="product.display"></b>
                                </span>
                                <span class="rpt-product-track">
                                    <span class="rpt-product-fill" x-bind:style="'width:' + product.percent + '%'"></span>
                                </span>
                            </li>
                        </template>
                    </ul>
                </template>

                <template x-if="!period.products.length">
                    <p class="rpt-empty">No product sales for this period.</p>
                </template>
            </section>
        </div>

        {{-- ── Receivables ───────────────────────────────────────────────────────────────────── --}}
        @php($receivables = $dashboard['receivables']['paginator'])
        <section class="rpt-card rpt-receivables">
            <h2 class="rpt-card-title">Receivables &mdash; debtors aging</h2>

            @if($receivables->total() > 0)
                <div class="rpt-table-scroll">
                    <table class="rpt-table">
                        <thead>
                            <tr>
                                <th scope="col" class="ui-sn">S/N</th>
                                <th scope="col">Customer</th>
                                @foreach($dashboard['receivables']['buckets'] as $bucket)
                                    <th scope="col" class="rpt-numeric">{{ $bucket }}</th>
                                @endforeach
                                <th scope="col" class="rpt-numeric">Total owed</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($receivables as $index => $row)
                                <tr>
                                    {{-- Continuous across pages: page two starts at 11, not 1. --}}
                                    <td class="ui-sn">{{ $receivables->firstItem() + $index }}</td>
                                    <td>{{ $row['name'] }}</td>
                                    @foreach($dashboard['receivables']['buckets'] as $bucket)
                                        <td class="rpt-numeric">
                                            {{-- An em dash where a bucket holds nothing: a column of
                                                 zeroes would read as figures rather than absences. --}}
                                            @if(bccomp($row['buckets'][$bucket], '0', 2) > 0)
                                                &#8358;{{ \App\Support\Money::compact($row['buckets'][$bucket]) }}
                                            @else
                                                &mdash;
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="rpt-numeric rpt-owed">&#8358;{{ \App\Support\Money::compact($row['total']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{-- The same footer every other record list uses, so the rows-per-page control and
                     the page links behave here exactly as they do on Sales and Inventory. --}}
                <x-table-footer :paginator="$receivables" noun="debtor" />
            @else
                <p class="rpt-empty">No outstanding receivables.</p>
            @endif
        </section>

    </div>
</x-app-layout>
