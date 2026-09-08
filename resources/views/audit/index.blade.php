<x-app-layout title="Audit trail"><div><h2 class="text-2xl font-bold">Audit trail</h2><p class="text-slate-500">Append-only record of business mutations · {{ $filters->from }} through {{ $filters->to }} inclusive · {{ config('business.timezone') }}</p></div>

<form method="GET" class="ui-filter-bar mt-6 grid gap-3 rounded-2xl border bg-white p-4 md:grid-cols-3 lg:grid-cols-6">
<label class="text-sm">From<input aria-label="From date" class="mt-1 w-full rounded-xl border-slate-300" type="date" name="from" value="{{ $filters->from }}"></label>
<label class="text-sm">To<input aria-label="To date" class="mt-1 w-full rounded-xl border-slate-300" type="date" name="to" value="{{ $filters->to }}"></label>
<label class="text-sm">Actor<select aria-label="Actor" class="mt-1 w-full rounded-xl border-slate-300" name="actor"><option value="">All actors</option>@foreach($actorOptions as $actor)<option value="{{ $actor->id }}" @selected($filters->actor == $actor->id)>{{ $actor->name }}</option>@endforeach</select></label>
<label class="text-sm">Event<select aria-label="Event" class="mt-1 w-full rounded-xl border-slate-300" name="event"><option value="">All events</option>@foreach($eventOptions as $event)<option value="{{ $event }}" @selected($filters->event === $event)>{{ str($event)->replace('_',' ')->title() }}</option>@endforeach</select></label>
<label class="text-sm">Subject<select aria-label="Subject type" class="mt-1 w-full rounded-xl border-slate-300" name="subject_type"><option value="">All subjects</option>@foreach($subjectTypes as $type)<option value="{{ $type }}" @selected($filters->subjectType === $type)>{{ str($type)->replace('_',' ')->title() }}</option>@endforeach</select></label>
<label class="text-sm">Search<input aria-label="Search records" class="mt-1 w-full rounded-xl border-slate-300" type="text" name="search" value="{{ $filters->search }}" placeholder="SALE-000123, CUST-000040, SKU, actor"></label>
<button class="self-end rounded-xl bg-slate-800 px-4 py-3 text-white md:col-span-3 lg:col-span-1">Apply</button></form>
<div class="mt-2 text-right"><x-filter-reset /></div>
@error('from')<p class="mt-2 text-red-700">{{ $message }}</p>@enderror

<p class="mt-4 text-sm text-slate-500">Audit history is permanent and cannot be edited or deleted.</p>

<div class="mt-4 overflow-x-auto rounded-2xl border bg-white"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="ui-sn">S/N</th><th class="p-4">Event</th><th class="p-4">Subject</th><th class="p-4">Actor</th><th class="p-4">Recorded at</th></tr></thead><tbody>
@forelse($events as $event)<tr class="border-b align-top">
<td class="ui-sn">{{ $events->firstItem() + $loop->index }}</td><td class="p-4"><a class="font-semibold text-blue-700" href="{{ route('audit.show', $event) }}">{{ str($event->action)->replace('_',' ')->title() }}</a><br><small class="text-slate-500">{{ $event->action }}</small></td>
<td class="p-4">{{ $event->subject_label_snapshot ?? '—' }}<br><small class="text-slate-500">{{ class_basename($event->auditable_type) }}</small></td>
<td class="p-4">{{ $event->actor_name_snapshot ?? $event->actor?->name ?? 'System' }}<br><small class="text-slate-500">{{ $event->actor_role_snapshot ? str($event->actor_role_snapshot)->replace('_',' ')->title() : 'System' }}</small></td>
<td class="p-4 whitespace-nowrap">{{ $event->created_at->timezone(config('business.timezone'))->format('d M Y g:i A') }}</td>
</tr>@empty<tr><td colspan="5" class="p-8 text-center text-slate-500"><x-empty-state title="No audit events match these filters." description="Try another search or adjust your filters." /></td></tr>@endforelse
</tbody></table></div><x-table-footer :paginator="$events" noun="event" />
</x-app-layout>
