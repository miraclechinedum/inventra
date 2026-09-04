@props(['label', 'name', 'type' => 'text', 'autocomplete' => null, 'value' => null])

<label class="block">
    <span class="inventra-field-label">{{ $label }}</span>
    <input
        type="{{ $type }}"
        name="{{ $name }}"
        value="{{ $type === 'password' ? '' : \App\Support\OldInput::scalar($name, $value) }}"
        @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
        {{ $attributes->class([
            'inventra-input',
            'border-red-400' => $errors->has($name),
            'border-slate-300' => ! $errors->has($name),
        ]) }}
    >
    @error($name)
        <span class="inventra-field-error">{{ $message }}</span>
    @enderror
</label>
