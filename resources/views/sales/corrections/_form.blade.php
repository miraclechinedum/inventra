{{--
    The correction form, shared by the full page and the Sales-list side panel.

    One partial so the two cannot drift: the same fields, the same FormRequest, the same action and
    the same policy. `$inPanel` only changes the chrome around it — a slide-over has its own header
    and does not need the page's back-link — never which fields appear or what they may carry.

    Immutable fields are absent by construction, not hidden: CorrectSaleRequest prohibits
    amount_paid, payment_status, sale_number, sold_by, totals and discount outright, so nothing here
    could smuggle one through even if it tried.
--}}
@php($inPanel = $inPanel ?? false)

@unless($inPanel)
    <x-validation-errors />
@endunless

<form method="POST" action="{{ route('sales.corrections.store', $sale) }}" data-submit-once
      class="{{ $inPanel ? 'ui-correct-form' : 'mt-6 space-y-6' }}">
    @csrf

    {{-- Where to return once the correction lands. The panel asks to come back to the list it was
         opened from; the full page keeps its existing behaviour. --}}
    <input type="hidden" name="return_to" value="{{ $inPanel ? 'index' : 'show' }}">

    <div class="{{ $inPanel ? 'ui-correct-note' : 'mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-5' }}">
        <p class="{{ $inPanel ? 'ui-correct-note-title' : 'font-bold text-amber-950' }}">This is not how you record returned goods.</p>
        <p class="{{ $inPanel ? '' : 'mt-1 text-sm text-amber-900' }}">
            If the customer brought something back, use
            <a class="font-semibold underline" href="{{ route('sales.returns.create', $sale) }}">Record Return</a>
            instead. A return puts stock back and leaves this Sale intact as evidence of what was sold.
        </p>
    </div>

    <section class="{{ $inPanel ? 'ui-correct-section' : 'rounded-2xl border border-slate-200 bg-white p-6 shadow-sm' }}">
        <h3 class="{{ $inPanel ? 'ui-correct-heading' : 'font-bold' }}">Items as they should have been recorded</h3>
        <p class="{{ $inPanel ? 'ui-correct-hint' : 'mt-1 text-sm text-slate-600' }}">
            Set the correct quantity for each line, or 0 to remove it. Stock is adjusted by the
            difference and the totals are recalculated. Prices stay as originally sold.
        </p>
        <div class="{{ $inPanel ? 'ui-correct-lines' : 'mt-5 space-y-4' }}">
            @foreach($sale->items as $index => $item)
                <div class="{{ $inPanel ? 'ui-correct-line' : 'grid items-center gap-3 border-b border-slate-100 pb-4 md:grid-cols-[2fr_1fr_1fr]' }}">
                    <div>
                        <p class="{{ $inPanel ? 'ui-correct-line-name' : 'font-semibold' }}">{{ $item->product_name_snapshot }}</p>
                        <p class="{{ $inPanel ? 'ui-correct-line-meta' : 'font-mono text-xs text-slate-500' }}">
                            Recorded {{ \App\Support\Quantity::trim($item->quantity) }} {{ $item->unit_snapshot }}
                            @ &#8358;{{ \App\Support\Money::format($item->unit_price) }}
                        </p>
                        <input type="hidden" name="products[{{ $index }}][product_id]" value="{{ $item->product_id }}">
                    </div>
                    <label class="{{ $inPanel ? 'ui-correct-qty' : 'text-xs font-semibold text-slate-500' }}">
                        <span>Correct quantity</span>
                        <input name="products[{{ $index }}][quantity]" inputmode="decimal"
                               value="{{ \App\Support\OldInput::scalar('products.'.$index.'.quantity', \App\Support\Quantity::trim($item->quantity)) }}"
                               class="{{ $inPanel ? 'inventra-input' : 'mt-1 w-full rounded-xl border-slate-300' }}" required>
                    </label>
                    @error('products.'.$index.'.quantity')<p class="inventra-field-error">{{ $message }}</p>@enderror
                </div>
            @endforeach
        </div>
        @error('products')<p class="inventra-field-error">{{ $message }}</p>@enderror
    </section>

    <section class="{{ $inPanel ? 'ui-correct-section' : 'rounded-2xl border border-slate-200 bg-white p-6 shadow-sm' }}">
        <h3 class="{{ $inPanel ? 'ui-correct-heading' : 'font-bold' }}">Customer</h3>
        @if($canChangeCustomer)
            <p class="{{ $inPanel ? 'ui-correct-hint' : 'mt-1 text-sm text-slate-600' }}">Change this only if the Sale was recorded against the wrong person.</p>
            <select aria-label="Customer" name="customer_id" class="{{ $inPanel ? 'inventra-input' : 'mt-3 w-full rounded-xl border border-slate-300 px-4 py-3' }}">
                @foreach($customers as $customer)
                    <option value="{{ $customer->id }}" @selected((int) \App\Support\OldInput::scalar('customer_id', (string) $sale->customer_id) === $customer->id)>
                        {{ $customer->customer_code }} · {{ $customer->first_name }} {{ $customer->last_name }}
                    </option>
                @endforeach
            </select>
            @error('customer_id')<p class="inventra-field-error">{{ $message }}</p>@enderror
        @else
            <p class="{{ $inPanel ? 'ui-correct-line-name' : 'mt-1 font-semibold' }}">{{ $sale->customer_name_snapshot }}</p>
            <p class="{{ $inPanel ? 'ui-correct-hint' : 'mt-2 text-sm text-slate-600' }}">
                The customer cannot be changed because a payment has already been recorded against
                this Sale. Payment records name the original customer and are never rewritten.
            </p>
        @endif
    </section>

    <section class="{{ $inPanel ? 'ui-correct-section' : 'rounded-2xl border border-slate-200 bg-white p-6 shadow-sm' }}">
        <h3 class="{{ $inPanel ? 'ui-correct-heading' : 'font-bold' }}">Reason for the correction <span class="text-red-600">*</span></h3>
        <p class="{{ $inPanel ? 'ui-correct-hint' : 'mt-1 text-sm text-slate-600' }}">Required. Stored with your name and the time, and shown in the audit trail.</p>
        <textarea aria-label="Reason for the correction" name="reason" required minlength="10" maxlength="500"
                  placeholder="e.g. Recorded 10 crates by mistake; the customer took 2."
                  class="{{ $inPanel ? 'inventra-input ui-correct-textarea' : 'mt-3 w-full rounded-xl border border-slate-300 p-3' }}">{{ \App\Support\OldInput::scalar('reason') }}</textarea>
        @error('reason')<p class="inventra-field-error">{{ $message }}</p>@enderror

        <label class="{{ $inPanel ? 'ui-correct-qty' : 'mt-4 block text-sm font-semibold' }}">
            <span>Sale notes</span>
            <textarea aria-label="Sale notes" name="notes" maxlength="1000"
                      class="{{ $inPanel ? 'inventra-input ui-correct-textarea' : 'mt-2 w-full rounded-xl border border-slate-300 p-3' }}">{{ \App\Support\OldInput::scalar('notes', (string) $sale->notes) }}</textarea>
        </label>
    </section>

    <div class="{{ $inPanel ? 'ui-correct-actions' : 'flex flex-wrap gap-3' }}">
        @if($inPanel)
            <button type="button" class="ui-button" x-on:click="showDetails">Cancel</button>
        @else
            <a href="{{ route('sales.show', $sale) }}" class="rounded-xl border border-slate-300 bg-white px-6 py-3 font-semibold">Cancel</a>
        @endif
        <button class="{{ $inPanel ? 'inventra-primary-action' : 'rounded-xl bg-[#0b56c9] px-6 py-3 font-semibold text-white' }}">Save correction</button>
    </div>
</form>
