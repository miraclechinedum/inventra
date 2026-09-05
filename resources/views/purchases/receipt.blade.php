<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $purchase->purchase_number }} · Stock Receiving Record</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="mx-auto max-w-4xl p-10 text-slate-800">
    <button type="button" data-print-page class="mb-6 rounded-lg bg-[#0b56c9] px-4 py-2 font-semibold text-white print:hidden">Print</button>
    <x-receipt-letterhead document="Stock Receiving Record" />
    <h2 class="mt-2 font-mono text-xl">{{ $purchase->purchase_number }}</h2>
    <div class="mt-6 border-y py-4">
        <p>Supplier: {{ $purchase->supplier_name_snapshot }} ({{ $purchase->supplier_code_snapshot }})</p>
        <p>Received: {{ $purchase->received_at->format('d M Y H:i') }} by {{ $purchase->received_by_name_snapshot }}</p>
    </div>
    <table class="mt-6 w-full border-collapse text-left">
        <thead><tr class="border-b"><th class="p-3">Product</th><th class="p-3">Quantity</th><th class="p-3">Unit cost</th><th class="p-3 text-right">Total</th></tr></thead>
        <tbody>@foreach($purchase->items as $item)<tr class="border-b"><td class="p-3">{{ $item->product_name_snapshot }} ({{ $item->product_sku_snapshot }})</td><td class="p-3">{{ $item->quantity }} {{ $item->product_unit_snapshot }}</td><td class="p-3">₦{{ \App\Support\Money::format($item->unit_cost) }}</td><td class="p-3 text-right">₦{{ \App\Support\Money::format($item->line_total) }}</td></tr>@endforeach</tbody>
    </table>
    <h3 class="mt-6 text-right text-xl font-bold">Total: ₦{{ \App\Support\Money::format($purchase->total_amount) }}</h3>
</body>
</html>
