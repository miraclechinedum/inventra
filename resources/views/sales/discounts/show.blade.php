@php
    // A pre-sale request has no Sale yet — it carries a draft cart instead. Everything about a Sale
    // below is therefore conditional, not null-coalesced: there is no Sale position to show before
    // the sale is recorded, and pretending otherwise with zeroes would misreport it.
    $sale = $discountRequest->sale;
    $draft = $discountRequest->draft;
    $buyer = $sale?->customer_name_snapshot
        ?? ($draft?->is_walk_in ? \App\Models\Sale::WALK_IN_NAME : $draft?->customer?->full_name);
    $pending = $discountRequest->status === \App\Enums\DiscountRequestStatus::Pending;
@endphp
<x-app-layout :title="'Discount request '.$discountRequest->id">
    <x-validation-errors />
    {{-- Back to the queue for management; anyone else goes back to where the request was raised, never to a queue they cannot open. --}}
    @php($canReviewQueue = auth()->user()->can('viewAny', \App\Models\SaleDiscountRequest::class))
    <a href="{{ $canReviewQueue ? route('discounts.index') : ($sale ? route('sales.show', $sale) : route('sales.create')) }}" class="text-sm font-semibold text-[#0b56c9]">← {{ $canReviewQueue ? 'Discount approvals' : ($sale ? 'Sale' : 'Record Sale') }}</a>
    <div class="mt-3 flex flex-wrap items-center gap-3"><h1 class="text-3xl font-bold">Discount request {{ $discountRequest->id }}</h1><span class="rounded-full px-3 py-1 text-xs font-semibold {{ $pending ? 'bg-amber-100 text-amber-900' : ($discountRequest->status === \App\Enums\DiscountRequestStatus::Approved ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700') }}">{{ $discountRequest->status->label() }}</span></div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
            <h2 class="text-lg font-bold">Request</h2>
            <dl class="mt-5 grid gap-5 sm:grid-cols-2">
                <div><dt class="text-xs uppercase text-slate-500">Sale</dt><dd class="mt-1 font-semibold">@if($sale)<a class="text-[#0b56c9]" href="{{ route('sales.show', $sale) }}">{{ $sale->sale_number }}</a>@else<span class="text-slate-600">Requested before the sale was recorded</span>@endif</dd></div>
                <div><dt class="text-xs uppercase text-slate-500">Customer</dt><dd class="mt-1 font-semibold">{{ $buyer }}</dd></div>
                <div><dt class="text-xs uppercase text-slate-500">Discount requested</dt><dd class="mt-1 text-xl font-bold">&#8358;{{ \App\Support\Money::format($discountRequest->requested_amount) }}</dd></div>
                <div><dt class="text-xs uppercase text-slate-500">Requested by</dt><dd class="mt-1">{{ $discountRequest->requested_by_name_snapshot }} · {{ $discountRequest->requested_at->format('M j, Y g:i A') }}</dd></div>
            </dl>
            <div class="mt-6 border-t pt-5"><p class="text-xs uppercase text-slate-500">Reason given</p><p class="mt-2 text-slate-700">{{ $discountRequest->reason }}</p></div>
            @if($discountRequest->status->isDecided())
                @php($expired = $discountRequest->status === \App\Enums\DiscountRequestStatus::Expired)
                {{-- An expired approval keeps the approval it records; an expired pending request was never decided. --}}
                <div class="mt-6 border-t pt-5"><p class="text-xs uppercase text-slate-500">Decision</p>
                    @if($discountRequest->decided_at)<p class="mt-2 font-semibold">{{ $expired ? 'Approved' : $discountRequest->status->label() }} by {{ $discountRequest->decided_by_name_snapshot }} on {{ $discountRequest->decided_at->format('M j, Y g:i A') }}</p>@endif
                    @if($expired)<p class="mt-2 font-semibold text-amber-800">{{ $discountRequest->decided_at ? 'Expired unspent' : 'Expired without a decision' }} &middot; {{ $discountRequest->expired_at?->timezone(config('business.timezone'))->format('M j, Y g:i A') }}</p>@endif
                    @if($discountRequest->self_approved)<p class="mt-2 text-sm text-amber-800">Self-approved: the requester was the business's only administrator.</p>@endif
                    @if($discountRequest->decision_note)<p class="mt-2 text-slate-700">{{ $discountRequest->decision_note }}</p>@endif
                </div>
            @endif
            @if($discountRequest->isApprovalExpired())
                <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <p class="font-bold text-amber-900">Approval expired</p>
                    <p class="mt-2 text-sm text-amber-900">This approval lasted until the end of the business day it was granted and was not used. A new request is needed for this cart.</p>
                </div>
            @elseif($discountRequest->status === \App\Enums\DiscountRequestStatus::Approved && $sale === null)
                <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                    <p class="font-bold text-emerald-900">Approved for a cart not yet recorded</p>
                    <p class="mt-2 text-sm text-emerald-900">&#8358;{{ \App\Support\Money::format($discountRequest->requested_amount) }} off a subtotal of &#8358;{{ \App\Support\Money::format($draft?->subtotal_snapshot ?? '0') }}. It applies only when this exact cart, buyer and sale date are recorded, and only once.</p>
                </div>
            @elseif($discountRequest->status === \App\Enums\DiscountRequestStatus::Approved)
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
            @if($sale)
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
            @endif

            @if($pending && $discountRequest->isPendingExpired())
                <section class="rounded-2xl border border-amber-200 bg-amber-50 p-6"><p class="text-sm font-semibold text-amber-900">This request expired {{ $discountRequest->pendingExpiresAt()->timezone(config('business.timezone'))->format('M j, Y g:i A') }} without a decision and can no longer be approved or declined. Raise a new request if it is still needed.</p></section>
            @elseif($pending)
                @can('approve', $discountRequest)
                    <section class="rounded-2xl border border-emerald-200 bg-white p-6 shadow-sm">
                        <h2 class="font-bold">Approve</h2>
                        @if((int) auth()->id() === (int) $discountRequest->requested_by)
                            <p class="mt-1 text-sm font-semibold text-amber-800">You raised this request. As the business's only administrator you may approve it yourself; it will be recorded as self-approved.</p>
                        @endif
                        <p class="mt-1 text-sm text-slate-600">@if($sale)This reduces the Sale total by &#8358;{{ \App\Support\Money::format($discountRequest->requested_amount) }} and recalculates the balance. Recorded payments are left exactly as they are.@else This authorises &#8358;{{ \App\Support\Money::format($discountRequest->requested_amount) }} off this cart when it is recorded. Nothing is sold, charged or moved in stock until then.@endif</p>
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
                @can('cancel', $discountRequest)
                    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="font-bold">Cancel request</h2>
                        <p class="mt-1 text-sm text-slate-600">Withdraws the request before a decision. Nothing about the Sale changes.</p>
                        <form method="POST" action="{{ route('discounts.cancel', $discountRequest) }}" data-submit-once data-confirm-message="Cancel this discount request?" class="mt-4">
                            @csrf
                            <button class="w-full rounded-lg border border-slate-300 px-4 py-2.5 font-semibold text-slate-700">Cancel request</button>
                        </form>
                    </section>
                @endcan
            @endif
        </div>
    </div>
</x-app-layout>
