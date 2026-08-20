@props(['action', 'label', 'message', 'destructive' => false])
<details class="relative inline-block">
    <summary class="cursor-pointer list-none rounded-lg border px-3 py-2 text-xs font-semibold {{ $destructive ? 'border-red-200 text-red-700 hover:bg-red-50' : 'border-slate-300 text-slate-700 hover:bg-slate-50' }}">{{ $label }}</summary>
    <div class="absolute right-0 z-20 mt-2 w-72 rounded-xl border border-slate-200 bg-white p-4 shadow-xl">
        <p class="text-sm text-slate-700">{{ $message }}</p>
        <form method="POST" action="{{ $action }}" class="mt-4">
            @csrf
            <button class="w-full rounded-lg px-3 py-2 text-sm font-semibold text-white {{ $destructive ? 'bg-red-600 hover:bg-red-700' : 'bg-[#0b56c9] hover:bg-[#0848aa]' }}">Confirm {{ strtolower($label) }}</button>
        </form>
    </div>
</details>
