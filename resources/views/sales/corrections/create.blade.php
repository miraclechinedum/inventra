<x-app-layout :title="'Correct '.$sale->sale_number">
    <a href="{{ route('sales.show', $sale) }}" class="text-sm font-semibold text-[#0b56c9]">← {{ $sale->sale_number }}</a>
    <h1 class="mt-3 text-3xl font-bold">Correct Sale</h1>
    <p class="mt-2 text-slate-600">Use this when the Sale was <strong>recorded incorrectly</strong> — the wrong product, the wrong quantity, or the wrong customer.</p>

    <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-5">
        <p class="font-bold text-amber-950">This is not how you record returned goods.</p>
        <p class="mt-1 text-sm text-amber-900">If the customer brought something back, use <a class="font-semibold underline" href="{{ route('sales.returns.create', $sale) }}">Record Return</a> instead. A return puts stock back, reduces what is owed and creates refundable credit, all while leaving this Sale intact as evidence of what was originally sold. Correcting the Sale would erase that history.</p>
    </div>

    <x-validation-errors />

    <form method="POST" action="{{ route('sales.corrections.store', $sale) }}" data-submit-once class="mt-6 space-y-6">
        @csrf

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="font-bold">Items as they should have been recorded</h2>
            <p class="mt-1 text-sm text-slate-600">Set the correct quantity for each line, or 0 to remove it. Stock is adjusted by the difference and the totals are recalculated automatically. Prices stay as originally sold.</p>
            <div class="mt-5 space-y-4">
                @foreach($sale->items as $index => $item)
                    <div class="grid items-center gap-3 border-b border-slate-100 pb-4 md:grid-cols-[2fr_1fr_1fr]">
                        <div>
                            <p class="font-semibold">{{ $item->product_name_snapshot }}</p>
                            <p class="font-mono text-xs text-slate-500">{{ $item->product_sku_snapshot }}</p>
                            <input type="hidden" name="products[{{ $index }}][product_id]" value="{{ $item->product_id }}">
                        </div>
                        <label class="text-xs font-semibold text-slate-500">Recorded
                            <span class="mt-1 block text-sm font-normal text-slate-700">{{ $item->quantity }} {{ $item->unit_snapshot }} @ &#8358;{{ \App\Support\Money::format($item->unit_price) }}</span>
                        </label>
                        <label class="text-xs font-semibold text-slate-500">Correct quantity
                            <input name="products[{{ $index }}][quantity]" inputmode="decimal" value="{{ \App\Support\OldInput::scalar('products.'.$index.'.quantity', (string) $item->quantity) }}" class="mt-1 w-full rounded-xl border-slate-300" required>
                        </label>
                    </div>
                @endforeach
            </div>
            @error('products')<p class="mt-3 text-sm text-red-600">{{ $message }}</p>@enderror
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="font-bold">Customer</h2>
            @if($canChangeCustomer)
                <p class="mt-1 text-sm text-slate-600">Change this only if the Sale was recorded against the wrong person.</p>
                <select aria-label="Customer" name="customer_id" class="mt-3 w-full rounded-xl border border-slate-300 px-4 py-3">
                    @foreach($customers as $customer)
                        <option value="{{ $customer->id }}" @selected((int) \App\Support\OldInput::scalar('customer_id', (string) $sale->customer_id) === $customer->id)>{{ $customer->customer_code }} · {{ $customer->first_name }} {{ $customer->last_name }}</option>
                    @endforeach
                </select>
                @error('customer_id')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
            @else
                <p class="mt-1 font-semibold">{{ $sale->customer_name_snapshot }}</p>
                <p class="mt-2 text-sm text-slate-600">The customer cannot be changed because a payment has already been recorded against this Sale. Payment records name the original customer and are never rewritten. If the Sale is against the wrong person entirely, an administrator should void it and record it again.</p>
            @endif
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="font-bold">Reason for the correction <span class="text-red-600">*</span></h2>
            <p class="mt-1 text-sm text-slate-600">Required. This is stored with your name and the time, and appears in the audit trail.</p>
            <textarea aria-label="Reason for the correction" name="reason" required minlength="10" maxlength="500" placeholder="e.g. Recorded 10 crates by mistake; the customer took 2." class="mt-3 w-full rounded-xl border border-slate-300 p-3">{{ \App\Support\OldInput::scalar('reason') }}</textarea>
            @error('reason')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror

            <label class="mt-4 block text-sm font-semibold">Sale notes
                <textarea aria-label="Sale notes" name="notes" maxlength="1000" class="mt-2 w-full rounded-xl border border-slate-300 p-3">{{ \App\Support\OldInput::scalar('notes', (string) $sale->notes) }}</textarea>
            </label>
        </section>

        <div class="flex flex-wrap gap-3">
            <button class="rounded-xl bg-[#0b56c9] px-6 py-3 font-semibold text-white">Save correction</button>
            <a href="{{ route('sales.show', $sale) }}" class="rounded-xl border border-slate-300 bg-white px-6 py-3 font-semibold">Cancel</a>
        </div>
    </form>
</x-app-layout>
