<x-app-layout :title="$customer->full_name.' activity'">
    <a href="{{ route('customers.show', $customer) }}" class="text-sm font-semibold text-[#0b56c9]">← Customer</a>
    <h1 class="mt-3 text-3xl font-bold">Customer activity</h1>
    <p class="mt-2 text-slate-600">Business changes for {{ $customer->full_name }} only.</p>
    <div class="mt-7 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div class="divide-y divide-slate-100">@forelse($events as $event)<article class="p-5"><div class="flex flex-wrap justify-between gap-2"><p class="flex items-center gap-2 font-semibold"><span class="ui-feed-ordinal">{{ $events->firstItem() + $loop->index }}</span>{{ str($event->action)->replace('_', ' ')->title() }}</p><time class="text-sm text-slate-500">{{ $event->created_at->format('M j, Y g:i A') }}</time></div><p class="mt-1 text-sm text-slate-600">Actor: {{ $event->actor?->name ?? 'System' }}</p></article>@empty<p class="p-12 text-center text-slate-500">No customer activity recorded.</p>@endforelse</div>@if($events->hasPages())<x-table-footer :paginator="$events" noun="event" />@endif</div>
</x-app-layout>
