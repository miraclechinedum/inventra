{{--
    The PDF receipt.

    dompdf renders this on the server with no stylesheet and only partial CSS support, so the page
    carries its own small inline style block and the shared body partial is rendered in `plain`
    mode. The content itself is identical to the printable receipt because both read the same
    ReceiptPresenter data — nothing about the money is computed twice.
--}}
@php($business = app(\App\Settings\BusinessSettings::class)->current())
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $sale->sale_number }}</title>
    <style>
        @page { margin: 26px 30px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0f172a; line-height: 1.5; }
        h1 { font-size: 17px; margin: 0; }
        .head { border-bottom: 1px solid #cbd5e1; padding-bottom: 10px; margin-bottom: 14px; }
        .muted { color: #475569; font-size: 11px; margin: 3px 0 0; }
        .doc { font-weight: 700; margin-top: 7px; }
        .foot { margin-top: 22px; border-top: 1px solid #e2e8f0; padding-top: 8px; font-size: 10px; color: #475569; }
    </style>
</head>
<body>
    <div class="head">
        <h1>{{ $business->business_name }}</h1>
        @php($lines = array_filter([$business->business_address, implode(', ', array_filter([$business->city, $business->state]))]))
        @if($lines)<p class="muted">{{ implode(' · ', $lines) }}</p>@endif
        @php($contact = array_filter([$business->business_phone, $business->business_email]))
        @if($contact)<p class="muted">{{ implode(' · ', $contact) }}</p>@endif
        <p class="doc">Sale Receipt</p>
    </div>

    @php($receipt = \App\Support\ReceiptPresenter::for($sale) + ['plain' => true])
    @include('sales._receipt-body', $receipt)

    @if($business->receipt_footer)
        <div class="foot">{!! nl2br(e($business->receipt_footer)) !!}</div>
    @endif
</body>
</html>
