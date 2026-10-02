<x-app-layout title="Inventory">
    {{-- Refusals from row actions — a product allowance on reactivation, for example. --}}
    <x-validation-errors class="mb-4" />
    <div class="ui-page-header">
        <div>
            <h2>Inventory</h2>
            <p class="ui-page-description">Search products and monitor current stock.</p>
        </div>
        @can('create', \App\Models\Product::class)
            <div class="ui-page-actions">
                <a href="{{ route('inventory.products.create') }}" class="inventra-primary-action">
                    <img src="{{ asset('images/figma/icon-plus.svg') }}" alt="">Add Product
                </a>
            </div>
        @endcan
    </div>

    <form method="GET" class="ui-filter-bar ui-filter-flow">
        <div class="ui-field">
            <label class="ui-field-label" for="search">Search</label>
            <input type="search" id="search" name="search" value="{{ is_string(request('search')) ? request('search') : '' }}" placeholder="Search by product name or SKU">
        </div>
        <div class="ui-field">
            <label class="ui-field-label" for="category">Category</label>
            <select id="category" name="category">
                <option value="">All categories</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}" @selected(is_string(request('category')) && request('category') === (string) $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="ui-field">
            <label class="ui-field-label" for="stock">Stock</label>
            <select id="stock" name="stock">
                <option value="">All stock</option>
                <option value="low" @selected(is_string(request('stock')) && request('stock') === 'low')>Low stock</option>
                <option value="ok" @selected(is_string(request('stock')) && request('stock') === 'ok')>Stock OK</option>
            </select>
        </div>
        @if($canManage)
            <div class="ui-field">
                <label class="ui-field-label" for="status">Status</label>
                <select id="status" name="status">
                    <option value="">All statuses</option>
                    <option value="active" @selected(is_string(request('status')) && request('status') === 'active')>Active</option>
                    <option value="inactive" @selected(is_string(request('status')) && request('status') === 'inactive')>Archived</option>
                </select>
            </div>
        @endif
        <button type="submit" class="ui-button ui-button-primary">Filter</button>
    </form>
    <div class="mt-2 text-right"><x-filter-reset /></div>

    <div class="ui-form-sheet mt-4 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="ui-inventory-table">
                <thead>
                    <tr>
                        <th class="ui-sn">S/N</th>
                        <x-sort-header key="name" label="Product" :active="$sort['key']" :direction="$sort['direction']" />
                        <x-sort-header key="sku" label="SKU" :active="$sort['key']" :direction="$sort['direction']" />
                        <th>Category</th>
                        <x-sort-header key="price" label="Price" :active="$sort['key']" :direction="$sort['direction']" align="right" />
                        <x-sort-header key="stock" label="Stock" :active="$sort['key']" :direction="$sort['direction']" align="right" />
                        <x-sort-header key="reorder" label="Reorder" :active="$sort['key']" :direction="$sort['direction']" align="right" />
                        <x-sort-header key="status" label="Status" :active="$sort['key']" :direction="$sort['direction']" />
                        @if($canManage)<th class="text-right">Actions</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @forelse($products as $product)
                        @php
                            // Archived is the authoritative operational state, so an archived
                            // product never also reads as "low stock": it is not awaiting
                            // replenishment. The precedence is decided here rather than left to
                            // CSS specificity, so the markup states the intent outright.
                            $archived = ! $product->is_active;
                            $low = ! $archived && $product->isLowStock();
                        @endphp
                        <tr @class(['is-low' => $low, 'is-archived' => $archived])>
                            <td class="ui-sn">{{ $products->firstItem() + $loop->index }}</td>
                            <td data-label="Product">
                                <span class="ui-product-cell">
                                    <x-entity-image :url="$product->image_path ? route('inventory.products.image', $product) : null"
                                        :label="$product->name" size="h-9 w-9" rounded="rounded-lg" />
                                    <a href="{{ route('inventory.products.show', $product) }}">{{ $product->name }}</a>
                                </span>
                            </td>
                            <td data-label="SKU" class="font-mono text-slate-500">{{ $product->sku }}</td>
                            <td data-label="Category" class="text-slate-600">{{ $product->category->name }}</td>
                            <td data-label="Price" class="text-right font-semibold">₦{{ \App\Support\Money::format($product->selling_price) }}</td>
                            <td data-label="Stock" @class(['text-right', 'font-semibold', 'ui-stock-low' => $low])>{{ \App\Support\Quantity::trim($product->current_stock) }}</td>
                            <td data-label="Reorder" class="text-right text-slate-400">{{ \App\Support\Quantity::trim($product->reorder_level) }}</td>
                            <td data-label="Status">
                                @if(! $product->is_active)
                                    <span class="ui-badge" data-tone="neutral">Archived</span>
                                @elseif($low)
                                    <span class="ui-badge" data-tone="warning">Low</span>
                                @else
                                    <span class="ui-badge" data-tone="success">In stock</span>
                                @endif
                            </td>
                            @if($canManage)
                                <td data-label="Actions">
                                    <span class="ui-row-actions">
                                        @can('update', $product)
                                            <a href="{{ route('inventory.products.edit', $product) }}"
                                               class="ui-icon-action"
                                               data-edit-product="{{ $product->id }}"
                                               aria-label="Edit product: {{ $product->name }}" data-tooltip="Edit product">
                                                <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M11.3 2.7a1.4 1.4 0 0 1 2 2L5.6 12.4l-2.6.6.6-2.6 7.7-7.7Z" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            </a>
                                        @endcan
                                        @can('adjustStock', $product)
                                            <a href="{{ route('inventory.products.show', $product) }}#adjust-stock"
                                               class="ui-icon-action" aria-label="Adjust stock: {{ $product->name }}" data-tooltip="Adjust stock">
                                                <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2.5 4.5h11M2.5 8h11M2.5 11.5h11" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><circle cx="6" cy="4.5" r="1.6" fill="currentColor"/><circle cx="10.5" cy="11.5" r="1.6" fill="currentColor"/></svg>
                                            </a>
                                        @endcan
                                        @can('archive', $product)
                                            <x-lifecycle-confirm :action="route('inventory.products.destroy', $product)" method="DELETE"
                                                label="Archive product" title="Archive product?" trigger-label="Archive product"
                                                message="This product will no longer be available for new sales or purchases. Existing sales, inventory movements and historical records will remain available.">
                                                <x-slot:icon><svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2 4.5h12M3.5 4.5V13a1 1 0 0 0 1 1h7a1 1 0 0 0 1-1V4.5M6 4.5V3a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v1.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg></x-slot:icon>
                                            </x-lifecycle-confirm>
                                        @endcan
                                        @can('reactivate', $product)
                                            <x-lifecycle-confirm :action="route('inventory.products.reactivate', $product)"
                                                label="Reactivate product" title="Reactivate product?" tone="primary"
                                                trigger-label="Reactivate product"
                                                message="This product will become available again for new sales, purchases and other permitted inventory operations.">
                                                <x-slot:icon><svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M13.5 8a5.5 5.5 0 1 1-1.9-4.2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><path d="M13.5 2.5V6H10" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg></x-slot:icon>
                                            </x-lifecycle-confirm>
                                        @endcan
                                    </span>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canManage ? 9 : 8 }}">
                                <x-empty-state title="No products found." description="Try another search or adjust your filters." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-table-footer :paginator="$products" noun="product" />
    </div>

    @if($canManage)
        @include('inventory.products._edit-modal')
    @endif
</x-app-layout>
