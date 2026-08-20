@props(['status'])
@php
    $classes = match ($status->value) {
        'active' => 'bg-emerald-100 text-emerald-800',
        'inactive' => 'bg-slate-200 text-slate-700',
        'locked' => 'bg-red-100 text-red-800',
    };
@endphp
<span {{ $attributes->class("inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {$classes}") }}>{{ ucfirst($status->value) }}</span>
