<x-app-layout title="Staff account created">
    <div class="mx-auto max-w-2xl">
        <section class="rounded-2xl border-2 border-amber-300 bg-amber-50 p-6 shadow-sm sm:p-8">
            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-800">One-time credential</p>
            <h1 class="mt-3 text-3xl font-bold text-amber-950">Staff account created successfully.</h1>
            <p class="mt-3 text-amber-900">Temporary password for {{ $staffMember->name }}:</p>
            <code class="mt-3 block break-all rounded-xl border border-amber-200 bg-white px-4 py-4 text-xl font-bold text-slate-950">{{ $temporaryPassword }}</code>
            <p class="mt-4 font-semibold text-amber-950">This password will not be shown again. The staff member must change it during first login.</p>
            <p class="mt-2 text-sm text-amber-800">Copy it now and deliver it through an approved secure channel.</p>
        </section>
        <div class="mt-6 flex flex-wrap gap-3">
            <a href="{{ route('staff.show', $staffMember) }}" class="rounded-xl bg-[#0b56c9] px-5 py-3 font-semibold text-white">View staff account</a>
            <a href="{{ route('staff.create') }}" class="rounded-xl border border-slate-300 bg-white px-5 py-3 font-semibold text-slate-700">Add another staff member</a>
        </div>
    </div>
</x-app-layout>
