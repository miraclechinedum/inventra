{{--
  Page links only. Laravel's stock Tailwind view also renders its own "Showing x to y of z
  results" line; the shared <x-table-footer> owns that text for every listing, so keeping it here
  too would print the count twice with two different wordings.
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="ui-pagination-nav">
        @if ($paginator->onFirstPage())
            <span class="ui-page-link is-disabled" aria-disabled="true">&laquo; {{ __('Previous') }}</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="ui-page-link">&laquo; {{ __('Previous') }}</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="ui-page-gap" aria-hidden="true">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="ui-page-link is-current" aria-current="page">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" class="ui-page-link" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="ui-page-link">{{ __('Next') }} &raquo;</a>
        @else
            <span class="ui-page-link is-disabled" aria-disabled="true">{{ __('Next') }} &raquo;</span>
        @endif
    </nav>
@endif
