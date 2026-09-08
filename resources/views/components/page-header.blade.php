@props(['title', 'description' => null, 'eyebrow' => null])
<div class="ui-page-header">
    <div>@if($eyebrow)<p class="ui-eyebrow">{{ $eyebrow }}</p>@endif<h2>{{ $title }}</h2>@if($description)<p class="ui-page-description">{{ $description }}</p>@endif</div>
    @if($slot->isNotEmpty())<div class="ui-page-actions">{{ $slot }}</div>@endif
</div>
