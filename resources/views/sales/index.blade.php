{{--
    Sales.

    Its own layout, deliberately not the application's generic page/card scaffolding: this screen is
    scanned a row at a time, so it wants an explicit column model, tight rows and a thin filter
    strip. Every class here is `sales-` prefixed and scoped to this page, so nothing it does can
    reach the Inventory, Customers or Staff tables.

    Column widths live in a <colgroup>, which is the browser's own column model — under
    `table-layout: fixed` the widths are taken from it directly, so no global `th`/`td` rule can
    collapse a column the way `.inventra-content th.ui-sn { width: 1% }` was collapsing S/N.

    Selecting a row opens the panel from data the row already carries; the pencil opens the same
    panel in correction mode. Neither navigates away.
--}}
<x-app-layout title="Sales">
    <div class="sales-index-shell" x-data="salesList"
         x-bind:class="panelMode ? 'has-panel' : ''"
         data-reopen-correction="{{ session('correctionFailedFor') ?? '' }}">

        <div class="sales-main">
            {{-- The application shell renders "Sales" in the topbar; the page owns the one primary
                 action so it sits with the list it belongs to. The shell's duplicate is suppressed
                 for this route, so there is exactly one Record Sale on the screen.

                 The same `ui-page-header` composition as Inventory: title, supporting line, and
                 the page's one primary action held to the right. Using Inventory's own classes
                 rather than a sales-only copy means the two pages cannot drift apart in heading
                 size, spacing or the width at which the action wraps. --}}
            <div class="ui-page-header sales-page-header">
                <div>
                    <h2>Sales</h2>
                    <p class="ui-page-description">View, manage and track all recorded sales.</p>
                </div>
                @can('create', \App\Models\Sale::class)
                    <div class="ui-page-actions">
                        <a href="{{ route('sales.create') }}" class="inventra-primary-action">
                            <img src="{{ asset('images/figma/icon-plus.svg') }}" alt="">Record Sale
                        </a>
                    </div>
                @endcan
            </div>

            <form method="GET" class="sales-filter-bar">
                {{-- One perceived date filter. Two native inputs still submit the `from`/`to` params
                     every filter and the CSV export already understand, so no picker library is
                     needed; the wrapper is what makes them read as a single control. --}}
                <div class="sales-daterange" data-empty="{{ request('from') || request('to') ? '0' : '1' }}">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7"
                         stroke-linecap="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/></svg>
                    <span class="sales-daterange-placeholder" aria-hidden="true">Select a date range</span>
                    <span class="sales-daterange-fields">
                        <input type="date" name="from" aria-label="From date" data-table-control
                               value="{{ is_string(request('from')) ? request('from') : '' }}">
                        <span class="sales-daterange-sep" aria-hidden="true">&ndash;</span>
                        <input type="date" name="to" aria-label="To date" data-table-control
                               value="{{ is_string(request('to')) ? request('to') : '' }}">
                    </span>
                </div>

                <select name="customer" aria-label="Customer" class="sales-select" data-table-control>
                    <option value="">Customer</option>
                    {{-- A walk-in has no customer id, so it needs a value of its own to stay
                         discoverable from this control. --}}
                    <option value="walk-in" @selected(request('customer') === 'walk-in')>Walk-in sales</option>
                    @foreach($customers as $customer)
                        <option value="{{ $customer->id }}" @selected((string) request('customer') === (string) $customer->id)>
                            {{ $customer->first_name }} {{ $customer->last_name }}
                        </option>
                    @endforeach
                </select>

                <select name="payment_status" aria-label="Payment status" class="sales-select" data-table-control>
                    <option value="">Payment status</option>
                    @foreach(\App\Enums\PaymentStatus::cases() as $payment)
                        <option value="{{ $payment->value }}" @selected(request('payment_status') === $payment->value)>{{ ucfirst($payment->value) }}</option>
                    @endforeach
                </select>

                {{-- Filters apply as they change, through the project's shared `data-table-control`
                     handler. The submit stays in the markup, visually hidden, so the form still
                     works with JavaScript unavailable. --}}
                <button type="submit" class="ui-visually-hidden-until-focus">Apply filters</button>
                <a href="{{ route('sales.index') }}" class="sales-clear">Clear filters</a>

                {{-- Export, as a menu of formats rather than one format's link.

                     Both entries are plain anchors carrying `request()->query()`, which is the same
                     filter state the list was drawn from and the same state the controller re-reads
                     through `filteredSales()`. So the table, the CSV and the PDF cannot disagree
                     about what "the current filters" means, and either format still downloads with
                     JavaScript unavailable — the menu is open by default to the keyboard in that
                     case, because `x-cloak` and the toggle are the only things hiding it. --}}
                <div class="sales-export-menu" x-data="salesExportMenu" x-on:keydown.escape.window="close"
                     x-on:click.outside="close">
                    <button type="button" class="sales-export" x-ref="trigger"
                            x-on:click="toggle" x-bind:aria-expanded="open ? 'true' : 'false'"
                            aria-haspopup="menu" aria-controls="sales-export-formats">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/></svg>
                        Export
                    </button>

                    <div class="sales-export-list" id="sales-export-formats" role="menu" x-cloak x-show="open">
                        <a href="{{ route('sales.export', request()->query()) }}" role="menuitem"
                           class="sales-export-option" x-on:click="close">
                            <svg class="is-csv" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                 stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h5"/></svg>
                            Export as CSV
                        </a>
                        <a href="{{ route('sales.export.pdf', request()->query()) }}" role="menuitem"
                           class="sales-export-option" x-on:click="close">
                            <svg class="is-pdf" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                 stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M9 17v-4h1.5a1.5 1.5 0 0 1 0 3H9"/><path d="M14 17v-4h1.6"/></svg>
                            Export as PDF
                        </a>
                    </div>
                </div>
            </form>

            <div class="sales-table-card">
                <table class="sales-table">
                    <caption class="ui-visually-hidden-until-focus">Recorded sales. Select a row to see its detail.</caption>
                    {{-- The column model. Under `table-layout: fixed` the browser takes its widths
                         from here, so no inherited rule can collapse a column. --}}
                    <colgroup>
                        <col class="sales-col-sn">
                        <col class="sales-col-id">
                        <col class="sales-col-customer">
                        <col class="sales-col-date">
                        <col class="sales-col-amount">
                        <col class="sales-col-status">
                        <col class="sales-col-actions">
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col">S/N</th>
                            <th scope="col">Sale ID</th>
                            <th scope="col">Customer</th>
                            <th scope="col">Date</th>
                            <th scope="col" class="sales-numeric">Amount</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="sales-centered">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($sales as $sale)
                            @php($canCorrect = auth()->user()->can('correct', $sale) && \App\Support\SaleCorrectionEligibility::permits($sale))
                            {{-- Returns are an Admin/Manager act, exactly as SaleReturnController
                                 enforces by role. Mirrored rather than re-decided here. --}}
                            @php($canReturn = in_array(auth()->user()->role, [\App\Enums\UserRole::Admin, \App\Enums\UserRole::Manager], true)
                                && $sale->status === \App\Enums\SaleStatus::Completed)
                            <tr
                                class="sales-table-row"
                                x-bind:class="selected === '{{ $sale->public_id }}' ? 'is-selected' : ''"
                                {{-- The row carries its own data; the handler reads it from the
                                     element, so no JSON parsing appears in an Alpine expression. --}}
                                x-on:click="selectRow"
                                data-sale="{{ json_encode([
                                    'id' => $sale->public_id,
                                    'number' => $sale->sale_number,
                                    'date' => $sale->sale_date->format('j M Y'),
                                    'recordedAt' => $sale->sale_date->format('j M Y').' · '.$sale->created_at->timezone(config('business.timezone'))->format('H:i'),
                                    'customer' => $sale->customer_name_snapshot,
                                    'phone' => $sale->customer_phone_snapshot,
                                    'initials' => $sale->isWalkIn() ? 'W' : \App\Support\Initials::from($sale->customer_name_snapshot),
                                    'isWalkIn' => $sale->isWalkIn(),
                                    'total' => \App\Support\Money::compact($sale->total_amount),
                                    // The settlement position, read from the Sale's own aggregate
                                    // columns. SaleFinancials::lockedState computes these under a
                                    // row lock whenever a payment, return or refund lands, and
                                    // `synchronizeReturnFinancials` writes them back — so the panel
                                    // states the position rather than deriving a second one. A DB
                                    // CHECK constraint keeps them reconciled:
                                    //   total − returned + credit = paid − refunded + balance
                                    'paid' => \App\Support\Money::compact($sale->amount_paid),
                                    'balance' => \App\Support\Money::compact($sale->balance_due),
                                    'returned' => \App\Support\Money::compact($sale->returned_amount),
                                    // Refund *due*: credit a return created, which is not the same
                                    // as cash gone out. `refunded` is what was actually disbursed.
                                    'refundDue' => \App\Support\Money::compact($sale->refundable_credit),
                                    'refunded' => \App\Support\Money::compact($sale->refunded_amount),
                                    // Whether each optional row is worth showing at all, decided
                                    // here in bcmath rather than by string-comparing a formatted
                                    // amount in the browser.
                                    'hasReturns' => bccomp((string) $sale->returned_amount, '0', 2) > 0,
                                    'hasRefundDue' => bccomp((string) $sale->refundable_credit, '0', 2) > 0,
                                    'hasRefunded' => bccomp((string) $sale->refunded_amount, '0', 2) > 0,
                                    'discount' => \App\Support\Money::compact($sale->discount_amount),
                                    'hasDiscount' => bccomp((string) $sale->discount_amount, '0', 2) > 0,
                                    'paymentStatus' => $sale->payment_status->value,
                                    'status' => $sale->status->value,
                                    'seller' => $sale->sold_by_name_snapshot,
                                    // The role is the live User's; the name above stays the Sale's
                                    // own snapshot. A seller since promoted must not have a past
                                    // sale restate who recorded it, but the role reads naturally in
                                    // the panel's "Recorded by" line and has no such history.
                                    'sellerRole' => $sale->seller?->role?->label(),
                                    'itemCount' => $sale->items_count,
                                    'subtotal' => \App\Support\Money::compact($sale->subtotal),
                                    'showUrl' => route('sales.show', $sale),
                                    'linesUrl' => route('sales.lines', $sale),
                                    'receiptUrl' => route('sales.receipt', $sale),
                                    'pdfUrl' => route('sales.receipt.pdf', $sale),
                                    'correctUrl' => $canCorrect ? route('sales.corrections.create', $sale) : null,
                                    'correctPanelUrl' => $canCorrect ? route('sales.corrections.panel', $sale) : null,
                                    'returnUrl' => $canReturn ? route('sales.returns.create', $sale) : null,
                                    // The Return form as a fragment, for the side panel. Offered
                                    // only where SaleReturnController would allow it; the route
                                    // re-checks the role regardless of what is rendered here.
                                    'returnPanelUrl' => $canReturn ? route('sales.returns.panel', $sale) : null,
                                ]) }}"
                            >
                                <td class="sales-sn">{{ $sales->firstItem() + $loop->index }}</td>
                                <th scope="row" class="sales-id">{{ $sale->sale_number }}</th>
                                <td>
                                    <span class="sales-customer">
                                        <span class="sales-avatar" aria-hidden="true">{{ $sale->isWalkIn() ? 'W' : \App\Support\Initials::from($sale->customer_name_snapshot) }}</span>
                                        <span class="sales-customer-name">{{ $sale->customer_name_snapshot }}</span>
                                    </span>
                                </td>
                                <td class="sales-date">{{ $sale->sale_date->format('j M Y') }}</td>
                                <td class="sales-numeric sales-amount">&#8358;{{ \App\Support\Money::compact($sale->total_amount) }}</td>
                                <td>
                                    <span class="sales-badge is-{{ $sale->payment_status->value }}">
                                        <span class="sales-dot" aria-hidden="true"></span>{{ ucfirst($sale->payment_status->value) }}
                                    </span>
                                    @if($sale->status === \App\Enums\SaleStatus::Voided)
                                        <span class="sales-badge is-voided">Voided</span>
                                    @endif
                                </td>
                                <td class="sales-centered">
                                    @if($canCorrect)
                                        <a href="{{ route('sales.corrections.create', $sale) }}"
                                           class="sales-pencil"
                                           aria-label="Correct sale {{ $sale->sale_number }}"
                                           data-tooltip="Correct sale"
                                           x-on:click="correctRow">
                                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.9"
                                                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="sales-empty">
                                    <x-empty-state title="No sales match these filters." description="Try another search or adjust your filters." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                <x-table-footer :paginator="$sales" noun="sale" />
            </div>
        </div>

        @include('sales._detail-panel')
    </div>
</x-app-layout>
