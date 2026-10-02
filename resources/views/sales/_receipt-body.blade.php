{{--
    The body of a receipt, shared by the printable page and the PDF.

    Every figure comes from ReceiptPresenter, which reads the Sale's own settled columns. Nothing is
    calculated here, so the printed receipt and the PDF cannot disagree with each other or with the
    ledger. `$plain` switches to inline styles for dompdf, which supports far less CSS than a
    browser and never sees the compiled stylesheet.
--}}
@php($plain = $plain ?? false)

<div class="{{ $plain ? '' : 'flex justify-between' }}" @if($plain) style="width:100%" @endif>
    <div>
        <p class="{{ $plain ? '' : 'mt-1 font-mono' }}" @if($plain) style="font-family:monospace;font-size:13px;font-weight:700" @endif>{{ $number }}</p>
    </div>
    <p class="{{ $plain ? '' : 'text-right text-sm' }}" @if($plain) style="font-size:11px;color:#475569" @endif>
        {{ $date->format('M j, Y') }}<br>{{ ucfirst($sale->status->value) }}
    </p>
</div>

<div class="{{ $plain ? '' : 'mt-6 border-y py-4' }}" @if($plain) style="margin-top:14px;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;padding:10px 0" @endif>
    <p class="{{ $plain ? '' : 'font-semibold' }}" @if($plain) style="font-weight:700;font-size:12px" @endif>{{ $buyerName }}</p>
    @if($buyerDetail !== '')
        <p class="{{ $plain ? '' : 'text-sm' }}" @if($plain) style="font-size:11px;color:#475569" @endif>{{ $buyerDetail }}</p>
    @endif
</div>

<table class="{{ $plain ? '' : 'mt-5 min-w-full text-sm' }}" @if($plain) style="width:100%;border-collapse:collapse;margin-top:14px;font-size:11px" @endif>
    <thead class="{{ $plain ? '' : 'border-b text-left' }}">
        <tr>
            <th class="{{ $plain ? '' : 'py-2' }}" @if($plain) style="text-align:left;border-bottom:1px solid #cbd5e1;padding:6px 0" @endif>Item</th>
            <th @if($plain) style="text-align:left;border-bottom:1px solid #cbd5e1;padding:6px 0" @endif>Qty</th>
            <th @if($plain) style="text-align:left;border-bottom:1px solid #cbd5e1;padding:6px 0" @endif>Price</th>
            <th class="{{ $plain ? '' : 'text-right' }}" @if($plain) style="text-align:right;border-bottom:1px solid #cbd5e1;padding:6px 0" @endif>Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach($items as $item)
            <tr class="{{ $plain ? '' : 'border-b' }}">
                <td class="{{ $plain ? '' : 'py-3' }}" @if($plain) style="border-bottom:1px solid #eef2f7;padding:6px 0" @endif>
                    {{ $item->product_name_snapshot }}<br>
                    <span class="{{ $plain ? '' : 'font-mono text-xs' }}" @if($plain) style="font-family:monospace;font-size:9px;color:#64748b" @endif>{{ $item->product_sku_snapshot }}</span>
                </td>
                <td @if($plain) style="border-bottom:1px solid #eef2f7;padding:6px 0" @endif>{{ \App\Support\Quantity::trim($item->quantity) }} {{ $item->unit_snapshot }}</td>
                <td @if($plain) style="border-bottom:1px solid #eef2f7;padding:6px 0" @endif>&#8358;{{ \App\Support\Money::format($item->unit_price) }}</td>
                <td class="{{ $plain ? '' : 'text-right' }}" @if($plain) style="text-align:right;border-bottom:1px solid #eef2f7;padding:6px 0" @endif>&#8358;{{ \App\Support\Money::format($item->line_total) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div @if($plain) style="width:100%;margin-top:14px" @else class="mt-5 flex justify-end" @endif>
<table @if($plain) style="width:46%;margin-left:auto;border-collapse:collapse;font-size:11px" @else class="w-full max-w-xs text-sm" @endif>
    @foreach($moneyLines as [$label, $amount, $strong])
        <tr>
            <td @if($plain) style="padding:3px 0;color:#475569" @else class="py-1 text-slate-600" @endif>{{ $label }}</td>
            <td @if($plain) style="padding:3px 0;text-align:right;font-weight:{{ $strong ? 700 : 600 }}" @else class="py-1 text-right {{ $strong ? 'font-bold' : 'font-semibold' }}" @endif>&#8358;{{ \App\Support\Money::format($amount) }}</td>
        </tr>
    @endforeach
</table>
</div>

<div class="{{ $plain ? '' : 'mt-6 border-t pt-4 text-sm' }}" @if($plain) style="margin-top:14px;border-top:1px solid #e2e8f0;padding-top:8px;font-size:11px;color:#475569" @endif>
    <p>{{ $paymentMethod }} · {{ ucfirst($paymentStatus->value) }}</p>
    <p>Sold by {{ $seller }}</p>
</div>

@if($corrected)
    <p class="{{ $plain ? '' : 'mt-5 text-xs text-slate-600' }}" @if($plain) style="margin-top:10px;font-size:9px;color:#475569" @endif>
        This receipt reflects a corrected record of this Sale. It supersedes any receipt issued earlier.
    </p>
@endif
