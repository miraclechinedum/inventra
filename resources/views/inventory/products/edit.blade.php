<x-app-layout :title="'Edit '.$product->name">
    <form method="POST" action="{{ route('inventory.products.update', $product) }}" class="ui-form-sheet" id="edit-product-form">
        <div class="ui-form-sheet-head">
            <nav class="ui-form-crumbs" aria-label="Breadcrumb">
                <a href="{{ route('inventory.index') }}">Inventory</a>
                <span aria-hidden="true">&rsaquo;</span>
                <strong aria-current="page">{{ $product->name }}</strong>
            </nav>
            <a href="{{ route('inventory.products.show', $product) }}" class="ui-form-dismiss" aria-label="Close and return to the product" data-tooltip="Close">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M12 4L4 12" stroke="currentColor" stroke-width="1.33333" stroke-linecap="round"/><path d="M4 4L12 12" stroke="currentColor" stroke-width="1.33333" stroke-linecap="round"/></svg>
            </a>
        </div>
        {{-- The modal lifts exactly this fragment, so both surfaces share one form. --}}
        <div data-edit-product-fragment>
            @include('inventory.products._edit-fields')
        </div>
    </form>
</x-app-layout>
