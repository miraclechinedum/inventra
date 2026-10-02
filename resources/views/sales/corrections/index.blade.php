<x-app-layout :title="'Corrections for '.$sale->sale_number">
    <a href="{{ route('sales.show', $sale) }}" class="text-sm font-semibold text-[#0b56c9]">← {{ $sale->sale_number }}</a>
    <h1 class="mt-3 text-3xl font-bold">Correction history</h1>
    <p class="mt-2 text-slate-600">Every correction recorded against this Sale, with the lines it replaced. Nothing here was overwritten — superseded lines are kept.</p>

    <div class="mt-6 space-y-5">
        @forelse($corrections as $correction)
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-bold">Correction {{ $correction->id }}</h2>
                        <p class="mt-1 text-sm text-slate-600">{{ $correction->corrected_by_name_snapshot }} · {{ $correction->corrected_at->format('M j, Y g:i A') }}</p>
                    </div>
                </div>
                <p class="mt-3 rounded-xl bg-slate-50 p-4 text-slate-700">{{ $correction->reason }}</p>

                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div><dt class="text-xs uppercase text-slate-500">Subtotal</dt><dd class="font-semibold">&#8358;{{ \App\Support\Money::format($correction->subtotal_before) }} → &#8358;{{ \App\Support\Money::format($correction->subtotal_after) }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-500">Total</dt><dd class="font-semibold">&#8358;{{ \App\Support\Money::format($correction->total_before) }} → &#8358;{{ \App\Support\Money::format($correction->total_after) }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-500">Balance</dt><dd class="font-semibold">&#8358;{{ \App\Support\Money::format($correction->balance_before) }} → &#8358;{{ \App\Support\Money::format($correction->balance_after) }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-500">Refundable credit</dt><dd class="font-semibold">&#8358;{{ \App\Support\Money::format($correction->refundable_credit_before) }} → &#8358;{{ \App\Support\Money::format($correction->refundable_credit_after) }}</dd></div>
                </dl>

                <div class="mt-5 grid gap-5 md:grid-cols-2">
                    <div>
                        <p class="text-xs uppercase text-slate-500">Lines replaced</p>
                        <div class="mt-2 space-y-1 text-sm">
                            @forelse($correction->supersededItems as $item)
                                <p class="text-slate-600 line-through">{{ $item->product_name_snapshot }} · {{ \App\Support\Quantity::trim($item->quantity) }} {{ $item->unit_snapshot }} · &#8358;{{ \App\Support\Money::format($item->line_total) }}</p>
                            @empty
                                <p class="text-slate-500">None</p>
                            @endforelse
                        </div>
                    </div>
                    <div>
                        <p class="text-xs uppercase text-slate-500">Lines recorded instead</p>
                        <div class="mt-2 space-y-1 text-sm">
                            @forelse($correction->addedItems as $item)
                                <p class="font-medium">{{ $item->product_name_snapshot }} · {{ \App\Support\Quantity::trim($item->quantity) }} {{ $item->unit_snapshot }} · &#8358;{{ \App\Support\Money::format($item->line_total) }}</p>
                            @empty
                                <p class="text-slate-500">None</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </section>
        @empty
            <x-empty-state title="This Sale has never been corrected." description="Corrections appear here with the lines they replaced." />
        @endforelse
    </div>
</x-app-layout>
