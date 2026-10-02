{{--
    Sale complete.

    A compact confirmation, not a receipt. The full document lives behind Print, PDF and the sale
    page; what belongs here is what the counter needs the moment a sale lands — that it worked, what
    was bought, what was paid, and whether the customer will get a copy.

    Every figure comes from ReceiptPresenter, the same source the receipt and the PDF read.

    The WhatsApp block reports; it never sends. CreateSale already queued an automatic receipt if the
    customer was eligible, so a green "Send" button here would risk a second message to a real
    person. Each state below says plainly what is true of this sale.
--}}
@php($receipt = $completed['receipt'])
@php($sale = $completed['sale'])
@php($state = $completed['whatsapp'])
<div
    class="ui-modal ui-sale-complete"
    x-data="saleComplete"
    data-done-url="{{ route('sales.index') }}"
    x-cloak
    x-show="open"
    x-on:keydown.escape.window="close"
    role="dialog"
    aria-modal="true"
    aria-labelledby="complete-modal-title"
>
    <div class="ui-modal-backdrop" x-on:click="close" aria-hidden="true"></div>
    {{-- Focus is moved and contained by the component; the CSP build has no x-trap plugin. --}}
    <div class="ui-modal-panel" x-ref="panel" x-on:keydown.tab="containFocus">
        <button type="button" class="ui-complete-close" x-on:click="close" aria-label="Close" data-tooltip="Close">
            <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>

        <div class="ui-modal-body ui-complete-body">
            <div class="ui-complete-head">
                <span class="ui-sale-check" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="3"
                         stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                </span>
                <h2 id="complete-modal-title">Sale complete</h2>
                <p class="ui-complete-lead">Receipt #{{ $sale->sale_number }} is ready</p>
            </div>

            @include('sales._complete-summary', $receipt)

            @if($state === 'queued')
                {{-- Already on its way. Reporting it is the whole point: an action here would be a
                     second delivery of the same receipt. --}}
                <p class="ui-complete-whatsapp is-queued">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.5 8.5 0 0 1-3.9-.9L3 20.5l1.6-4.9A8.4 8.4 0 0 1 3.7 11a8.4 8.4 0 0 1 8.4-8.4 8.4 8.4 0 0 1 8.9 8.9Z"/></svg>
                    Receipt queued for WhatsApp
                </p>
                @if($completed['customerName'])
                    <p class="ui-complete-consent">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>
                        {{ $completed['customerName'] }} opted in to WhatsApp messages
                    </p>
                @endif
            @elseif($state === 'available')
                {{-- Eligible, but nothing has been queued — the only case where a manual send is
                     safe. It happens on the sale page, which owns the send token. --}}
                <a href="{{ route('sales.show', $sale) }}" class="ui-complete-whatsapp-action">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.5 8.5 0 0 1-3.9-.9L3 20.5l1.6-4.9A8.4 8.4 0 0 1 3.7 11a8.4 8.4 0 0 1 8.4-8.4 8.4 8.4 0 0 1 8.9 8.9Z"/></svg>
                    Send to WhatsApp
                </a>
                @if($completed['customerName'])
                    <p class="ui-complete-consent">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>
                        {{ $completed['customerName'] }} opted in to WhatsApp messages
                    </p>
                @endif
            @else
                {{-- Walk-in, or a registered customer without consent. The buyer panel from the
                     Figma, then a disabled action and a plain explanation. --}}
                <div class="ui-complete-buyer">
                    <span class="ui-complete-buyer-body">
                        <span class="ui-complete-buyer-name">{{ $sale->customer_name_snapshot }}</span>
                        <span class="ui-complete-buyer-money">
                            &#8358;{{ \App\Support\Money::format($sale->total_amount) }} · {{ ucfirst($sale->payment_status->value) }}
                        </span>
                    </span>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="ui-complete-buyer-icon"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                </div>

                <span class="ui-complete-whatsapp is-disabled" aria-disabled="true">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.5 8.5 0 0 1-3.9-.9L3 20.5l1.6-4.9A8.4 8.4 0 0 1 3.7 11a8.4 8.4 0 0 1 8.4-8.4 8.4 8.4 0 0 1 8.9 8.9Z"/><path d="m3 3 18 18"/></svg>
                    Send to WhatsApp
                </span>

                <p class="ui-complete-note">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1"/></svg>
                    <span>
                        @if($state === 'walk_in')
                            {{-- Deliberately not offering "Add customer & consent". A recorded Sale's
                                 buyer identity is immutable — CorrectSale refuses to give a walk-in a
                                 customer, and the database CHECK forbids it — so that button could not
                                 do what it says. Saying so plainly beats a control that would fail. --}}
                            This was a walk-in sale, so there is no customer number to send to. Print or
                            download the receipt instead. To send future receipts, record the sale
                            against a registered customer who has opted in.
                        @else
                            This customer hasn&rsquo;t opted in to WhatsApp. Add a number and consent on
                            their customer record to enable sending.
                        @endif
                    </span>
                </p>

                @if($state === 'not_eligible' && $sale->customer_id !== null)
                    <a href="{{ route('customers.edit', $sale->customer_id) }}" class="ui-complete-secondary-link">
                        Update customer &amp; consent
                    </a>
                @endif
            @endif

            <div class="ui-complete-actions">
                <button type="button" class="ui-complete-action" data-print-trigger>
                    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
                    Print
                </button>
                <a href="{{ route('sales.receipt.pdf', $sale) }}" class="ui-complete-action">
                    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/></svg>
                    PDF
                </a>
            </div>
        </div>
    </div>
</div>
