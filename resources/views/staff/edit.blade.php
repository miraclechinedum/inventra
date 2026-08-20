<x-app-layout title="Edit staff">
    <div class="mb-6">
        <a href="{{ route('staff.show', $staffMember) }}" class="text-sm font-semibold text-[#0b56c9]">← Staff details</a>
        <h1 class="mt-3 text-3xl font-bold">Edit {{ $staffMember->name }}</h1>
        <p class="mt-2 text-slate-600">Update basic contact details. Role and account-state changes are controlled separately.</p>
    </div>
    <form method="POST" action="{{ route('staff.update', $staffMember) }}" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        @include('staff._form', ['submitLabel' => 'Save profile', 'method' => 'PUT'])
    </form>
</x-app-layout>
