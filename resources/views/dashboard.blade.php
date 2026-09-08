@php
    $severities = [
        'critical' => 'border-red-300 bg-red-50 text-red-800',
        'attention' => 'border-amber-300 bg-amber-50 text-amber-900',
        'information' => 'border-slate-300 bg-slate-50 text-slate-700',
    ];
    $severityLabels = ['critical' => 'Critical attention', 'attention' => 'Needs attention', 'information' => 'Information'];
@endphp
<x-app-layout title="Dashboard">
    <x-page-header title="Operational dashboard" description="Your sales, collections and operational priorities in one place." eyebrow="Dashboard">
        <a class="ui-button" href="{{ route('inventory.index') }}">View {{ $scope === 'management' ? 'inventory' : 'products' }}</a>
        <a class="ui-button ui-button-primary" href="{{ route('sales.create') }}">Record sale</a>
    </x-page-header>

    <form method="GET" class="ui-filter-bar mt-6 grid gap-3 rounded-2xl border bg-white p-4 md:grid-cols-4">
        <label class="text-sm">From<input aria-label="From date" class="mt-1 w-full rounded-xl border-slate-300" type="date" name="from" value="{{ $filters->from }}"></label>
        <label class="text-sm">To<input aria-label="To date" class="mt-1 w-full rounded-xl border-slate-300" type="date" name="to" value="{{ $filters->to }}"></label>
        <div class="flex items-end"><button class="rounded-xl bg-slate-800 px-4 py-3 text-white">Apply period</button></div>
        <p class="flex items-end text-xs text-slate-500">Period {{ $filters->from }} through {{ $filters->to }} inclusive · {{ config('business.timezone') }}</p>
    </form>
<div class="mt-2 text-right"><x-filter-reset /></div>
    @error('from')<p class="mt-2 text-red-700">{{ $message }}</p>@enderror

    <div class="ui-section-title"><h3>In the selected period</h3><p>Sales and cash flows · {{ $filters->from }} to {{ $filters->to }}</p></div>
    <section class="ui-metrics">
        @foreach($periodMetrics as $metric)
            <article class="ui-metric">
                <small class="text-slate-500">{{ $metric['label'] }}</small>
                <strong class="mt-1 block text-2xl">@if($metric['format'] === 'money')₦{{ \App\Support\Money::format((string) $metric['value']) }}@else{{ $metric['value'] }}@endif</strong>
                <p class="mt-2 text-xs text-slate-500">{{ $metric['meaning'] }}</p>
            </article>
        @endforeach
    </section>

    <div class="ui-section-title"><h3>Right now</h3><p>Current business state</p></div>
    <p class="text-sm text-slate-500">Current-state figures. These describe the business today and are not limited by the selected period.</p>
    <section class="ui-metrics">
        @foreach($currentMetrics as $metric)
            <article class="ui-metric">
                <small class="text-slate-500">{{ $metric['label'] }}</small>
                <strong class="mt-1 block text-2xl">@if($metric['format'] === 'money')₦{{ \App\Support\Money::format((string) $metric['value']) }}@else{{ $metric['value'] }}@endif</strong>
                <p class="mt-2 text-xs text-slate-500">{{ $metric['meaning'] }}</p>
            </article>
        @endforeach
    </section>

    <h3 class="mt-8 text-lg font-bold">Operational alerts</h3>
    <section class="mt-3 grid gap-3">
        @forelse($alerts['items'] as $alert)
            <article class="rounded-2xl border p-4 {{ $severities[$alert['severity']] ?? $severities['information'] }}">
                <small class="font-semibold uppercase tracking-wide">{{ $severityLabels[$alert['severity']] ?? 'Information' }}</small>
                <p class="mt-1 font-semibold">{{ $alert['title'] }}</p>
                <p class="text-sm">{{ $alert['detail'] }}</p>
            </article>
        @empty
            <div class="rounded-2xl border bg-white"><x-empty-state title="Nothing needs attention right now." description="Current stock and sale balances have no attention items to show." /></div>
        @endforelse
    </section>

    <section class="mt-6 grid gap-4 {{ $scope === 'management' ? 'lg:grid-cols-3' : 'lg:grid-cols-2' }}">
        <article class="rounded-2xl border bg-white p-5">
            <div class="flex items-center justify-between"><h4 class="font-bold">Oldest outstanding Sales</h4><a class="text-sm text-blue-700" href="{{ route('sales.index') }}">All Sales</a></div>
            @forelse($alerts['outstandingSales'] as $row)
                <div class="mt-3 flex justify-between border-b pb-2 text-sm">
                    <span><a class="text-blue-700" href="{{ route('sales.show', $row) }}">{{ $row->sale_number }}</a><br><small>{{ $row->customer_name_snapshot }} · {{ $row->created_at->timezone(config('business.timezone'))->format('d M Y') }}</small></span>
                    <strong>₦{{ \App\Support\Money::format($row->balance_due) }}</strong>
                </div>
            @empty
                <p class="mt-3 text-sm text-slate-500">No outstanding balances.</p>
            @endforelse
        </article>

        <article class="rounded-2xl border bg-white p-5">
            <div class="flex items-center justify-between"><h4 class="font-bold">Low stock</h4><a class="text-sm text-blue-700" href="{{ route('inventory.index', ['stock' => 'low']) }}">All low stock</a></div>
            @forelse($alerts['lowStockProducts'] as $row)
                <div class="mt-3 flex justify-between border-b pb-2 text-sm">
                    <span><a class="text-blue-700" href="{{ route('inventory.products.show', $row) }}">{{ $row->name }}</a><br><small>{{ $row->sku }}</small></span>
                    <strong>{{ $row->current_stock }} {{ $row->unit->value }}</strong>
                </div>
            @empty
                <p class="mt-3 text-sm text-slate-500">No Products at or below reorder level.</p>
            @endforelse
        </article>

        @if($scope === 'management')
            <article class="rounded-2xl border bg-white p-5">
                <h4 class="font-bold">Refundable customer credit</h4>
                @forelse($alerts['creditSales'] as $row)
                    <div class="mt-3 flex justify-between border-b pb-2 text-sm">
                        <span><a class="text-blue-700" href="{{ route('sales.show', $row) }}">{{ $row->sale_number }}</a><br><small>{{ $row->customer_name_snapshot }}</small></span>
                        <strong>₦{{ \App\Support\Money::format($row->refundable_credit) }}</strong>
                    </div>
                @empty
                    <p class="mt-3 text-sm text-slate-500">No Sale is holding refundable credit.</p>
                @endforelse
            </article>
        @endif
    </section>

    <h3 class="mt-8 text-lg font-bold">Recent activity</h3>
    <section class="mt-3 grid gap-4 lg:grid-cols-2">
        <article class="rounded-2xl border bg-white p-5">
            <div class="flex items-center justify-between"><h4 class="font-bold">{{ $scope === 'management' ? 'Recent Sales' : 'My recent Sales' }}</h4><a class="text-sm text-blue-700" href="{{ route('sales.index') }}">View all</a></div>
            @forelse($recent['sales'] as $row)
                <div class="mt-3 flex justify-between border-b pb-2 text-sm">
                    <span><a class="text-blue-700" href="{{ route('sales.show', $row) }}">{{ $row->sale_number }}</a><br><small>{{ $row->customer_name_snapshot }} · {{ $row->created_at->timezone(config('business.timezone'))->format('d M Y') }} · {{ $row->payment_status->value }}</small></span>
                    <strong>₦{{ \App\Support\Money::format($row->total_amount) }}</strong>
                </div>
            @empty
                <p class="mt-3 text-sm text-slate-500">No Sales recorded yet.</p>
            @endforelse
        </article>

        @if($scope === 'management')
            <article class="rounded-2xl border bg-white p-5">
                <div class="flex items-center justify-between"><h4 class="font-bold">Recent Collections</h4><a class="text-sm text-blue-700" href="{{ route('sale-payments.index') }}">View all</a></div>
                @forelse($recent['collections'] as $row)
                    <div class="mt-3 flex justify-between border-b pb-2 text-sm">
                        <span>{{ $row->payment_number }}<br><small>{{ $row->sale?->sale_number }} · {{ $row->payment_method->label() }} · {{ $row->paid_at->timezone(config('business.timezone'))->format('d M Y') }}</small></span>
                        <strong>₦{{ \App\Support\Money::format($row->amount) }}</strong>
                    </div>
                @empty
                    <p class="mt-3 text-sm text-slate-500">No Collections recorded yet.</p>
                @endforelse
            </article>

            <article class="rounded-2xl border bg-white p-5">
                <div class="flex items-center justify-between"><h4 class="font-bold">Recent Returns</h4><a class="text-sm text-blue-700" href="{{ route('returns.index') }}">View all</a></div>
                @forelse($recent['returns'] as $row)
                    <div class="mt-3 flex justify-between border-b pb-2 text-sm">
                        <span><a class="text-blue-700" href="{{ route('returns.show', $row) }}">{{ $row->return_number }}</a><br><small>{{ $row->sale_number_snapshot }} · {{ $row->customer_name_snapshot }}</small></span>
                        <strong>₦{{ \App\Support\Money::format($row->merchandise_value) }}</strong>
                    </div>
                @empty
                    <p class="mt-3 text-sm text-slate-500">No Returns recorded yet.</p>
                @endforelse
            </article>

            <article class="rounded-2xl border bg-white p-5">
                <div class="flex items-center justify-between"><h4 class="font-bold">Recent Refunds</h4><a class="text-sm text-blue-700" href="{{ route('refunds.index') }}">View all</a></div>
                @forelse($recent['refunds'] as $row)
                    <div class="mt-3 flex justify-between border-b pb-2 text-sm">
                        <span><a class="text-blue-700" href="{{ route('refunds.show', $row) }}">{{ $row->refund_number }}</a><br><small>{{ $row->sale_number_snapshot }} · {{ $row->payment_method->label() }}</small></span>
                        <strong>₦{{ \App\Support\Money::format($row->amount) }}</strong>
                    </div>
                @empty
                    <p class="mt-3 text-sm text-slate-500">No Refunds recorded yet.</p>
                @endforelse
            </article>

            <article class="rounded-2xl border bg-white p-5">
                <div class="flex items-center justify-between"><h4 class="font-bold">Recent Expenses</h4><a class="text-sm text-blue-700" href="{{ route('expenses.index') }}">View all</a></div>
                @forelse($recent['expenses'] as $row)
                    <div class="mt-3 flex justify-between border-b pb-2 text-sm">
                        <span><a class="text-blue-700" href="{{ route('expenses.show', $row) }}">{{ $row->expense_number }}</a><br><small>{{ $row->category_name_snapshot }} · {{ $row->description }}</small></span>
                        <strong>₦{{ \App\Support\Money::format($row->amount) }}</strong>
                    </div>
                @empty
                    <p class="mt-3 text-sm text-slate-500">No Expenses recorded yet.</p>
                @endforelse
            </article>

            <article class="rounded-2xl border bg-white p-5">
                <div class="flex items-center justify-between"><h4 class="font-bold">Recent Purchases</h4><a class="text-sm text-blue-700" href="{{ route('purchases.index') }}">View all</a></div>
                @forelse($recent['purchases'] as $row)
                    <div class="mt-3 flex justify-between border-b pb-2 text-sm">
                        <span><a class="text-blue-700" href="{{ route('purchases.show', $row) }}">{{ $row->purchase_number }}</a><br><small>{{ $row->supplier_name_snapshot }} · {{ $row->received_at->timezone(config('business.timezone'))->format('d M Y') }}</small></span>
                        <strong>₦{{ \App\Support\Money::format($row->total_amount) }}</strong>
                    </div>
                @empty
                    <p class="mt-3 text-sm text-slate-500">No Purchases recorded yet.</p>
                @endforelse
            </article>
        @endif
    </section>
<p class="mt-8 text-xs text-slate-500">No profit, COGS, margin, inventory valuation, or tax is calculated.</p>
</x-app-layout>
