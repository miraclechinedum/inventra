{{--
    The customer profile.

    A header, three figures, what they have bought and what has been sent to them.

    Everything here is real. The three figures are completed sales only — the same scope the
    Customer Performance report uses — and the communication panel reads actual WhatsApp delivery
    records, so a customer who has never been messaged is shown as exactly that rather than given
    invented history.

    The "WhatsApp opted-in" badge tracks consent alone. A customer with a perfectly good WhatsApp
    number who never agreed to be messaged does not get it.
--}}
<x-app-layout title="{{ $customer->full_name }}">
    <div class="cust">
        {{-- ── Header ────────────────────────────────────────────────────────────────────────── --}}
        <section class="cust-card cust-profile-head">
            <div class="cust-identity">
                <x-customer-avatar :customer="$customer" size="lg" />
                <div class="cust-identity-body">
                    <h1>
                        {{ $customer->full_name }}
                        @if($customer->whatsapp_opt_in)
                            <span class="cust-badge is-optin">
                                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.9"
                                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.5 8.5 0 0 1-3.9-.9L3 20l1.1-4.9A8.4 8.4 0 1 1 21 11.5z"/></svg>
                                WhatsApp opted-in
                            </span>
                        @endif
                        @unless($customer->is_active)
                            <span class="cust-badge is-archived">Archived</span>
                        @endunless
                    </h1>
                    <p class="cust-identity-meta">
                        <span>
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7"
                                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.2a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/></svg>
                            {{ $customer->phone }}
                        </span>
                        {{-- Shown only when WhatsApp is on a different line; otherwise the phone
                             above already says where a message would go. --}}
                        @unless($customer->whatsAppUsesPhone())
                            <span>
                                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7"
                                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.5 8.5 0 0 1-3.9-.9L3 20l1.1-4.9A8.4 8.4 0 1 1 21 11.5z"/></svg>
                                {{ $customer->whatsapp_phone }}
                            </span>
                        @endunless
                        @if($customer->email)
                            <span>
                                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7"
                                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/></svg>
                                {{ $customer->email }}
                            </span>
                        @endif
                        @if($customer->tag)
                            <span>
                                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7"
                                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h9l6 7-6 7H5a2 2 0 0 1-2-2z"/></svg>
                                {{ $customer->tag }}
                            </span>
                        @endif
                    </p>
                </div>
            </div>

            <div class="cust-profile-actions">
                @can('update', $customer)
                    <a href="{{ route('customers.edit', $customer) }}" class="cust-button">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.9"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                        Edit
                    </a>
                @endcan
                {{-- The existing Record Sale flow, with this customer carried in so the operator
                     does not have to find them again. The sale form still validates the id. --}}
                @can('create', \App\Models\Sale::class)
                    <a href="{{ route('sales.create', ['customer' => $customer->id]) }}" class="inventra-primary-action">
                        <img src="{{ asset('images/figma/icon-plus.svg') }}" alt="">Record Sale
                    </a>
                @endcan
            </div>
        </section>

        {{-- ── Figures ───────────────────────────────────────────────────────────────────────
             Completed sales only. A voided sale is withdrawn and never counts toward what someone
             has spent; `sale_date` is the trading day, as everywhere else. --}}
        <div class="cust-kpis">
            <div class="cust-kpi">
                <span class="cust-kpi-head">
                    <span class="cust-kpi-icon is-blue" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"
                             stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/></svg>
                    </span>
                    Total purchases
                </span>
                <p class="cust-kpi-value">{{ number_format($stats['purchases']) }}</p>
            </div>

            <div class="cust-kpi">
                <span class="cust-kpi-head">
                    <span class="cust-kpi-icon is-green" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"
                             stroke-linecap="round" stroke-linejoin="round"><path d="M20 12V8H6a2 2 0 0 1 0-4h12v4"/><path d="M4 6v12a2 2 0 0 0 2 2h14v-4"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/></svg>
                    </span>
                    Total spent
                </span>
                <p class="cust-kpi-value">&#8358;{{ \App\Support\Money::compact($stats['spent']) }}</p>
            </div>

            <div class="cust-kpi">
                <span class="cust-kpi-head">
                    <span class="cust-kpi-icon is-amber" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"
                             stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                    </span>
                    Last purchase
                </span>
                <p class="cust-kpi-value">
                    @if($stats['last_purchase'])
                        {{ \Carbon\CarbonImmutable::parse($stats['last_purchase'])->format('j M Y') }}
                    @else
                        &mdash;
                    @endif
                </p>
            </div>
        </div>

        {{-- ── Balances and payments ─────────────────────────────────────────────────────────
             Outstanding is the Receivables report's own scope narrowed to this customer: completed
             sales with a balance still due. Payments are those taken on completed sales. A Sales
             Representative sees only the sales they recorded, in both. --}}
        <section class="cust-card cust-balances" aria-labelledby="cust-balances-title">
            <h2 class="cust-card-title" id="cust-balances-title">Balances &amp; payments</h2>

            <dl class="cust-balance-figures">
                <div>
                    <dt>Outstanding balance</dt>
                    <dd @class(['cust-balance-due' => $receivables['open_count'] > 0])>&#8358;{{ \App\Support\Money::format($receivables['outstanding']) }}</dd>
                </div>
                <div>
                    <dt>Open sales</dt>
                    <dd>{{ number_format($receivables['open_count']) }}</dd>
                </div>
                <div>
                    <dt>Unpaid</dt>
                    <dd>{{ number_format($receivables['unpaid_count']) }}</dd>
                </div>
                <div>
                    <dt>Part-paid</dt>
                    <dd>{{ number_format($receivables['partial_count']) }}</dd>
                </div>
            </dl>

            <h3 class="cust-subtitle">Unpaid and part-paid sales</h3>
            @if($receivables['sales']->isNotEmpty())
                <div class="cust-table-scroll">
                    <table class="cust-table">
                        <thead>
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Sale</th>
                                <th scope="col" class="cust-numeric">Total</th>
                                <th scope="col" class="cust-numeric">Paid</th>
                                <th scope="col" class="cust-numeric">Balance</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($receivables['sales'] as $open)
                                <tr>
                                    <td class="cust-muted">{{ $open->sale_date->format('j M Y') }}</td>
                                    <td><a href="{{ route('sales.show', $open) }}" class="cust-history-link">{{ $open->sale_number }}</a></td>
                                    <td class="cust-numeric">&#8358;{{ \App\Support\Money::format((string) $open->total_amount) }}</td>
                                    <td class="cust-numeric">&#8358;{{ \App\Support\Money::format((string) $open->amount_paid) }}</td>
                                    <td class="cust-numeric cust-balance-due">&#8358;{{ \App\Support\Money::format((string) $open->balance_due) }}</td>
                                    <td>
                                        <span class="cust-pill {{ $open->payment_status === \App\Enums\PaymentStatus::Unpaid ? 'is-bad' : 'is-pending' }}">
                                            <span class="cust-dot" aria-hidden="true"></span>{{ $open->payment_status === \App\Enums\PaymentStatus::Unpaid ? 'Unpaid' : 'Part-paid' }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($receivables['open_count'] > $receivables['sales']->count())
                    <p class="cust-panel-foot">
                        Showing the {{ $receivables['sales']->count() }} oldest of {{ number_format($receivables['open_count']) }} open sales.
                    </p>
                @endif
            @else
                <p class="cust-muted cust-panel-note">Nothing is owed on this customer's sales.</p>
            @endif

            <h3 class="cust-subtitle">Payment history</h3>
            @if($payments->isNotEmpty())
                <div class="cust-table-scroll">
                    <table class="cust-table">
                        <thead>
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Payment</th>
                                <th scope="col">Sale</th>
                                <th scope="col">Method</th>
                                <th scope="col" class="cust-numeric">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($payments as $payment)
                                <tr>
                                    <td class="cust-muted">{{ $payment->paid_at?->timezone(config('business.timezone'))->format('j M Y') }}</td>
                                    <td>{{ $payment->payment_number }}</td>
                                    <td><a href="{{ route('sales.show', $payment->sale) }}" class="cust-history-link">{{ $payment->sale->sale_number }}</a></td>
                                    <td>{{ $payment->payment_method->label() }}</td>
                                    <td class="cust-numeric cust-spent">&#8358;{{ \App\Support\Money::format((string) $payment->amount) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($paymentCount > $payments->count())
                    <p class="cust-panel-foot">
                        Showing the {{ $payments->count() }} most recent of {{ number_format($paymentCount) }} payments.
                    </p>
                @endif
            @else
                <p class="cust-muted cust-panel-note">No payments recorded yet.</p>
            @endif
        </section>

        {{-- ── Internal details ──────────────────────────────────────────────────────────────
             Management only, by CustomerPolicy@viewInternalDetails. Escaped like everything else;
             line breaks are kept by CSS, never by rendering the note as HTML. --}}
        @if($canViewInternalDetails)
            <section class="cust-card cust-internal-card" aria-labelledby="cust-internal-title">
                <h2 class="cust-card-title" id="cust-internal-title">Internal details</h2>
                <dl class="cust-internal-list">
                    <div>
                        <dt>Address</dt>
                        <dd>@if(filled($customer->address)){{ $customer->address }}@else<span class="cust-muted">No address saved.</span>@endif</dd>
                    </div>
                    <div>
                        <dt>Internal notes</dt>
                        <dd>@if(filled($customer->notes)){{ $customer->notes }}@else<span class="cust-muted">No internal notes.</span>@endif</dd>
                    </div>
                </dl>
            </section>
        @endif

        <div class="cust-panels">
            {{-- ── Purchase history ──────────────────────────────────────────────────────── --}}
            <section class="cust-card">
                <h2 class="cust-card-title">Purchase history</h2>

                @if($purchases->isNotEmpty())
                    <div class="cust-table-scroll">
                        <table class="cust-table cust-history">
                            <thead>
                                <tr>
                                    <th scope="col">Date</th>
                                    <th scope="col">Products</th>
                                    <th scope="col" class="cust-numeric">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($purchases as $sale)
                                    <tr>
                                        <td class="cust-muted">{{ $sale->sale_date->format('j M') }}</td>
                                        <td>
                                            {{-- The products as they were sold, from the line
                                                 snapshots, with the quantity only where more than
                                                 one was bought. --}}
                                            <a href="{{ route('sales.show', $sale) }}" class="cust-history-link">
                                                {{ $sale->items->map(fn ($item): string => $item->product_name_snapshot
                                                    .(bccomp((string) $item->quantity, '1', 3) > 0
                                                        ? ' ×'.\App\Support\Quantity::trim((string) $item->quantity)
                                                        : ''))->implode(', ') }}
                                            </a>
                                        </td>
                                        <td class="cust-numeric cust-spent">&#8358;{{ \App\Support\Money::compact((string) $sale->total_amount) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($stats['purchases'] > $purchases->count())
                        {{-- The profile shows a recent window, not the whole ledger. --}}
                        <p class="cust-panel-foot">
                            Showing the {{ $purchases->count() }} most recent of {{ number_format($stats['purchases']) }} purchases.
                        </p>
                    @endif
                @else
                    <div class="cust-empty is-inline">
                        <span class="cust-empty-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7"
                                 stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/></svg>
                        </span>
                        <h3>No purchases yet</h3>
                        <p>Sales recorded for this customer will appear here.</p>
                    </div>
                @endif
            </section>

            {{-- ── Communication history ─────────────────────────────────────────────────────
                 Real WhatsApp delivery records. Nothing is invented: no template names the
                 application does not store, no timestamps it did not record. --}}
            <section class="cust-card">
                <h2 class="cust-card-title">Communication history</h2>

                @if($messages->isNotEmpty())
                    <ul class="cust-messages">
                        @foreach($messages as $message)
                            @php($status = $message->status instanceof \App\Enums\WhatsAppDeliveryStatus
                                ? $message->status
                                : \App\Enums\WhatsAppDeliveryStatus::tryFrom((string) $message->status))
                            @php($tone = match ($status) {
                                \App\Enums\WhatsAppDeliveryStatus::Delivered,
                                \App\Enums\WhatsAppDeliveryStatus::Read => 'is-ok',
                                \App\Enums\WhatsAppDeliveryStatus::Failed,
                                \App\Enums\WhatsAppDeliveryStatus::Unresolved => 'is-bad',
                                default => 'is-pending',
                            })
                            <li class="cust-message">
                                <span class="cust-message-icon {{ $tone }}" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8"
                                         stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.5 8.5 0 0 1-3.9-.9L3 20l1.1-4.9A8.4 8.4 0 1 1 21 11.5z"/></svg>
                                </span>
                                <span class="cust-message-body">
                                    {{-- The sale a receipt belonged to is the only label the
                                         application actually records for a message. --}}
                                    <b>{{ $message->sale?->sale_number ? 'Receipt · '.$message->sale->sale_number : 'WhatsApp message' }}</b>
                                    <span class="cust-muted">
                                        {{ ($message->requested_at ?? $message->created_at)?->timezone(config('business.timezone'))->format('j M H:i') }}
                                    </span>
                                </span>
                                <span class="cust-pill {{ $tone }}">
                                    <span class="cust-dot" aria-hidden="true"></span>{{ $status?->name ?? 'Unknown' }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="cust-empty is-inline">
                        <span class="cust-empty-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7"
                                 stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.5 8.5 0 0 1-3.9-.9L3 20l1.1-4.9A8.4 8.4 0 1 1 21 11.5z"/><path d="m2 2 20 20"/></svg>
                        </span>
                        <h3>No messages sent yet</h3>
                        <p>No messages sent to this customer yet.</p>
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
