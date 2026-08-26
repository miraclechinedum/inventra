<x-app-layout :title="'Edit '.$customer->full_name">
    <a href="{{ route('customers.show', $customer) }}" class="text-sm font-semibold text-[#0b56c9]">← Customer</a>
    <h1 class="mt-3 text-3xl font-bold">Edit customer</h1>
    <form method="POST" action="{{ route('customers.update', $customer) }}" class="mt-7 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        @csrf
        @method('PUT')
        @include('customers._form', ['submitLabel' => 'Save changes'])
    </form>
</x-app-layout>
