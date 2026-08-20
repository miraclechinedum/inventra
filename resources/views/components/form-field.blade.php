@props(['label', 'name', 'type' => 'text', 'autocomplete' => null, 'value' => null])

<label class="block">
    <span class="text-sm font-medium text-slate-700">{{ $label }}</span>
    <input
        type="{{ $type }}"
        name="{{ $name }}"
        value="{{ $type === 'password' ? '' : old($name, $value) }}"
        @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
        {{ $attributes->class([
            'mt-2 block w-full rounded-xl border bg-white px-4 py-3 text-slate-950 shadow-sm outline-none transition focus:border-[#0b56c9] focus:ring-4 focus:ring-blue-100',
            'border-red-400' => $errors->has($name),
            'border-slate-300' => ! $errors->has($name),
        ]) }}
    >
    @error($name)
        <span class="mt-2 block text-sm text-red-600">{{ $message }}</span>
    @enderror
</label>
