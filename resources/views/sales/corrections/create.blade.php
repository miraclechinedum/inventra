<x-app-layout :title="'Correct '.$sale->sale_number">
    <a href="{{ route('sales.show', $sale) }}" class="text-sm font-semibold text-[#0b56c9]">← {{ $sale->sale_number }}</a>
    <h1 class="mt-3 text-3xl font-bold">Correct Sale</h1>
    <p class="mt-2 text-slate-600">Use this when the Sale was <strong>recorded incorrectly</strong> — the wrong product, the wrong quantity, or the wrong customer.</p>

    {{-- The same partial the Sales-list panel renders, so the page and the panel can never
         offer different fields or post different payloads. --}}
    @include('sales.corrections._form', ['inPanel' => false])
</x-app-layout>
