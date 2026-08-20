<x-app-layout title="Add staff">
    <div class="mb-6">
        <a href="{{ route('staff.index') }}" class="text-sm font-semibold text-[#0b56c9]">← Staff accounts</a>
        <h1 class="mt-3 text-3xl font-bold">Add staff account</h1>
        <p class="mt-2 text-slate-600">Create a Manager or Sales Representative. Administrator accounts can only be bootstrapped from the command line.</p>
    </div>
    <form method="POST" action="{{ route('staff.store') }}" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        @include('staff._form', ['submitLabel' => 'Create staff account'])
    </form>
</x-app-layout>
