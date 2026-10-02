<x-app-layout :title="$sale->sale_number.' receipt'">
    <div class="receipt-controls mx-auto mb-4 flex max-w-3xl flex-wrap gap-3">
        <button type="button" data-print-trigger class="inventra-primary-action">Print receipt</button>
        <a href="{{ route('sales.receipt.pdf', $sale) }}" class="ui-button">Download PDF</a>
    </div>
    <div class="mx-auto max-w-3xl rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
        <x-receipt-letterhead document="Sale Receipt" />
        @php($receipt = \App\Support\ReceiptPresenter::for($sale) + ['plain' => false])
    @include('sales._receipt-body', $receipt)
        <x-receipt-footer />
        <a href="{{ route('sales.show', $sale) }}" class="receipt-controls mt-6 inline-block font-semibold text-[#0b56c9]">← Sale detail</a>
    </div>
</x-app-layout>
