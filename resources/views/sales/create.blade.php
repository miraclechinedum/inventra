{{--
    Record Sale.

    Every control below is a real form control inside a real form that posts to StoreSaleRequest, so
    the page degrades to a working (if plain) sale form without JavaScript. The Alpine component
    adds the searching, the running totals and the discount round trip on top of that, and none of
    what it computes is trusted: CreateSale re-reads prices and stock under a row lock, and an
    approved discount's amount is read from the database via the draft, never from this page.
--}}
@php($discountStatusTemplate = route('sales.discounts.draft.status', ['discountRequest' => '__ID__']))
<x-app-layout title="Record sale">
    <div
        class="ui-sale"
        x-data="recordSale"
        data-products="{{ json_encode($products) }}"
        data-customers="{{ json_encode($customers) }}"
        data-today="{{ $today }}"
        data-can-request-discount="{{ $canRequestDiscount ? '1' : '0' }}"
        data-products-url="{{ route('sales.create') }}"
        data-customers-url="{{ route('sales.customers.search') }}"
        data-draft-store-url="{{ route('sales.discounts.draft.store') }}"
        data-draft-status-url="{{ $discountStatusTemplate }}"
        data-resumable-url="{{ route('sales.discounts.draft.resumable') }}"
        {{-- What the operator had entered when a submission was rejected. Built server-side from
             authoritative records — prices, stock and names are re-read, never echoed back from the
             browser — and passed as a data attribute like every other config on this page, so no
             JSON parsing or unsafe expression appears in an Alpine attribute. --}}
        data-restored="{{ $restored ? json_encode($restored) : '' }}"
        {{-- A customer carried in from their profile. Only used when nothing is being restored
             from a rejected submission, which always takes precedence. --}}
        data-preselected="{{ $preselected ? json_encode($preselected) : '' }}"
    >
        <x-page-header title="Record a sale" description="Choose a customer, add products and record the payment." eyebrow="Sales">
            <a href="{{ route('sales.index') }}" class="ui-button">Back to sales</a>
        </x-page-header>

        {{-- Sales left waiting for a discount decision. The draft and its request are already in
             the database, so this is a real resume rather than a local draft: choosing one rebuilds
             the exact cart, buyer and trading day the approval was asked about. --}}
        <div class="ui-sale-resume" x-cloak x-show="hasResumable">
            <p class="ui-sale-resume-lead">
                You have <span x-text="resumable.length"></span> sale(s) saved while waiting for a discount decision.
            </p>
            <button type="button" class="ui-button" x-on:click="openResume">Resume a saved sale</button>
        </div>

        <form
            method="POST"
            action="{{ route('sales.store') }}"
            class="ui-sale-form"
            x-ref="form"
            x-on:submit="submit"
        >
            @csrf
            <x-validation-errors />

            {{-- What actually posts. Rendered from the component's own state so the wire format is
                 in one place, and deliberately values only — ids, quantities, the chosen date. No
                 price, total or discount amount is ever submitted as authority. --}}
            <input type="hidden" name="is_walk_in" x-bind:value="walkIn ? 1 : 0">
            <input type="hidden" name="customer_id" x-bind:value="customer ? customer.id : ''">
            <input type="hidden" name="sale_draft_id" x-bind:value="postedDraftId">
            <template x-for="(line, index) in postedLines" x-bind:key="index">
                <span>
                    <input type="hidden" x-bind:name="'products[' + index + '][product_id]'" x-bind:value="line.product_id">
                    <input type="hidden" x-bind:name="'products[' + index + '][quantity]'" x-bind:value="line.quantity">
                </span>
            </template>

            {{-- ── Customer ────────────────────────────────────────────────────────────────── --}}
            <section class="ui-sale-panel-section">
                <h2 class="ui-sale-section-label">Customer</h2>

                {{-- Chosen buyer, as a chip. Registered customer or walk-in — the same slot, because
                     a sale has exactly one buyer identity. --}}
                <div class="ui-sale-chip" x-cloak x-show="buyerChosen">
                    <span class="ui-sale-chip-avatar" x-text="walkIn ? 'W' : (customer ? customer.initials : '')" aria-hidden="true"></span>
                    <span class="ui-sale-chip-body">
                        <span class="ui-sale-chip-name" x-text="buyerLabel"></span>
                        <span class="ui-sale-chip-meta" x-show="!walkIn" x-text="customer ? customer.code + ' · ' + customer.phone : ''"></span>
                        <span class="ui-sale-chip-meta" x-show="walkIn">No account · no receipt by WhatsApp</span>
                    </span>
                    <button type="button" class="ui-sale-chip-remove" x-on:click="walkIn ? clearWalkIn() : clearCustomer()"
                            x-bind:aria-label="'Remove ' + buyerLabel" data-tooltip="Remove buyer">&times;</button>
                </div>

                <div class="ui-combobox" x-cloak x-show="!buyerChosen" x-on:click.outside="closeCustomerList">
                    <label class="inventra-field-label" for="customer-search">Search customers</label>
                    <div class="ui-combobox-control">
                        <input
                            id="customer-search"
                            type="text"
                            class="inventra-input ui-combobox-input"
                            placeholder="Add customer.."
                            autocomplete="off"
                            role="combobox"
                            aria-expanded="false"
                            x-bind:aria-expanded="customerOpen"
                            aria-controls="customer-options"
                            aria-autocomplete="list"
                            x-ref="customerInput"
                            x-model="customerQuery"
                            x-on:input="customerInput"
                            x-on:focus="customerFocus"
                            x-on:keydown.arrow-down.prevent="moveCustomer(1)"
                            x-on:keydown.arrow-up.prevent="moveCustomer(-1)"
                            x-on:keydown.enter="chooseCustomerAtCursor"
                            x-on:keydown.escape.stop="customerOpen = false"
                        >
                        <span class="ui-combobox-caret" aria-hidden="true"></span>
                    </div>
                    {{-- `mousedown.prevent` on each option, not `click`: pressing the mouse over a
                         listbox option would otherwise blur the input first, and the blur hides the
                         whole combobox — taking the option out of the document before its click
                         could ever land. Preventing the default on mousedown keeps focus where it
                         is, so the click always reaches the handler. Closing is handled by a
                         click-away on the combobox root below, which contains both the input and
                         the list, rather than by the input's own blur. --}}
                    <ul class="ui-combobox-list" id="customer-options" role="listbox" x-cloak x-show="customerOpen">
                        <template x-for="(row, index) in customerRows" x-bind:key="row.id">
                            <li
                                class="ui-combobox-option ui-sale-option"
                                role="option"
                                aria-selected="false"
                                x-bind:class="index === customerActive ? 'is-active' : ''"
                                x-on:mousedown.prevent="chooseCustomer(row)"
                                x-on:mousemove="customerActive = index"
                            >
                                <span class="ui-sale-option-main" x-text="row.name"></span>
                                <span class="ui-sale-option-meta" x-text="row.phone"></span>
                            </li>
                        </template>
                        <li class="ui-combobox-empty" x-show="!customerRows.length && !customerBusy">No matching customer.</li>
                        <li class="ui-combobox-empty" x-show="customerBusy">Searching…</li>
                    </ul>
                    {{-- One baseline: the link, the separator and the button are all inline, so
                         the differing control types cannot sit at different heights. --}}
                    <p class="ui-sale-alt">
                        <a href="{{ route('customers.create') }}" class="ui-sale-alt-link">
                            <span class="ui-sale-alt-plus" aria-hidden="true">+</span>Add new customer
                        </a>
                        <span class="ui-sale-alt-or">or</span>
                        <button type="button" class="ui-sale-alt-action" x-on:click="chooseWalkIn">Walk-in customer</button>
                    </p>
                </div>
                @error('customer_id')<p class="inventra-field-error">{{ $message }}</p>@enderror
                @error('is_walk_in')<p class="inventra-field-error">{{ $message }}</p>@enderror
            </section>

            {{-- ── Items ───────────────────────────────────────────────────────────────────── --}}
            <section class="ui-sale-panel-section">
                <h2 class="ui-sale-section-label">Items</h2>
                <div class="ui-sale-table" role="table" aria-label="Sale items">
                    <div class="ui-sale-row ui-sale-head" role="row">
                        <span role="columnheader">Product</span>
                        <span role="columnheader">Qty</span>
                        <span role="columnheader">Unit price</span>
                        <span role="columnheader">Total</span>
                        <span role="columnheader"><span class="ui-visually-hidden-until-focus">Actions</span></span>
                    </div>
                    <template x-for="(line, index) in lines" x-bind:key="line.key">
                        <div class="ui-sale-row" role="row">
                            <div class="ui-sale-cell ui-sale-cell-product" role="cell">
                                <button type="button" class="ui-sale-product-button" x-on:click="openProductPicker(index)">
                                    <span x-show="line.product" x-text="line.product ? line.product.name : ''" class="ui-sale-product-name"></span>
                                    <span x-show="line.product" x-text="line.product ? line.product.sku : ''" class="ui-sale-product-sku"></span>
                                    <span x-show="!line.product" class="ui-sale-product-empty">Add product..</span>
                                </button>
                                <p class="ui-sale-stock-warning" x-cloak x-show="overStock(line)" role="status"
                                   x-text="line.product ? ('Only ' + line.product.stock + ' ' + line.product.name + ' in stock!') : ''"></p>
                            </div>
                            <div class="ui-sale-cell" role="cell" data-label="Qty">
                                <div class="ui-stepper ui-sale-stepper">
                                    <button type="button" x-on:click="step(index, -1)" x-bind:aria-label="'Decrease quantity on line ' + (index + 1)">&minus;</button>
                                    <input type="number" min="1" step="1" inputmode="numeric" x-model="line.quantity"
                                           x-on:input="quantityChanged" x-bind:aria-label="'Quantity on line ' + (index + 1)">
                                    <button type="button" x-on:click="step(index, 1)" x-bind:aria-label="'Increase quantity on line ' + (index + 1)">+</button>
                                </div>
                            </div>
                            <div class="ui-sale-cell ui-money" role="cell" data-label="Unit price" x-text="line.product ? money(kobo(line.product.price)) : '—'"></div>
                            <div class="ui-sale-cell ui-money ui-sale-line-total" role="cell" data-label="Total" x-text="line.product ? money(lineTotal(line)) : '—'"></div>
                            <div class="ui-sale-cell ui-sale-cell-actions" role="cell">
                                <button type="button" class="ui-icon-action" x-on:click="removeLine(index)"
                                        x-bind:aria-label="'Remove line ' + (index + 1)" data-tooltip="Remove line">&times;</button>
                            </div>
                        </div>
                    </template>
                </div>
                <button type="button" class="ui-button ui-sale-add-line" x-on:click="addLine">+ Add another item</button>
                @error('products')<p class="inventra-field-error">{{ $message }}</p>@enderror
                @error('products.*')<p class="inventra-field-error">{{ $message }}</p>@enderror
                @error('products.*.quantity')<p class="inventra-field-error">{{ $message }}</p>@enderror
            </section>

            {{-- ── Payment status, sale date and total ─────────────────────────────────────── --}}
            <section class="ui-sale-panel-section ui-sale-payment">
                <div class="ui-sale-payment-main">
                    <h2 class="ui-sale-field-heading" id="payment-status-label">Payment status</h2>

                    {{-- Three real buttons filling the row. The chosen one is solid blue, as in the
                         design; `aria-pressed` carries the same state for assistive technology. --}}
                    <div class="ui-segment" role="group" aria-labelledby="payment-status-label">
                        @foreach(['paid' => 'Paid', 'partial' => 'Partial', 'unpaid' => 'Unpaid'] as $value => $label)
                            <button
                                type="button"
                                class="ui-segment-option"
                                x-bind:class="paymentChoice === '{{ $value }}' ? 'is-active' : ''"
                                x-bind:aria-pressed="paymentChoice === '{{ $value }}'"
                                x-on:click="choosePayment('{{ $value }}')"
                            >{{ $label }}</button>
                        @endforeach
                    </div>

                    <div class="ui-sale-amount">
                        <label class="ui-sale-field-heading" for="amount_paid">Amount paid (&#8358;)</label>
                        <input id="amount_paid" name="amount_paid" class="inventra-input" inputmode="decimal"
                               placeholder="Add amount paid.."
                               x-model="amountInput" x-bind:readonly="paymentChoice !== 'partial'">
                        @error('amount_paid')<p class="inventra-field-error">{{ $message }}</p>@enderror

                        {{-- Wording follows the chosen state, so an unpaid sale never reads as if
                             money was taken. --}}
                        <p class="ui-sale-balance is-paid" x-cloak x-show="paymentChoice === 'paid' && !overpaying" role="status">
                            Paid in full · <span x-text="money(totalKobo)"></span>
                        </p>
                        <p class="ui-sale-balance is-partial" x-cloak x-show="paymentChoice === 'partial' && !overpaying" role="status">
                            Balance: <span x-text="money(balanceKobo)"></span>
                        </p>
                        <p class="ui-sale-balance is-unpaid" x-cloak x-show="paymentChoice === 'unpaid'" role="status">
                            Balance owed · <span x-text="money(totalKobo)"></span>
                        </p>
                        <p class="inventra-field-error" x-cloak x-show="overpaying">Amount paid cannot be more than the sale total.</p>
                    </div>

                </div>

                <div class="ui-sale-payment-side">
                    <div>
                        <label class="ui-sale-field-heading" for="sale_date">Sale date</label>
                        <input id="sale_date" name="sale_date" type="date" class="inventra-input"
                               max="{{ $today }}" x-model="saleDate" x-on:change="dateChanged" required>
                        <p class="ui-sale-date-readout" x-cloak x-show="saleDate" x-text="saleDateLabel"></p>
                        <p class="inventra-field-error" x-cloak x-show="futureDate">A sale cannot be dated in the future.</p>
                        @error('sale_date')<p class="inventra-field-error">{{ $message }}</p>@enderror
                    </div>

                    {{-- The one figure the operator commits to. Subtotal is not shown here: with no
                         discount it merely repeats this line, and when a discount is approved the
                         cart modal is where the breakdown belongs. --}}
                    <div class="ui-sale-total-box">
                        <span class="ui-sale-total-label">Total</span>
                        <span class="ui-sale-total-value ui-money" x-text="money(totalKobo)"></span>
                    </div>
                    <p class="ui-sale-total-discount" x-cloak x-show="discountKobo > 0">
                        Includes an approved discount of <span x-text="money(discountKobo)"></span>
                    </p>
                </div>
            </section>

            {{-- ── Footer actions ──────────────────────────────────────────────────────────── --}}
            <div class="ui-sale-footer">
                <div class="ui-sale-footer-start">
                    @if($canRequestDiscount)
                        <button type="button" class="ui-button" x-ref="discountTrigger" x-on:click="openDiscount"
                                x-bind:disabled="!ready || waiting">Request Discount</button>
                    @endif
                </div>
                <div class="ui-sale-footer-end">
                    <a href="{{ route('sales.index') }}" class="ui-button">Cancel</a>
                    <button type="submit" class="inventra-primary-action ui-sale-submit" x-bind:disabled="!ready || submitting">
                        <span x-show="!submitting" class="ui-sale-submit-label">
                            <span class="ui-sale-tick" aria-hidden="true">&check;</span> Record Sale
                        </span>
                        <span x-cloak x-show="submitting" class="ui-sale-spinner-label">
                            <span class="ui-spinner" aria-hidden="true"></span> Recording sale…
                        </span>
                    </button>
                </div>
            </div>
            <p class="ui-sale-notice is-stale" x-cloak x-show="staleNotice" role="status" x-text="staleNotice"></p>
            <p class="inventra-field-error" x-cloak x-show="formError" x-text="formError"></p>
        </form>

        @include('sales._resume-modal')
        @include('sales._product-picker')
        @include('sales._discount-modal')
        @include('sales._waiting-modal')
        @include('sales._cart-modal')
    </div>

    @if($completed)
        @include('sales._complete-modal', ['completed' => $completed])
    @endif
</x-app-layout>
