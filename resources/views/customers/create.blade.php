<x-app-layout title="Add customer">
    <a href="{{ route('customers.index') }}" class="text-sm font-semibold text-[#0b56c9]">← Customers</a>
    <h1 class="mt-3 text-3xl font-bold">Add customer</h1>
    <p class="mt-2 text-slate-600">Create an active customer profile. WhatsApp consent is recorded separately.</p>
    <form method="POST" action="{{ route('customers.store') }}" class="mt-7 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        @csrf
        @include('customers._form', ['submitLabel' => 'Create customer'])
    </form>
</x-app-layout>
