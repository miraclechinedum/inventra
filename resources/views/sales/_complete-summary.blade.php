{{--
    The compact sale summary shown on the completion modal.

    Deliberately not `_receipt-body`: a receipt is a document — letterhead, address, customer code,
    payment method, seller, footer — and that belongs to Print, the PDF and the sale page. What the
    person at the counter needs the moment a sale lands is narrower: what was bought, what came off,
    and what was paid. Same data, different job, so this is its own partial.

    Every figure still comes from ReceiptPresenter, so this cannot disagree with the receipt or the
    ledger. Nothing here is computed.
--}}
@php($business = app(\App\Settings\BusinessSettings::class)->current())
<div class="ui-complete-card">
    <p class="ui-complete-business">
        {{-- Business Settings carries no logo, so the Inventra mark stands in for one rather than
             inventing a brand asset. The name is the identity that matters here. --}}
        <img src="{{ asset('images/figma/auth-logo-mark.png') }}" alt="" width="20" height="20" aria-hidden="true">
        <span>{{ $business->business_name }}</span>
    </p>

    <ul class="ui-complete-items">
        @foreach($items as $item)
            <li>
                <span class="ui-complete-item-name">
                    {{ $item->product_name_snapshot }} &times;{{ \App\Support\Quantity::trim($item->quantity) }}
                </span>
                <span class="ui-complete-item-amount">&#8358;{{ \App\Support\Money::format($item->line_total) }}</span>
            </li>
        @endforeach
    </ul>

    {{-- Only a discount the finalized Sale actually carries. A request that was declined, or one
         still pending, never reaches a recorded Sale's `discount_amount`, so there is nothing to
         guard against here beyond not drawing an empty row. --}}
    @if($hasDiscount)
        <p class="ui-complete-discount">
            <span>Discount</span>
            <span>&minus; &#8358;{{ \App\Support\Money::format($discount) }}</span>
        </p>
    @endif

    <dl class="ui-complete-totals">
        @foreach($closingLines as [$label, $amount, $strong])
            <div @class(['is-strong' => $strong])>
                <dt>{{ $label }}</dt>
                <dd>&#8358;{{ \App\Support\Money::format($amount) }}</dd>
            </div>
        @endforeach
    </dl>
</div>
