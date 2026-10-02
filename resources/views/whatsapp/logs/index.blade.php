{{--
    WhatsApp logs — read-only.

    Every figure and row comes from `whatsapp_messages`; nothing on this screen is illustrative. The
    design's sample names, numbers and counts are therefore absent by construction: with an empty
    log the table renders its empty state and the cards read zero.

    Retrying is Administrator-only and lives on its own authorised route. A Manager sees the state
    and the reason, never a control they would be refused — and the route does not exist for them
    either, so hiding the button is not what protects it.
--}}
@php($selectedProduct = $selected ? \App\Http\Controllers\WhatsAppLogController::lowStockProduct($selected) : null)

<x-app-layout title="WhatsApp logs">
    <div @class(['wl-page', 'has-panel' => $selected])>
        <div class="wl-main">
            {{-- ── Header ──────────────────────────────────────────────────────────────────── --}}
            <div class="wl-head">
                <h1>WhatsApp logs</h1>
                @unless ($canRetry)
                    {{-- States the ceiling of what this role can do here, rather than leaving a
                         Manager to discover it by pressing something that refuses them. --}}
                    <span class="wl-viewonly">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                        View only
                    </span>
                @endunless
            </div>

            {{-- ── Summary ─────────────────────────────────────────────────────────────────── --}}
            <section class="wl-cards" aria-label="Message summary">
                <article class="wl-card">
                    <p>Sent today</p>
                    <strong>{{ number_format($summary['sentToday']) }}</strong>
                </article>
                <article class="wl-card">
                    <p>Delivered</p>
                    <strong class="is-green">{{ number_format($summary['delivered']) }}</strong>
                </article>
                <article class="wl-card">
                    <p>Pending</p>
                    <strong class="is-amber">{{ number_format($summary['pending']) }}</strong>
                </article>
                <article class="wl-card">
                    <p>Failed</p>
                    <strong class="is-red">{{ number_format($summary['failed']) }}</strong>
                </article>
            </section>

            {{-- ── Filters ─────────────────────────────────────────────────────────────────── --}}
            {{-- A GET form: the filter state lives in the URL, so it survives pagination, a reload
                 and a shared link. Every value is allowlisted server-side before it reaches SQL. --}}
            <form method="GET" action="{{ route('whatsapp.logs.index') }}" class="wl-filters">
                <span class="wl-search">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <label class="wl-sr-only" for="wl-search">Search customer or number</label>
                    <input id="wl-search" type="search" name="search" placeholder="Search customer or number"
                           value="{{ $filters['search'] }}" maxlength="100">
                </span>

                <span class="wl-select has-icon">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4.5" width="18" height="17" rx="2"/><path d="M8 2.5v4M16 2.5v4M3 10h18"/></svg>
                    <label class="wl-sr-only" for="wl-range">Date range</label>
                    <select id="wl-range" name="range" data-table-control>
                        @foreach ($rangeOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters['range'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </span>

                <span class="wl-select">
                    <label class="wl-sr-only" for="wl-type">Message type</label>
                    <select id="wl-type" name="type" data-table-control>
                        <option value="">Type</option>
                        @foreach ($typeOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </span>

                <span @class(['wl-select', 'is-failed' => $filters['status'] === \App\Models\WhatsAppMessage::STATUS_FAILED])>
                    <label class="wl-sr-only" for="wl-status">Delivery status</label>
                    <select id="wl-status" name="status" data-table-control>
                        <option value="">Status</option>
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </span>

                {{-- The selects apply themselves on change through the shared data-table-control
                     listener, and the search submits on Enter, so the design needs no Apply button.
                     This one stays for the keyboard and for a browser without that listener: it is
                     reachable by Tab and visible on focus, never a dead control. --}}
                <button type="submit" class="ui-visually-hidden-until-focus">Apply filters</button>
            </form>

            {{-- ── Table ───────────────────────────────────────────────────────────────────── --}}
            <div class="wl-table-card">
                <div class="wl-table-wrap">
                    <table class="wl-table">
                        <thead>
                            <tr>
                                <th>Date &amp; time</th>
                                <th>Name</th>
                                <th>Recipient</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th><span class="wl-sr-only">Open</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($messages as $message)
                                @php($tone = match ($message->status) {
                                    \App\Models\WhatsAppMessage::STATUS_FAILED => 'failed',
                                    \App\Models\WhatsAppMessage::STATUS_DELIVERED, \App\Models\WhatsAppMessage::STATUS_READ => 'delivered',
                                    default => 'pending',
                                })
                                @php($label = match ($tone) {
                                    'failed' => 'Failed', 'delivered' => 'Delivered', default => 'Pending',
                                })
                                <tr @class(['is-failed' => $tone === 'failed', 'is-open' => $selected?->id === $message->id])>
                                    <td class="is-muted">{{ $message->created_at?->timezone(config('business.timezone'))->format('j M H:i') }}</td>
                                    <td class="wl-name">{{ $message->recipient_name }}</td>
                                    {{-- The full number stays in the DOM as the accessible name, so
                                         truncation is visual only. --}}
                                    <td class="is-muted wl-phone" title="{{ $message->destination_phone }}">{{ $message->destination_phone }}</td>
                                    <td class="is-muted">{{ $message->typeLabel() }}</td>
                                    <td>
                                        {{-- Dot AND word: the state never depends on colour alone. --}}
                                        <span class="wl-status is-{{ $tone }}"><span class="wl-dot" aria-hidden="true"></span>{{ $label }}</span>
                                    </td>
                                    <td class="wl-open-cell">
                                        <a href="{{ route('whatsapp.logs.index', array_merge(request()->query(), ['message' => $message->id])) }}#wl-detail"
                                           class="wl-open" aria-label="Open message to {{ $message->recipient_name }}">
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="wl-empty">
                                        <x-empty-state title="No WhatsApp messages match these filters."
                                            description="Messages appear here once an automation sends one." />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <x-table-footer :paginator="$messages" noun="message" />
            </div>

            {{-- Reinforces the ceiling below the table, where a failed row is actually read. --}}
            @unless ($canRetry)
                <p class="wl-helper">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/></svg>
                    <span>Failed messages can be retried by an Admin. Ask your admin to retry, or check the recipient&rsquo;s number.</span>
                </p>
            @endunless
        </div>

        {{-- ── Detail panel ────────────────────────────────────────────────────────────────── --}}
        @if ($selected)
            @php($tone = match ($selected->status) {
                \App\Models\WhatsAppMessage::STATUS_FAILED => 'failed',
                \App\Models\WhatsAppMessage::STATUS_DELIVERED, \App\Models\WhatsAppMessage::STATUS_READ => 'delivered',
                default => 'pending',
            })
            @php($label = match ($tone) { 'failed' => 'Failed', 'delivered' => 'Delivered', default => 'Pending' })
            @php($initials = \App\Support\Initials::from($selected->recipient_name))

            <aside class="wl-panel" id="wl-detail" aria-label="Message detail">
                <header class="wl-panel-head">
                    <h2>Message detail</h2>
                    <a href="{{ route('whatsapp.logs.index', \Illuminate\Support\Arr::except(request()->query(), ['message'])) }}"
                       class="wl-panel-close" aria-label="Close message detail">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </a>
                </header>

                <div class="wl-panel-body">
                    <p class="wl-panel-status">
                        <span class="wl-badge is-{{ $tone }}">{{ $label }}</span>
                        <span>{{ $selected->created_at?->timezone(config('business.timezone'))->format('j M Y · H:i') }}</span>
                    </p>

                    <section class="wl-section">
                        <p class="wl-section-label">Recipient</p>
                        <div class="wl-recipient">
                            <span class="wl-avatar" aria-hidden="true">{{ $initials }}</span>
                            <span class="wl-recipient-body">
                                <strong>{{ $selected->recipient_name }}</strong>
                                <small>{{ $selected->destination_phone }}</small>
                            </span>
                        </div>
                    </section>

                    <section class="wl-section">
                        <p class="wl-section-label">Message &middot; {{ $selected->typeLabel() }}</p>
                        {{-- The stored body, exactly as it was sent. Escaped by Blade; line breaks
                             preserved by CSS rather than by turning text into markup. --}}
                        <div class="wl-body">{{ $selected->body }}</div>
                    </section>

                    @if ($tone === 'failed')
                        <section class="wl-failure">
                            <p class="wl-failure-title">Why it failed</p>
                            {{-- The provider's own reason when it gave one, otherwise a plain
                                 statement. Never a code, a payload or a stack trace. --}}
                            <p class="wl-failure-body">{{ $selected->failure_reason ?: 'WhatsApp did not accept this message. The recipient may not be reachable on WhatsApp.' }}</p>
                        </section>

                        @if ($canRetry)
                            <form method="POST" action="{{ route('whatsapp.messages.retry', $selected) }}" data-submit-once>
                                @csrf
                                <button type="submit" class="wl-action is-primary" @disabled(! $selected->isRetryable())>
                                    Retry message
                                </button>
                            </form>
                        @else
                            {{-- No retry control at all for a Manager. The note explains the ceiling
                                 rather than offering an action that would be refused. --}}
                            <p class="wl-note">Only an Admin can retry a failed message.</p>
                        @endif
                    @endif

                    @if ($tone === 'delivered' && $selected->type === \App\Models\WhatsAppAutomation::LOW_STOCK)
                        <section class="wl-received">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/></svg>
                            <span class="wl-received-body">
                                <strong>Low-stock alert received</strong>
                                <small>
                                    @if ($selectedProduct)
                                        Low stock alert for {{ $selectedProduct->name }} was sent to {{ $selected->recipient_name }}.
                                    @else
                                        This alert was delivered to {{ $selected->recipient_name }}.
                                    @endif
                                </small>
                            </span>
                        </section>
                    @endif

                    @if ($selectedProduct)
                        {{-- The existing product page, addressed by its public id. No inventory
                             behaviour is reimplemented here. --}}
                        <a href="{{ route('inventory.products.show', $selectedProduct) }}" class="wl-action is-primary">
                            Preview low-stock
                        </a>
                    @endif
                </div>
            </aside>
        @endif
    </div>
</x-app-layout>
