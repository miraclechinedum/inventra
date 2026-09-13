<x-app-layout title="Product categories">
    <div class="ui-page-header">
        <div>
            <h2>Product categories</h2>
            <p class="ui-page-description">Group products for reporting and for the Add Product form. A category must be active before a product can be assigned to it.</p>
        </div>
        @if($canManage)
            <div class="ui-page-actions">
                <a href="{{ route('inventory.categories.create') }}" class="inventra-primary-action">
                    <img src="{{ asset('images/figma/icon-plus.svg') }}" alt="">Add category
                </a>
            </div>
        @endif
    </div>

    <form class="ui-filter-bar ui-filter-flow" method="GET">
        <div class="ui-field">
            <label class="ui-field-label" for="search">Search</label>
            <input type="search" id="search" name="search" value="{{ $search }}" placeholder="Category name">
        </div>
        <div class="ui-field">
            <label class="ui-field-label" for="status">Status</label>
            <select id="status" name="status">
                <option value="">All statuses</option>
                <option value="active" @selected($status === 'active')>Active</option>
                <option value="inactive" @selected($status === 'inactive')>Inactive</option>
            </select>
        </div>
        <button type="submit" class="ui-button ui-button-primary">Filter</button>
    </form>
    <div class="mt-2 text-right"><x-filter-reset /></div>

    <div class="ui-form-sheet mt-4 overflow-hidden">
        <div class="overflow-x-auto">
            <table>
                <thead>
                    <tr>
                        <th class="ui-sn">S/N</th>
                        <x-sort-header key="name" label="Category" :active="$sort['key']" :direction="$sort['direction']" />
                        <th>Description</th>
                        <x-sort-header key="products" label="Products" :active="$sort['key']" :direction="$sort['direction']" align="right" />
                        <x-sort-header key="status" label="Status" :active="$sort['key']" :direction="$sort['direction']" />
                        @if($canManage)<th><span class="sr-only">Actions</span></th>@endif
                    </tr>
                </thead>
                <tbody>
                    @forelse($categories as $category)
                        <tr>
                            <td class="ui-sn">{{ $categories->firstItem() + $loop->index }}</td>
                            <td><strong>{{ $category->name }}</strong></td>
                            <td>{{ $category->description ?: '—' }}</td>
                            <td class="text-right">{{ number_format($category->products_count) }}</td>
                            <td><x-status-badge :status="$category->is_active ? 'active' : 'inactive'" /></td>
                            @if($canManage)
                                <td>
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                        @can('update', $category)
                                            <a href="{{ route('inventory.categories.edit', $category) }}" class="ui-button">Edit</a>
                                        @endcan
                                        @can('changeStatus', $category)
                                            @if($category->is_active)
                                                <x-confirm-action :action="route('inventory.categories.deactivate', $category)" label="Deactivate"
                                                    message="This is allowed only when no active products depend on the category." />
                                            @else
                                                <x-confirm-action :action="route('inventory.categories.activate', $category)" label="Activate"
                                                    message="Restore this category for product assignment." />
                                            @endif
                                        @endcan
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canManage ? 6 : 5 }}">
                                <x-empty-state
                                    title="{{ $search !== '' || $status !== '' ? 'No categories match these filters.' : 'No product categories yet.' }}"
                                    description="{{ $search !== '' || $status !== '' ? 'Try another search or clear the filters.' : 'Add the first category to start grouping products.' }}" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-table-footer :paginator="$categories" noun="category" />
    </div>
</x-app-layout>
