{{--
    The Sales list as a printable report.

    Deliberately not a receipt. `sales/receipt-pdf.blade.php` is one Sale rendered for the customer
    who bought it; this is the sales register rendered for the business, from exactly the rows the
    Sales list and the CSV export are showing. All three read `SaleController::filteredSales()`, so
    none of them can quietly describe a different set of Sales than the others.

    dompdf sees no stylesheet and supports only a subset of CSS, so the styling is a small inline
    block and the table is plain. DejaVu Sans is the font because it is the bundled face that has
    the naira sign; without it ₦ renders as a blank box.

    Everything printed here is a settled column on the Sale. Nothing on this page recomputes money:
    the column totals are sums of what the rows already say.
--}}
@php($business = app(\App\Settings\BusinessSettings::class)->current())
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Sales report</title>
    <style>
        @page { margin: 24px 26px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #0f172a; line-height: 1.45; }
        h1 { font-size: 15px; margin: 0; }
        .head { border-bottom: 1px solid #cbd5e1; padding-bottom: 9px; margin-bottom: 12px; }
        .muted { color: #475569; font-size: 9.5px; margin: 3px 0 0; }
        .doc { font-weight: 700; font-size: 11px; margin: 7px 0 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th { text-align: left; border-bottom: 1px solid #cbd5e1; padding: 5px 6px; font-size: 9px;
             text-transform: uppercase; letter-spacing: .04em; color: #475569; }
        td { border-bottom: 1px solid #eef2f7; padding: 5px 6px; font-size: 9.5px; }
        .num { text-align: right; }
        .totals td { border-top: 1px solid #cbd5e1; border-bottom: 0; font-weight: 700; padding-top: 7px; }
        .foot { margin-top: 14px; font-size: 9px; color: #475569; }
        .note { margin-top: 9px; padding: 6px 8px; background: #fef4e2; color: #92400e; font-size: 9.5px; }
        .empty { padding: 18px 6px; color: #475569; }
    </style>
</head>
<body>
    <div class="head">
        <h1>{{ $business->business_name }}</h1>
        @php($lines = array_filter([$business->business_address, implode(', ', array_filter([$business->city, $business->state]))]))
        @if($lines)<p class="muted">{{ implode(' · ', $lines) }}</p>@endif
        <p class="doc">Sales Report</p>
        {{-- What this is a report *of*. Only filters the controller actually applied are listed,
             so the header can never claim a narrowing that did not happen. --}}
        <p class="muted">
            {{ $filters === [] ? 'All sales' : implode(' · ', $filters) }}
            · Generated {{ $generatedAt->format('j M Y, H:i') }}
        </p>
    </div>

    @if($matched > $limit)
        <p class="note">
            Showing the first {{ number_format($limit) }} of {{ number_format($matched) }} matching sales.
            Narrow the date range, or use the CSV export, for the complete set.
        </p>
    @endif

    <table>
        <thead>
            <tr>
                <th>Sale ID</th>
                <th>Date</th>
                <th>Customer</th>
                <th class="num">Items</th>
                <th class="num">Discount</th>
                <th class="num">Total</th>
                <th class="num">Paid</th>
                <th class="num">Balance</th>
                <th>Payment</th>
                <th>Status</th>
                <th>Sold by</th>
            </tr>
        </thead>
        <tbody>
            @forelse($sales as $sale)
                <tr>
                    {{-- Blade escapes this, as it escapes every other sale number in the
                         application. A sale number may contain @ ! % or # and none of them are
                         special to HTML, but nothing here depends on that being true. --}}
                    <td>{{ $sale->sale_number }}</td>
                    <td>{{ $sale->sale_date->format('j M Y') }}</td>
                    <td>{{ $sale->customer_name_snapshot }}</td>
                    <td class="num">{{ $sale->items_count }}</td>
                    <td class="num">&#8358;{{ \App\Support\Money::format((string) $sale->discount_amount) }}</td>
                    <td class="num">&#8358;{{ \App\Support\Money::format((string) $sale->total_amount) }}</td>
                    <td class="num">&#8358;{{ \App\Support\Money::format((string) $sale->amount_paid) }}</td>
                    <td class="num">&#8358;{{ \App\Support\Money::format((string) $sale->balance_due) }}</td>
                    <td>{{ ucfirst($sale->payment_status->value) }}</td>
                    <td>{{ ucfirst($sale->status->value) }}</td>
                    <td>{{ $sale->sold_by_name_snapshot }}</td>
                </tr>
            @empty
                <tr><td colspan="11" class="empty">No sales match these filters.</td></tr>
            @endforelse

            @if($sales->isNotEmpty())
                {{-- Sums of the rows above, so the foot always describes the page it is on. --}}
                <tr class="totals">
                    <td colspan="5">{{ $sales->count() }} {{ \Illuminate\Support\Str::plural('sale', $sales->count()) }}</td>
                    <td class="num">&#8358;{{ \App\Support\Money::format($totals['total']) }}</td>
                    <td class="num">&#8358;{{ \App\Support\Money::format($totals['paid']) }}</td>
                    <td class="num">&#8358;{{ \App\Support\Money::format($totals['balance']) }}</td>
                    <td colspan="3"></td>
                </tr>
            @endif
        </tbody>
    </table>

    <div class="foot">{{ $business->business_name }} · Sales report · Generated {{ $generatedAt->format('j M Y, H:i') }}</div>
</body>
</html>
