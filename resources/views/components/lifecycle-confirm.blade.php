@props([
    'action',
    'label',
    'title',
    'message',
    'method' => 'POST',
    'tone' => 'warning',
    'confirmLabel' => null,
    'cancelLabel' => 'Cancel',
    'triggerLabel' => null,
])
{{--
    One confirmation dialog for every consequential lifecycle action, wherever the trigger lives.

    It replaces `x-confirm-action`'s floating popover for these actions because that popover is
    positioned relative to its trigger, so any clipping ancestor — the Edit Product modal's
    scrollable body, or the inventory table's `overflow-x: auto` wrapper — cuts it off. This dialog
    is `position: fixed`, so it is laid out against the viewport and no ancestor's overflow reaches
    it.

    `tone` carries the intent, and drives both the trigger and the confirm button:
      - "primary": reactivate — a positive, restorative action.
      - "warning" (default): archive — reversible, but worth pausing over.
      - "danger": permanent delete — irreversible.

    Passing an `icon` slot renders the trigger as a compact square icon control (for a table row)
    rather than a labelled button; `triggerLabel` is then both its accessible name and its tooltip.
--}}
@php
    $confirm = $confirmLabel ?? $label;
    $dialogId = 'confirm-'.substr(md5($action.$label), 0, 10);
    $titleId = $dialogId.'-title';
    $descId = $dialogId.'-desc';
    $confirmClass = match ($tone) {
        'danger' => 'ui-button-danger',
        'primary' => 'ui-button-primary',
        default => 'ui-button-warning',
    };
@endphp
<span x-data="lifecycleConfirm">
    @if(isset($icon))
        <button type="button" @class(['ui-icon-action', 'is-danger' => $tone === 'danger'])
            x-on:click="openDialog"
            x-bind:aria-expanded="open ? 'true' : 'false'" aria-haspopup="dialog"
            aria-label="{{ $triggerLabel ?? $label }}" data-tooltip="{{ $triggerLabel ?? $label }}">
            {{ $icon }}
        </button>
    @else
        <button type="button" class="ui-button {{ $confirmClass }}"
            x-on:click="openDialog" x-bind:aria-expanded="open ? 'true' : 'false'" aria-haspopup="dialog">
            {{ $label }}
        </button>
    @endif

    {{-- The Escape listener lives on the dialog itself, not the outer span: it is reachable only
         while the dialog is open (and therefore rendered and focus is inside it), so it can never
         steal an Escape keypress meant for the Edit Product modal underneath while this dialog is
         closed. `.stop` then keeps that keypress from also reaching the modal's own window-level
         Escape handler once this dialog IS open, which is what stops one Escape press from closing
         both layers at once. --}}
    <div class="ui-confirm-dialog" x-cloak x-show="open" x-on:keydown.escape.stop="close" x-on:keydown.tab="trapFocus"
        role="dialog" aria-modal="true" aria-labelledby="{{ $titleId }}" aria-describedby="{{ $descId }}">
        <div class="ui-confirm-backdrop" x-on:click="close" aria-hidden="true"></div>
        <div class="ui-confirm-panel" x-ref="panel">
            <h2 id="{{ $titleId }}">{{ $title }}</h2>
            <p id="{{ $descId }}">{{ $message }}</p>
            <div class="ui-confirm-actions">
                <button type="button" class="ui-button" x-on:click="close">{{ $cancelLabel }}</button>
                <form method="POST" action="{{ $action }}" x-on:submit="submit">
                    @csrf
                    @if (strtoupper($method) !== 'POST')
                        @method($method)
                    @endif
                    <button type="submit" class="ui-button {{ $confirmClass }}"
                        x-ref="confirmButton" x-bind:disabled="submitting">
                        {{ $confirm }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</span>
