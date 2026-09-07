<x-app-layout :title="$alert->title">
<a href="{{ route('notifications.index') }}" class="font-semibold text-[#0b56c9]">← Notifications</a>

<div class="mt-4 max-w-3xl rounded-2xl border bg-white p-6">
    <p class="flex flex-wrap items-center gap-2">
        <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $alert->severity->badgeClasses() }}">{{ $alert->severity->label() }}</span>
        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">{{ $alert->status->label() }}</span>
        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">{{ $alert->type->label() }}</span>
        @if($notification->read_at === null)<span class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-800">Unread</span>@endif
        @if($notification->acknowledged_at !== null)<span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">Acknowledged</span>@endif
    </p>

    <h2 class="mt-4 text-2xl font-bold">{{ $alert->title }}</h2>
    <p class="mt-2 text-slate-700">{{ $alert->message }}</p>

    <dl class="mt-6 grid gap-3 border-t pt-5 text-sm md:grid-cols-2">
        <div class="flex justify-between border-b py-2"><dt class="text-slate-500">Subject</dt><dd class="font-medium">{{ $alert->subject_label_snapshot }}</dd></div>
        <div class="flex justify-between border-b py-2"><dt class="text-slate-500">First detected</dt><dd class="font-medium">{{ $alert->first_detected_at->timezone(config('business.timezone'))->format('d M Y g:i A') }}</dd></div>
        <div class="flex justify-between border-b py-2"><dt class="text-slate-500">Occurrence</dt><dd class="font-medium">{{ $alert->occurrence }}</dd></div>
        <div class="flex justify-between border-b py-2"><dt class="text-slate-500">Resolved</dt><dd class="font-medium">{{ $alert->resolved_at?->timezone(config('business.timezone'))->format('d M Y g:i A') ?? 'Still active' }}</dd></div>
        <div class="flex justify-between border-b py-2"><dt class="text-slate-500">Read</dt><dd class="font-medium">{{ $notification->read_at?->timezone(config('business.timezone'))->format('d M Y g:i A') ?? 'Not yet' }}</dd></div>
        <div class="flex justify-between border-b py-2"><dt class="text-slate-500">Acknowledged</dt><dd class="font-medium">{{ $notification->acknowledged_at?->timezone(config('business.timezone'))->format('d M Y g:i A') ?? 'Not yet' }}</dd></div>
    </dl>

    <div class="mt-6 flex flex-wrap gap-3">
        @if($notification->read_at === null)
            <form method="POST" action="{{ route('notifications.read', $notification) }}">@csrf
                <button class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Mark as read</button>
            </form>
        @endif
        @if($notification->acknowledged_at === null)
            <form method="POST" action="{{ route('notifications.acknowledge', $notification) }}">@csrf
                <button class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Acknowledge</button>
            </form>
        @endif
        @if($subjectUrl !== null)
            <a href="{{ $subjectUrl }}" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-[#0b56c9]">Open {{ $alert->subject_type }}</a>
        @else
            <span class="rounded-xl border border-dashed border-slate-300 px-4 py-2 text-sm text-slate-500">Subject is no longer available</span>
        @endif
    </div>

    <p class="mt-6 border-t pt-4 text-xs text-slate-500">Acknowledging records that you are aware of this alert. It does not resolve it — an alert resolves only when the underlying business condition clears.</p>
</div>
</x-app-layout>
