@php
    $sale = $discountRequest->sale;
    $pending = $discountRequest->status === \App\Enums\DiscountRequestStatus::Pending;
@endphp
<x-app-layout :title="'Discount request '.$discountRequest->id">
    <x-validation-errors />
    <a href="{{ auth()->user()->can('viewAny', \App\Models\SaleDiscountRequest::class) ? route('discounts.index') : route('sales.show', $sale) }}" class="text-sm font-semibold text-[#0b56c9]">← {{ auth()->user()->can('viewAny', \App\Models\SaleDiscountRequest::class) ? 'Discount approvals' : 'Sale' }}</a>
    <div class="mt-3 flex flex-wrap items-center gap-3"><h1 class="text-3xl font-bold">Discount request {{ $discountRequest->id }}</h1><span class="rounded-full px-3 py-1 text-xs font-semibold {{ $pending ? 'bg-amber-100 text-amber-900' : ($discountRequest->status === \App\Enums\DiscountRequestStatus::Approved ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700') }}">{{ $discountRequest->status->label() }}</span></div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
            <h2 class="text-lg font-bold">Request</h2>
            <dl class="mt-5 grid gap-5 sm:grid-cols-2">
                <div><dt class="text-xs uppercase text-slate-500">Sale</dt><dd class="mt-1 font-semibold"><a class="text-[#0b56c9]" href="{{ route('sales.show', $sale) }}">{{ $sale->sale_number }}</a></dd></div>
                <div><dt class="text-xs uppercase text-slate-500">Customer</dt><dd class="mt-1 font-semibold">{{ $sale->customer_name_snapshot }}</dd></div>
                <div><dt class="text-xs uppercase text-slate-500">Discount requested</dt><dd class="mt-1 text-xl font-bold">&#8358;{{ \App\Support\Money::format($discountRequest->requested_amount) }}</dd></div>
                <div><dt class="text-xs uppercase text-slate-500">Requested by</dt><dd class="mt-1">{{ $discountRequest->requested_by_name_snapshot }} · {{ $discountRequest->requested_at->format('M j, Y g:i A') }}</dd></div>
            </dl>
            <div class="mt-6 border-t pt-5"><p class="text-xs uppercase text-slate-500">Reason given</p><p class="mt-2 text-slate-700">{{ $discountRequest->reason }}</p></div>
            @if($discountRequest->status->isDecided())
                <div class="mt-6 border-t pt-5"><p class="text-xs uppercase text-slate-500">Decision</p><p class="mt-2 font-semibold">{{ $discountRequest->status->label() }} by {{ $discountRequest->decided_by_name_snapshot }} on {{ $discountRequest->decided_at->format('M j, Y g:i A') }}</p>@if($discountRequest->decision_note)<p class="mt-2 text-slate-700">{{ $discountRequest->decision_note }}</p>@endif</div>
            @endif
            @if($discountRequest->status === \App\Enums\DiscountRequestStatus::Approved)
                <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                    <p class="font-bold text-emerald-900">Effect on the Sale, as recorded at approval</p>
                    <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                        <div><dt class="text-emerald-900">Discount</dt><dd class="font-semibold">&#8358;{{ \App\Support\Money::format($discountRequest->discount_before) }} → &#8358;{{ \App\Support\Money::format($discountRequest->discount_after) }}</dd></div>
                        <div><dt class="text-emerald-900">Sale total</dt><dd class="font-semibold">&#8358;{{ \App\Support\Money::format($discountRequest->total_before) }} → &#8358;{{ \App\Support\Money::format($discountRequest->total_after) }}</dd></div>
                        <div><dt class="text-emerald-900">Balance due after</dt><dd class="font-semibold">&#8358;{{ \App\Support\Money::format($discountRequest->balance_after) }}</dd></div>
                        <div><dt class="text-emerald-900">Refundable credit after</dt><dd class="font-semibold">&#8358;{{ \App\Support\Money::format($discountRequest->refundable_credit_after) }}</dd></div>
                    </dl>
                    <p class="mt-3 text-sm text-emerald-900">Recorded payments were not altered. Any amount paid above the new total became refundable credit.</p>
                </div>
            @endif
        </section>

        <div class="space-y-6">
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="font-bold">Sale position now</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Subtotal</dt><dd class="font-semibold">&#8358;{{ \App\Support\Money::format($sale->subtotal) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Discount</dt><dd class="font-semibold">&#8358;{{ \App\Support\Money::format($sale->discount_amount) }}</dd></div>
                    <div class="flex justify-between border-t pt-3"><dt class="text-slate-500">Total</dt><dd class="font-semibold">&#8358;{{ \App\Support\Money::format($sale->total_amount) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Paid</dt><dd class="font-semibold">&#8358;{{ \App\Support\Money::format($sale->amount_paid) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Balance due</dt><dd class="font-semibold">&#8358;{{ \App\Support\Money::format($sale->balance_due) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Refundable credit</dt><dd class="font-semibold">&#8358;{{ \App\Support\Money::format($sale->refundable_credit) }}</dd></div>
                </dl>
            </section>

            @if($pending)
                @can('approve', $discountRequest)
                    <section class="rounded-2xl border border-emerald-200 bg-white p-6 shadow-sm">
                        <h2 class="font-bold">Approve</h2>
                        <p class="mt-1 text-sm text-slate-600">This reduces the Sale total by &#8358;{{ \App\Support\Money::format($discountRequest->requested_amount) }} and recalculates the balance. Recorded payments are left exactly as they are.</p>
                        <form method="POST" action="{{ route('discounts.approve', $discountRequest) }}" data-submit-once data-confirm-message="Approve this discount and reconcile the Sale?" class="mt-4 space-y-3">
                            @csrf
                            <textarea aria-label="Approval note" name="decision_note" maxlength="500" placeholder="Note (optional)" class="w-full rounded-lg border border-slate-300 p-3">{{ \App\Support\OldInput::scalar('decision_note') }}</textarea>
                            <button class="w-full rounded-lg bg-emerald-600 px-4 py-2.5 font-semibold text-white">Approve discount</button>
                        </form>
                    </section>
                @else
                    <section class="rounded-2xl border border-slate-200 bg-slate-50 p-6"><p class="text-sm font-semibold text-slate-700">@if(auth()->id() === $discountRequest->requested_by)You raised this request, so somebody else must approve it.@else Only an administrator can approve a discount.@endif</p></section>
                @endcan
                @can('decline', $discountRequest)
                    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="font-bold">Decline</h2>
                        <p class="mt-1 text-sm text-slate-600">Declining changes nothing about the Sale.</p>
                        <form method="POST" action="{{ route('discounts.decline', $discountRequest) }}" data-submit-once class="mt-4 space-y-3">
                            @csrf
                            <textarea aria-label="Decline reason" name="decision_note" required maxlength="500" placeholder="Why is this declined?" class="w-full rounded-lg border border-slate-300 p-3">{{ \App\Support\OldInput::scalar('decision_note') }}</textarea>
                            @error('decision_note')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                            <button class="w-full rounded-lg border border-red-300 px-4 py-2.5 font-semibold text-red-700">Decline discount</button>
                        </form>
                    </section>
                @endcan
            @endif
        </div>
    </div>
</x-app-layout>
