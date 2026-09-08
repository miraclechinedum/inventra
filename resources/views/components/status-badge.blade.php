@props(['status'])
@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $tone = match ($value) {
        'active', 'paid', 'completed', 'delivered', 'read' => 'success',
        'partial', 'pending', 'accepted', 'sent' => 'warning',
        'locked', 'failed', 'voided', 'unresolved' => 'danger',
        default => 'neutral',
    };
@endphp
<span {{ $attributes->class('ui-badge') }} data-tone="{{ $tone }}">{{ ucfirst($value) }}</span>
