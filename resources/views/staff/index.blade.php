<x-app-layout title="Staff accounts">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div><p class="text-sm font-semibold uppercase tracking-[0.18em] text-[#0b56c9]">Administration</p><h1 class="mt-2 text-3xl font-bold">Staff accounts</h1><p class="mt-2 text-slate-600">Manage access, account state, and staff security activity.</p></div>
        <a href="{{ route('staff.create') }}" class="rounded-xl bg-[#0b56c9] px-5 py-3 font-semibold text-white shadow-sm hover:bg-[#0848aa]">Add staff</a>
    </div>
    <form method="GET" class="mt-7 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-4">
        <input name="search" value="{{ is_string(request('search')) ? request('search') : '' }}" placeholder="Name, email, or phone" class="rounded-xl border border-slate-300 px-4 py-2.5 outline-none focus:border-[#0b56c9] focus:ring-4 focus:ring-blue-100">
        <select name="role" class="rounded-xl border border-slate-300 px-4 py-2.5"><option value="">All roles</option>@foreach (\App\Enums\UserRole::cases() as $role)<option value="{{ $role->value }}" @selected(request('role') === $role->value)>{{ $role->label() }}</option>@endforeach</select>
        <select name="status" class="rounded-xl border border-slate-300 px-4 py-2.5"><option value="">All statuses</option>@foreach (\App\Enums\UserStatus::cases() as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ ucfirst($status->value) }}</option>@endforeach</select>
        <button class="rounded-xl bg-slate-900 px-4 py-2.5 font-semibold text-white">Apply filters</button>
    </form>
    <div class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr><th class="px-5 py-4">Staff name</th><th class="px-5 py-4">Email address</th><th class="px-5 py-4">Phone</th><th class="px-5 py-4">Role</th><th class="px-5 py-4">Status</th><th class="px-5 py-4">Last login</th><th class="px-5 py-4"><span class="sr-only">Actions</span></th></tr></thead>
        <tbody class="divide-y divide-slate-100">@forelse ($staff as $staffMember)<tr class="hover:bg-slate-50"><td class="px-5 py-4 font-semibold">{{ $staffMember->name }}</td><td class="px-5 py-4 text-slate-600">{{ $staffMember->email }}</td><td class="px-5 py-4 text-slate-600">{{ $staffMember->phone ?: '—' }}</td><td class="px-5 py-4">{{ $staffMember->role->label() }}</td><td class="px-5 py-4"><x-status-badge :status="$staffMember->status" /></td><td class="px-5 py-4 text-slate-600">{{ $staffMember->last_login_at?->diffForHumans() ?? 'Never' }}</td><td class="px-5 py-4 text-right"><a href="{{ route('staff.show', $staffMember) }}" class="font-semibold text-[#0b56c9]">View</a></td></tr>@empty<tr><td colspan="7" class="px-6 py-16 text-center text-slate-500">No staff accounts match these filters.</td></tr>@endforelse</tbody>
    </table></div>@if ($staff->hasPages()) <div class="border-t border-slate-200 p-4">{{ $staff->links() }}</div> @endif</div>
</x-app-layout>
