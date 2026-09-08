@props(['title', 'description' => ''])
<div class="ui-empty-state"><img src="{{ asset('images/figma/icon-search.svg') }}" alt=""><p>{{ $title }}</p>@if($description)<small>{{ $description }}</small>@endif{{ $slot }}</div>
