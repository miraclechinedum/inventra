<button
    {{ $attributes->class('inline-flex w-full items-center justify-center rounded-xl bg-[#0b56c9] px-5 py-3 font-semibold text-white shadow-sm transition hover:bg-[#0848aa] focus:outline-none focus:ring-4 focus:ring-blue-200 disabled:cursor-wait disabled:opacity-70') }}
>
    {{ $slot }}
</button>
