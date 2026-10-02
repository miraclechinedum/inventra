{{--
    The Return form, as the Sales-list side panel renders it.

    A fragment: no layout, no <html>. It is fetched and injected into the panel, so anything outside
    this form would be discarded by the fragment parser anyway.

    It posts to `sales.returns.store` — the same endpoint the full Record Return page posts to,
    carrying the same `request_token`. Nothing here computes money that matters: the figures shown
    are a preview drawn from the Sale's own settled columns, and RecordSaleReturn re-reads every one
    of them under a row lock before it writes. The browser proposes; the server settles.

    Alpine state lives in `returnPanel`, seeded from the lines this controller prepared, so no
    pricing or quantity arithmetic is expressed in an attribute the CSP-safe build could not run.
--}}
<form method="POST" action="{{ route('sales.returns.store', $sale) }}"
      class="ui-return-form" data-return-panel-form
      x-data="returnPanel"
      data-lines="{{ json_encode($lines) }}"
      data-preview-url="{{ route('sales.returns.preview', $sale) }}"
      x-on:submit="submitting = true">
    @csrf

    {{-- The idempotency token. RecordSaleReturn matches it to this Sale, this actor and this
         session, and refuses a second use with different details — so a double submission cannot
         return the same units twice. --}}
    <input type="hidden" name="request_token" value="{{ $requestToken }}">

    {{-- Where to land once the return is recorded. The panel asks for the Sales list, which redraws
         every row from the database, so the new settlement position is read back rather than
         guessed at. Compared against a literal server-side; never used as a redirect target. --}}
    <input type="hidden" name="return_to" value="index">

    <div class="ui-return-scroll">
        <p class="ui-return-heading">Select items to return</p>

        <ul class="ui-return-items" data-return-panel-items>
            @foreach($lines as $index => $line)
                <li @class(['ui-return-item', 'is-exhausted' => $line['exhausted']])>
                    <label class="ui-return-pick">
                        {{-- A real checkbox underneath: the tick is drawn with CSS, so keyboard
                             focus, space to toggle and screen readers all behave as standard. --}}
                        <input type="checkbox" class="ui-return-check"
                               value="{{ $line['id'] }}"
                               x-model="picked"
                               @disabled($line['exhausted'])
                               aria-describedby="return-meta-{{ $line['id'] }}">
                        <span class="ui-return-box" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor"
                                 stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 13 4 4L19 7"/></svg>
                        </span>
                        <span class="ui-return-item-body">
                            <span class="ui-return-item-name">{{ $line['name'] }}</span>
                            <span class="ui-return-item-meta" id="return-meta-{{ $line['id'] }}">
                                @if($line['exhausted'])
                                    Already returned in full
                                @else
                                    Bought {{ $line['sold'] }} &middot; &#8358;{{ $line['unitPrice'] }} each
                                    @if($line['returned'] !== '0')
                                        &middot; {{ $line['returned'] }} returned
                                    @endif
                                @endif
                            </span>
                        </span>
                    </label>

                    {{-- The stepper appears only for a chosen line. Its ceiling is this line's
                         remaining quantity, which the server re-derives and enforces regardless. --}}
                    <template x-if="isPicked({{ $line['id'] }})">
                        <span class="ui-return-stepper">
                            <button type="button" x-on:click="step({{ $line['id'] }}, -1)"
                                    x-bind:disabled="atMin({{ $line['id'] }})"
                                    aria-label="Reduce quantity for {{ $line['name'] }}">&minus;</button>
                            <span x-text="quantityOf({{ $line['id'] }})"></span>
                            <button type="button" x-on:click="step({{ $line['id'] }}, 1)"
                                    x-bind:disabled="atMax({{ $line['id'] }})"
                                    aria-label="Increase quantity for {{ $line['name'] }}">+</button>
                        </span>
                    </template>
                    <span class="ui-return-dash" x-show="!isPicked({{ $line['id'] }})" aria-hidden="true">&mdash;</span>

                    {{-- Submitted only for a chosen line, so the payload names exactly what the
                         operator picked. `disabled` inputs are not posted. --}}
                    <template x-if="isPicked({{ $line['id'] }})">
                        <span>
                            <input type="hidden" name="items[{{ $index }}][sale_item_id]" value="{{ $line['id'] }}">
                            <input type="hidden" name="items[{{ $index }}][quantity]" x-bind:value="quantityOf({{ $line['id'] }})">
                            <input type="hidden" name="items[{{ $index }}][disposition]" x-bind:value="disposition">
                        </span>
                    </template>
                </li>
            @endforeach
        </ul>

        <p class="ui-return-heading">Reason</p>
        {{-- `reason` is free text on the server (required, max 500), so these labels are stored
             verbatim rather than mapped onto an enum that does not exist. The field is a real input
             so the form still submits a reason without JavaScript. --}}
        <div class="ui-return-reasons" role="group" aria-label="Reason for the return">
            @foreach(['Faulty', 'Wrong item', 'Changed mind'] as $option)
                <button type="button" class="ui-return-reason"
                        x-bind:class="reason === '{{ $option }}' ? 'is-chosen' : ''"
                        x-bind:aria-pressed="reason === '{{ $option }}' ? 'true' : 'false'"
                        x-on:click="reason = '{{ $option }}'">{{ $option }}</button>
            @endforeach
        </div>
        <input type="hidden" name="reason" x-bind:value="reason">

        {{-- Whether the returned goods should go back on the shelf. Faulty stock usually should
             not, so this stays visible rather than being assumed. --}}
        <div class="ui-return-disposition">
            @foreach($dispositions as $option)
                <label>
                    <input type="radio" x-model="disposition" value="{{ $option->value }}">
                    <span>{{ $option->label() }}</span>
                </label>
            @endforeach
        </div>

        {{-- The preview, written by the server. It states the settlement in the terms the ledger
             will actually use: goods coming back cancel what is still owed first, and only money
             already taken can be refunded. A partly paid Sale therefore reads "reduce balance", not
             "Refund", and an effect worth nothing is not mentioned at all.

             `aria-live` so the sentence is announced when a quantity changes it, rather than
             changing silently under a screen reader. --}}
        <p class="ui-return-summary" x-show="clauses.length" aria-live="polite" data-return-panel-summary>
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 2v6h6"/><path d="M3 13a9 9 0 1 0 3-7.7L3 8"/></svg>
            {{-- Built from the server's labelled clauses: the label reads normally, the amount is
                 emphasised. `x-text` throughout, so nothing is ever rendered as markup. --}}
            <span>
                <template x-for="(clause, index) in clauses" x-bind:key="index">
                    <span
                        {{-- The separator is not merely hidden before the first clause: `x-show`
                             leaves the node in place, and its text still reads back as part of the
                             sentence. `x-if` keeps it out of the document entirely. --}}
                    ><template x-if="index > 0"><span aria-hidden="true"> · </span></template><span
                        x-text="clause.label"></span><template x-if="clause.label && clause.amount"><span> </span></template><b
                        x-text="clause.amount"></b></span>
                </template>
            </span>
        </p>

        <p class="ui-return-summary is-problem" x-show="summaryError" x-text="summaryError"></p>

        <p class="inventra-field-error ui-return-error" x-show="error" x-text="error"></p>
    </div>

    <div class="ui-return-actions" data-return-panel-footer>
        <button type="button" class="ui-button" x-on:click="$dispatch('return-cancelled')">Cancel</button>
        <button type="submit" class="inventra-primary-action ui-return-submit"
                x-bind:disabled="!valid || submitting"
                x-text="submitting ? 'Processing…' : 'Process return'"></button>
    </div>
</form>
