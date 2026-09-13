@props(['label', 'name', 'required' => false, 'hint' => null, 'for' => null, 'full' => false])
{{--
    Label, control slot, hint and error for one field. The label is a real <label> bound to the
    control's id, and the error is rendered next to the field it belongs to so a validation failure
    never reflows the grid.
--}}
<div {{ $attributes->class(['ui-field', 'is-full' => $full]) }}>
    <label class="ui-field-label" for="{{ $for ?? $name }}">
        {{ $label }}@if($required)<span class="is-required" aria-hidden="true">*</span>@endif
    </label>
    {{ $slot }}
    @error($name)<p class="ui-field-error" id="{{ $for ?? $name }}-error">{{ $message }}</p>@enderror
    @if($hint)<p class="ui-field-hint" id="{{ $for ?? $name }}-hint">{{ $hint }}</p>@endif
</div>
