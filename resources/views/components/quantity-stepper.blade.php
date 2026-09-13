@props(['name', 'label', 'value' => '0', 'step' => '1', 'min' => '0'])
{{--
    A − / + pair around an ordinary number input. The input carries the name and submits its own
    value, so the field still works with JavaScript unavailable and the server stays the only
    authority on what quantity is acceptable. Both buttons are real, focusable buttons with their
    own accessible names, so the control is fully keyboard operable.
--}}
<div class="ui-stepper" x-data="stepper">
    <button type="button" x-on:click="down" aria-label="Decrease {{ $label }}" data-tooltip="Decrease {{ $label }}">&minus;</button>
    <input
        type="number"
        id="{{ $name }}"
        name="{{ $name }}"
        value="{{ \App\Support\OldInput::scalar($name, $value) }}"
        step="{{ $step }}"
        min="{{ $min }}"
        inputmode="decimal"
        x-ref="input"
        @if($errors->has($name)) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif
        {{ $attributes }}
    >
    <button type="button" x-on:click="up" aria-label="Increase {{ $label }}" data-tooltip="Increase {{ $label }}">+</button>
</div>
