{{--
  Shared footer for every paginated record list: result count, rows-per-page selector and page
  links. The selector is a GET form that re-submits the current query string minus `page`, so
  changing the size keeps the active search, filters and sort while returning to page one.
--}}
@props(['paginator', 'noun' => 'records'])
@php
    $total = $paginator->total();
    $carried = \App\Support\QueryInputs::hidden(request()->query(), ['page', 'per_page']);
    $selectId = 'per-page-'.$paginator->getPageName();
@endphp
<div class="ui-table-footer">
    <p class="ui-result-count" aria-live="polite">
        @if ($total === 0)
            Showing 0 {{ $noun }}
        @else
            Showing {{ number_format($paginator->firstItem()) }}&ndash;{{ number_format($paginator->lastItem()) }} of {{ number_format($total) }} {{ \Illuminate\Support\Str::plural($noun, $total) }}
        @endif
    </p>
    <div class="ui-table-footer-controls">
        <form method="GET" action="{{ url()->current() }}" class="ui-per-page">
            @foreach ($carried as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
            <label for="{{ $selectId }}">Rows per page:</label>
            <select id="{{ $selectId }}" name="per_page" data-table-control>
                @foreach (\App\Support\PerPage::OPTIONS as $option)
                    <option value="{{ $option }}" @selected($paginator->perPage() === $option)>{{ $option }}</option>
                @endforeach
            </select>
            {{-- Keeps the control operable when the delegated auto-submit listener has not run. --}}
            <button type="submit" class="ui-visually-hidden-until-focus">Apply</button>
        </form>
        @if ($paginator->hasPages())
            <div class="ui-pagination">{{ $paginator->onEachSide(1)->links() }}</div>
        @endif
    </div>
</div>
