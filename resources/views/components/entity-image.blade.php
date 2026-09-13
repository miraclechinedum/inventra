@props(['url' => null, 'label' => '', 'size' => 'h-12 w-12', 'rounded' => 'rounded-xl'])
{{--
    One image presentation for products and people. When there is no photograph it renders an
    initials placeholder rather than a broken image or an empty box, so a list stays aligned whether
    or not anything has been uploaded. The image itself is served by an authorized route, never from
    a public directory.
--}}
@if($url)
<img src="{{ $url }}" alt="{{ $label }}" loading="lazy" {{ $attributes->class([$size, $rounded, 'border border-[#e7ebf0] bg-white object-cover']) }}>
@else
<span role="img" aria-label="No image for {{ $label }}" {{ $attributes->class([$size, $rounded, 'flex shrink-0 items-center justify-center border border-[#e7ebf0] bg-slate-100 text-xs font-bold uppercase tracking-wide text-slate-500']) }}>{{ \App\Support\Initials::from($label) }}</span>
@endif
