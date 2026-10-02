{{--
    WhatsApp Automation.

    Every piece of state on this page is server-rendered from the database: the connection pill
    reads the real connection row, each toggle reads its automation's persisted `enabled`, each
    template badge derives from that same column, and the log is a real query. Nothing here is
    decorative — there is no state that the page invents for itself.

    All classes are `wa-` prefixed and scoped under `.wa-module`, so none of this styling can reach
    Sales, Staff, Customers or Inventory.
--}}
<x-app-layout title="WhatsApp automation">
    <div class="wa-module" x-data="whatsappAutomation">
        <div class="wa-head">
            <h1>WhatsApp automation</h1>
            @if ($connected)
                <span class="wa-pill is-connected">
                    <span class="wa-dot" aria-hidden="true"></span>Business number connected
                </span>
                {{-- Both act on this Business's own connection only; the server derives which. --}}
                <form method="POST" action="{{ route('whatsapp.automation.templates.sync') }}">
                    @csrf
                    <button type="submit" class="wa-button">Refresh templates</button>
                </form>
                <form method="POST" action="{{ route('whatsapp.automation.connection.disconnect') }}"
                      x-on:submit="if (! confirm('Disconnect WhatsApp? Messages that have not been sent yet will be cancelled.')) $event.preventDefault()">
                    @csrf
                    <button type="submit" class="wa-button">Disconnect</button>
                </form>
            @else
                {{-- The page's single connection action. `inventra-primary-action` is the same
                     primary token every other Inventra screen uses, so nothing about the blue,
                     the radius or the hover state is redefined here. It opens the existing
                     connection modal at step 1 — no second flow, no duplicated state. --}}
                <button type="button" class="inventra-primary-action" x-on:click="openConnect">
                    Connect business number
                </button>
            @endif
        </div>

        @if (session('status'))
            <p class="wa-info" role="status">{{ session('status') }}</p>
        @endif

        <div class="wa-grid">
            {{-- ── Automations ──────────────────────────────────────────────────────────────── --}}
            <section class="wa-card">
                <header class="wa-card-head"><h2>Automations</h2></header>

                @if (! $connected)
                    @include('whatsapp.automation._empty')
                @else
                    <div class="wa-rows">
                        @foreach ($automations as $automation)
                            <div class="wa-row">
                                <span class="wa-row-icon is-{{ $automation->key }}" aria-hidden="true">
                                    @include('whatsapp.automation._icon', ['key' => $automation->key])
                                </span>
                                <span class="wa-row-body">
                                    <button type="button" class="wa-row-title" x-on:click="openEditor({{ $automation->id }})">
                                        {{ $automation->title() }}
                                    </button>
                                    <span class="wa-row-note">{{ $automation->description() }}</span>
                                </span>

                                @if ($automation->key === \App\Models\WhatsAppAutomation::PICKUP_REMINDER)
                                    {{-- Timing lives with the automation it belongs to, and is saved
                                         through the same endpoint as the template body. --}}
                                    <select class="wa-timing" aria-label="Pickup reminder timing"
                                            x-on:change="saveTiming({{ $automation->id }}, $event.target.value)">
                                        @foreach ([0 => 'Immediately', 4 => '4 hours after', 24 => '1 day after', 48 => '2 days after', 72 => '3 days after'] as $hours => $label)
                                            <option value="{{ $hours }}" @selected((int) $automation->delay_hours === $hours)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @endif

                                {{-- A real checkbox, so it is keyboard-operable and announced with
                                     its own name rather than being a styled div. --}}
                                <label class="wa-switch">
                                    <input type="checkbox" @checked($automation->enabled)
                                           x-on:change="toggle({{ $automation->id }}, $event.target)"
                                           aria-label="{{ $automation->title() }}">
                                    <span class="wa-switch-track" aria-hidden="true"><span class="wa-switch-thumb"></span></span>
                                </label>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            {{-- ── Message templates ────────────────────────────────────────────────────────── --}}
            <section class="wa-card">
                <header class="wa-card-head"><h2>Message templates</h2></header>

                @if (! $connected)
                    @include('whatsapp.automation._empty')
                @else
                    <div class="wa-templates">
                        @foreach ($automations as $automation)
                            <div class="wa-template">
                                <span class="wa-template-name">{{ $automation->templateLabel() }}</span>
                                {{-- Derived from the automation's own `enabled`, never stored twice,
                                     so the badge cannot disagree with the switch beside it. Status
                                     is carried by the word as well as the colour. --}}
                                <span class="wa-badge {{ $automation->enabled ? 'is-active' : 'is-inactive' }}">
                                    <span class="wa-dot" aria-hidden="true"></span>{{ $automation->enabled ? 'Active' : 'Inactive' }}
                                </span>
                                <span class="wa-template-actions">
                                    <button type="button" x-on:click="openEditor({{ $automation->id }})">Edit</button>
                                    <button type="button" x-on:click="openPreview({{ $automation->id }})">Preview</button>
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>

        {{-- ── Message logs ─────────────────────────────────────────────────────────────────── --}}
        <section class="wa-card wa-logs">
            <header class="wa-card-head">
                <h2>Message logs</h2>
                @if ($connected)
                    {{-- Server-side filtering: a plain GET form, so the filtered view is a real URL
                         that can be linked, reloaded and paginated. --}}
                    <form method="GET" class="wa-filters">
                        <select name="type" aria-label="Filter by type" onchange="this.form.submit()">
                            <option value="">Type</option>
                            @foreach ([
                                \App\Models\WhatsAppAutomation::WELCOME => 'Welcome',
                                \App\Models\WhatsAppAutomation::POST_PURCHASE => 'Post-purchase',
                                \App\Models\WhatsAppAutomation::PICKUP_REMINDER => 'Pickup reminder',
                                \App\Models\WhatsAppAutomation::LOW_STOCK => 'Low-stock',
                                'test' => 'Test',
                            ] as $value => $label)
                                <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <select name="status" aria-label="Filter by status" onchange="this.form.submit()">
                            <option value="">Status</option>
                            @foreach (['queued' => 'Queued', 'sent' => 'Sent', 'delivered' => 'Delivered', 'read' => 'Read', 'failed' => 'Failed'] as $value => $label)
                                <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </form>
                @endif
            </header>

            @if (! $connected)
                @include('whatsapp.automation._empty')
            @else
                <div class="wa-table-wrap">
                    <table class="wa-table">
                        <thead>
                            <tr>
                                <th>Sent</th><th>Customer</th><th>Type</th><th>Status</th><th class="wa-right">By</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($messages as $message)
                                <tr>
                                    <td>{{ ($message->sent_at ?? $message->queued_at)->timezone(config('business.timezone'))->format('j M H:i') }}</td>
                                    <td>{{ $message->recipient_name }}</td>
                                    <td>{{ $message->typeLabel() }}</td>
                                    <td>
                                        <span class="wa-status is-{{ $message->status }}">
                                            <span class="wa-dot" aria-hidden="true"></span>{{ $message->statusLabel() }}
                                        </span>
                                        @if ($message->isRetryable())
                                            {{-- A real POST with CSRF, not a link: retrying sends a
                                                 message to a real person. --}}
                                            <form method="POST" action="{{ route('whatsapp.messages.retry', $message) }}"
                                                  class="wa-retry-form" data-submit-once>
                                                @csrf
                                                <button type="submit" class="wa-retry">
                                                    <svg viewBox="0 0 16 16" width="12" height="12" fill="none" aria-hidden="true"><path d="M13.5 8a5.5 5.5 0 1 1-1.9-4.2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><path d="M13.5 2.5V6H10" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                    Retry
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                    <td class="wa-right">{{ $message->originLabel() }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5">
                                        <div class="wa-empty">
                                            <span class="wa-empty-icon" aria-hidden="true">@include('whatsapp.automation._muted-icon')</span>
                                            <p class="wa-empty-title">No messages yet</p>
                                            <p class="wa-empty-note">Messages appear here once an automation sends one.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($messages->hasPages())
                    <div class="wa-pagination">{{ $messages->links() }}</div>
                @endif
            @endif
        </section>

        @include('whatsapp.automation._connect-modal')
        @include('whatsapp.automation._editor-modal')
    </div>

    {{-- The automations' saved state, handed to Alpine as escaped JSON in a script tag rather
         than interpolated into attributes, so nothing a template body contains can break out into
         markup or script. The payload is built in a PHP block first because Blade cannot parse a
         multi-line closure inside a directive's arguments. --}}
    @php
        $automationPayload = $automations->map(fn ($a) => [
            'id' => $a->id,
            'key' => $a->key,
            'title' => $a->title(),
            'trigger' => $a->triggerDescription(),
            'body' => $a->body,
            'enabled' => $a->enabled,
            'delayHours' => $a->delay_hours,
            'chips' => $a->chips(),
            'staff' => $a->sendsToStaff(),
            'recipients' => $a->recipients->pluck('id'),
            'templateName' => $a->template_name,
            'templateLanguage' => $a->template_language,
            'templateStatus' => $a->templateStatusLabel(),
            'templateApproved' => $a->templateIsApproved(),
        ])->values();
        $recipientPayload = $recipientOptions->map(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'phone' => $u->phone,
        ])->values();
    @endphp
    <script type="application/json" id="wa-automations">@json($automationPayload)</script>
    <script type="application/json" id="wa-samples">@json($sampleValues)</script>
    <script type="application/json" id="wa-recipient-options">@json($recipientPayload)</script>
</x-app-layout>
