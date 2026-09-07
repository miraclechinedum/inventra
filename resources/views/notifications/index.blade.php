<x-app-layout title="Notifications">
<div class="flex flex-wrap items-start justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold">Notifications</h2>
        <p class="text-slate-500">Operational alerts delivered to you. Alerts resolve themselves when the underlying condition clears.</p>
    </div>
    @if($unreadCount > 0)
        <form method="POST" action="{{ route('notifications.read-all') }}">@csrf
            <button class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Mark all as read</button>
        </form>
    @endif
</div>

<form method="GET" action="{{ route('notifications.index') }}" class="mt-6 flex flex-wrap items-end gap-3 rounded-2xl border bg-white p-4">
    <label class="text-sm">Status
        <select name="status" class="mt-1 block rounded-xl border-slate-300">
            <option value="">All</option>
            @foreach(\App\Enums\OperationalAlertStatus::cases() as $case)
                <option value="{{ $case->value }}" @selected($filters->status === $case->value)>{{ $case->label() }}</option>
            @endforeach
        </select>
    </label>
    <label class="text-sm">Read state
        <select name="read" class="mt-1 block rounded-xl border-slate-300">
            <option value="">All</option>
            @foreach(['unread' => 'Unread', 'read' => 'Read', 'acknowledged' => 'Acknowledged'] as $value => $label)
                <option value="{{ $value }}" @selected($filters->readState === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="text-sm">Severity
        <select name="severity" class="mt-1 block rounded-xl border-slate-300">
            <option value="">All</option>
            @foreach(\App\Enums\OperationalAlertSeverity::cases() as $case)
                <option value="{{ $case->value }}" @selected($filters->severity === $case->value)>{{ $case->label() }}</option>
            @endforeach
        </select>
    </label>
    <label class="text-sm">Type
        <select name="type" class="mt-1 block rounded-xl border-slate-300">
            <option value="">All</option>
            @foreach(\App\Enums\OperationalAlertType::cases() as $case)
                <option value="{{ $case->value }}" @selected($filters->type === $case->value)>{{ $case->label() }}</option>
            @endforeach
        </select>
    </label>
    <button class="rounded-xl bg-slate-800 px-5 py-2 text-sm font-semibold text-white">Apply</button>
    @if($filters->isActive())
        <a href="{{ route('notifications.index') }}" class="text-sm font-semibold text-[#0b56c9]">Clear filters</a>
    @endif
</form>

<div class="mt-6 overflow-hidden rounded-2xl border bg-white">
@forelse($notifications as $notification)
    <article class="flex flex-wrap items-start justify-between gap-4 border-b p-5 last:border-b-0 {{ $notification->read_at === null ? 'bg-blue-50/40' : '' }}">
        <div class="min-w-0 flex-1">
            <p class="flex flex-wrap items-center gap-2">
                <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $notification->alert->severity->badgeClasses() }}">{{ $notification->alert->severity->label() }}</span>
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">{{ $notification->alert->status->label() }}</span>
                @if($notification->read_at === null)<span class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-800">Unread</span>@endif
                @if($notification->acknowledged_at !== null)<span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">Acknowledged</span>@endif
            </p>
            <h3 class="mt-2 font-semibold"><a class="text-[#0b56c9]" href="{{ route('notifications.show', $notification) }}">{{ $notification->alert->title }}</a></h3>
            <p class="mt-1 text-sm text-slate-600">{{ $notification->alert->message }}</p>
            <p class="mt-1 text-xs text-slate-500">
                {{ $notification->alert->subject_label_snapshot }} ·
                {{ $notification->alert->created_at->timezone(config('business.timezone'))->format('d M Y g:i A') }}
                @if($notification->alert->occurrence > 1) · occurrence {{ $notification->alert->occurrence }} @endif
            </p>
        </div>
        <div class="flex shrink-0 gap-2">
            @if($notification->read_at === null)
                <form method="POST" action="{{ route('notifications.read', $notification) }}">@csrf
                    <button class="rounded-xl border border-slate-300 px-3 py-2 text-sm" aria-label="Mark &quot;{{ $notification->alert->title }}&quot; as read">Mark read</button>
                </form>
            @endif
            @if($notification->acknowledged_at === null)
                <form method="POST" action="{{ route('notifications.acknowledge', $notification) }}">@csrf
                    <button class="rounded-xl bg-slate-800 px-3 py-2 text-sm font-semibold text-white" aria-label="Acknowledge &quot;{{ $notification->alert->title }}&quot;">Acknowledge</button>
                </form>
            @endif
        </div>
    </article>
@empty
    <p class="p-8 text-center text-slate-500">
        @if($filters->isActive())
            No notifications match these filters.
        @elseif($unreadCount === 0 && $notifications->total() === 0)
            You have no notifications. Operational alerts appear here when stock runs low, a Sale carries a balance, or the ledger disagrees with itself.
        @else
            You have no unread notifications.
        @endif
    </p>
@endforelse
</div>

<div class="mt-6">{{ $notifications->links() }}</div>
</x-app-layout>
