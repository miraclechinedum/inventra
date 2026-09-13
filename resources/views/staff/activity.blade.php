<x-app-layout title="Staff activity">
    <a href="{{ route('staff.show', $staffMember) }}" class="text-sm font-semibold text-[#0b56c9]">← {{ $staffMember->name }}</a>
    <div class="mt-3 flex items-start gap-4">
        <x-entity-image :url="$staffMember->photo_path ? route('users.photo', $staffMember) : null" :label="$staffMember->name" size="h-14 w-14" rounded="rounded-full" />
        <div>
            <h1 class="text-3xl font-bold">{{ $staffMember->name }}</h1>
            <p class="mt-1 text-slate-600">{{ $staffMember->role->label() }} · Last signed in {{ $staffMember->last_login_at?->diffForHumans() ?? 'never' }}</p>
        </div>
    </div>

    {{-- Work activity: every figure is derived from the business records themselves. --}}
    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-bold">Work activity</h2>
                <p class="mt-1 text-sm text-slate-600">{{ $activity['from']->format('M j, Y') }} &ndash; {{ $activity['to']->format('M j, Y') }}. Counted directly from sales, payments, returns, refunds, expenses, purchases and stock records.</p>
            </div>
            <form method="GET" class="flex flex-wrap items-end gap-2">
                <label class="text-xs font-semibold text-slate-500">From<input type="date" name="from" value="{{ $activity['from']->toDateString() }}" class="mt-1 block rounded-xl border-slate-300"></label>
                <label class="text-xs font-semibold text-slate-500">To<input type="date" name="to" value="{{ $activity['to']->toDateString() }}" class="mt-1 block rounded-xl border-slate-300"></label>
                <button class="rounded-xl bg-slate-800 px-4 py-2.5 text-sm font-semibold text-white">Apply</button>
            </form>
        </div>

        <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($activity['metrics'] as $metric)
                <div class="rounded-xl border border-[#e7ebf0] p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500">{{ $metric['label'] }}</p>
                    <p class="mt-1 text-2xl font-bold">{{ number_format((int) $metric['count']) }}</p>
                    @if($metric['value'] !== null)<p class="mt-1 text-sm font-semibold text-slate-700">&#8358;{{ \App\Support\Money::format($metric['value']) }}</p>@endif
                    <p class="mt-1 text-xs text-slate-500">{{ $metric['note'] }}</p>
                </div>
            @endforeach
        </div>
    </section>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="font-bold">Most recent audited actions</h2>
            <p class="mt-1 text-sm text-slate-600">The latest entries this person authored in the audit trail, within the window above.</p>
            <div class="mt-4 divide-y divide-slate-100">
                @forelse($activity['recent'] as $entry)
                    <div class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                        <span><strong>{{ str($entry->action)->replace('_', ' ')->title() }}</strong>@if($entry->subject_label_snapshot)<span class="text-slate-500"> · {{ $entry->subject_label_snapshot }}</span>@endif</span>
                        <time class="text-slate-500">{{ \Illuminate\Support\Carbon::parse($entry->created_at)->format('M j, g:i A') }}</time>
                    </div>
                @empty
                    <p class="py-6 text-center text-slate-500">No audited actions in this period.</p>
                @endforelse
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="p-6 pb-0"><h2 class="font-bold">Security activity</h2><p class="mt-1 text-sm text-slate-600">Account and administrative events, across all time.</p></div>
            <div class="mt-4 divide-y divide-slate-100">
                @forelse ($events as $event)
                    <article class="flex flex-wrap justify-between gap-3 px-6 py-5">
                        <div>
                            <p class="flex items-center gap-2 font-semibold"><span class="ui-feed-ordinal">{{ $events->firstItem() + $loop->index }}</span>{{ str($event->event)->replace('_', ' ')->title() }}</p>
                            <p class="mt-1 text-sm text-slate-500">Actor: {{ $event->actor?->name ?? 'System or account owner' }} · IP: {{ $event->ip_address ?? 'Not recorded' }}</p>
                        </div>
                        <time class="text-sm text-slate-500">{{ $event->created_at->format('M j, Y g:i A') }}</time>
                    </article>
                @empty
                    <p class="px-6 py-16 text-center text-slate-500">No security activity has been recorded.</p>
                @endforelse
            </div>
            <x-table-footer :paginator="$events" noun="event" />
        </section>
    </div>
</x-app-layout>
