<x-app-layout title="Audit event"><a class="text-sm font-semibold text-blue-700" href="{{ route('audit.index') }}">← Audit trail</a>
<h2 class="mt-3 text-2xl font-bold">{{ str($event->action)->replace('_',' ')->title() }}</h2>
<p class="text-slate-500">{{ $event->action }} · recorded {{ $event->created_at->timezone(config('business.timezone'))->format('d M Y g:i A') }} ({{ config('business.timezone') }})</p>

<div class="mt-6 grid gap-4 md:grid-cols-2">
<div class="rounded-2xl border bg-white p-5"><h3 class="font-bold">Actor</h3>
<p class="mt-2">{{ $event->actor_name_snapshot ?? $event->actor?->name ?? 'System' }}</p>
<p class="text-sm text-slate-500">{{ $event->actor_role_snapshot ? str($event->actor_role_snapshot)->replace('_',' ')->title() : 'System / automated' }}</p>
@if($event->ip_address)<p class="mt-2 text-sm text-slate-500">IP {{ $event->ip_address }}</p>@endif
@if($event->user_agent)<p class="mt-1 break-all text-sm text-slate-500">{{ $event->user_agent }}</p>@endif
</div>
<div class="rounded-2xl border bg-white p-5"><h3 class="font-bold">Subject</h3>
<p class="mt-2">{{ $event->subject_label_snapshot ?? '—' }}</p>
<p class="text-sm text-slate-500">{{ class_basename($event->auditable_type) }} · reference {{ $event->auditable_id }}</p>
<p class="mt-2 text-sm text-slate-500">Snapshots are historical evidence and are not refreshed if the record later changes.</p>
</div>
</div>

@foreach(['Before' => $event->old_values, 'After' => $event->new_values, 'Context' => $event->metadata] as $heading => $values)
@if(is_array($values) && $values !== [])
<div class="mt-6 rounded-2xl border bg-white p-5"><h3 class="font-bold">{{ $heading }}</h3>
<dl class="mt-3 grid gap-2 md:grid-cols-2">@foreach($values as $key => $value)
<div class="flex justify-between gap-4 border-b py-2"><dt class="text-slate-500">{{ str($key)->replace('_',' ')->title() }}</dt><dd class="text-right font-medium">{{ is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value }}</dd></div>
@endforeach</dl></div>
@endif
@endforeach
</x-app-layout>
