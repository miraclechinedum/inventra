{{--
  A sortable column heading. `key` must be one of the keys the controller allowlists for this
  listing; anything else is ignored server-side and the list keeps its default ordering. Clicking
  the active column flips the direction; `page` is dropped so a new sort starts at page one.
--}}
@props(['key', 'label', 'active', 'direction', 'align' => 'left'])
@php
    $isActive = $active === $key;
    $next = $isActive && $direction === 'asc' ? 'desc' : 'asc';
    $target = url()->current().'?'.http_build_query(array_merge(
        collect(request()->query())->except(['page', 'sort', 'direction'])->all(),
        ['sort' => $key, 'direction' => $next],
    ));
@endphp
<th @class(['ui-sortable', 'is-right' => $align === 'right']) aria-sort="{{ $isActive ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}">
    <a href="{{ $target }}" class="ui-sort-link">
        <span>{{ $label }}</span>
        <span class="ui-sort-indicator" aria-hidden="true">@if($isActive){{ $direction === 'asc' ? '▲' : '▼' }}@else↕@endif</span>
        <span class="sr-only">{{ $isActive ? 'Sorted '.($direction === 'asc' ? 'ascending' : 'descending').'. Activate to sort '.($next === 'asc' ? 'ascending' : 'descending').'.' : 'Activate to sort ascending.' }}</span>
    </a>
</th>
