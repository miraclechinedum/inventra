{{--
    The editable product fields, shared by the full edit page and the listing's edit modal.

    Stock is shown but never editable here: it is only ever changed through an inventory adjustment,
    which writes a movement. Lifecycle actions (archive, reactivate, permanent delete) live in the
    footer and each post to their own authorized route.
--}}
@csrf
@method('PUT')
<div class="ui-form-body">
    <div class="ui-field">
        <span class="ui-field-label">Product image</span>
        <div class="ui-image-field">
            <x-entity-image :url="$product->image_path ? route('inventory.products.image', $product) : null"
                :label="$product->name" size="h-16 w-16" rounded="rounded-xl" />
            <div class="ui-image-actions">
                <p class="ui-field-hint">Photographs are managed on the product page, where a replacement is recorded in its own audit entry.</p>
                <a href="{{ route('inventory.products.show', $product) }}" class="ui-inline-link">Manage photo</a>
            </div>
        </div>
    </div>

    <div class="ui-form-grid" style="margin-top:18px">
        <x-field-shell label="Product name" name="name" required full>
            <input type="text" id="name" name="name" maxlength="255"
                value="{{ \App\Support\OldInput::scalar('name', $product->name) }}" autocomplete="off"
                @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
        </x-field-shell>

        <x-field-shell label="SKU" name="sku" required>
            <input type="text" id="sku" name="sku" maxlength="64" spellcheck="false"
                value="{{ \App\Support\OldInput::scalar('sku', $product->sku) }}" autocomplete="off"
                @error('sku') aria-invalid="true" aria-describedby="sku-error" @enderror>
        </x-field-shell>

        <div class="ui-field">
            <label class="ui-field-label" for="category_id_search">Category<span class="is-required" aria-hidden="true">*</span></label>
            <x-category-combobox :categories="$categories" :selected="$product->category_id" :can-create="$canManageCategories ?? false" />
            @error('category_id')<p class="ui-field-error" id="category_id-error">{{ $message }}</p>@enderror
        </div>

        <x-field-shell label="Selling price (₦)" name="selling_price" required>
            <span class="ui-money">
                <span aria-hidden="true">₦</span>
                <input type="text" id="selling_price" name="selling_price" inputmode="decimal"
                    value="{{ \App\Support\OldInput::scalar('selling_price', $product->selling_price) }}" autocomplete="off"
                    @error('selling_price') aria-invalid="true" aria-describedby="selling_price-error" @enderror>
            </span>
        </x-field-shell>

        <x-field-shell label="Cost price (₦)" name="cost_price" required>
            <span class="ui-money">
                <span aria-hidden="true">₦</span>
                <input type="text" id="cost_price" name="cost_price" inputmode="decimal"
                    value="{{ \App\Support\OldInput::scalar('cost_price', $product->cost_price) }}" autocomplete="off"
                    @error('cost_price') aria-invalid="true" aria-describedby="cost_price-error" @enderror>
            </span>
        </x-field-shell>

        <x-field-shell label="Current stock" name="current_stock">
            <input type="text" id="current_stock" value="{{ \App\Support\Quantity::trim($product->current_stock) }}"
                readonly disabled>
        </x-field-shell>

        <x-field-shell label="Reorder level" name="reorder_level" required>
            <x-quantity-stepper name="reorder_level" label="reorder level" step="1" min="0"
                :value="\App\Support\Quantity::trim($product->reorder_level)" />
        </x-field-shell>
    </div>

    {{-- Unit and Description are not part of this screen's design. Both still need the values the
         backend expects on every save — Unit is required and non-nullable, and Description would
         otherwise be silently cleared by the update action, since it treats an absent key as null.
         Each travels as a hidden input carrying the product's current, unedited value. --}}
    <input type="hidden" name="unit" value="{{ \App\Support\OldInput::scalar('unit', $product->unit->value) }}">
    <input type="hidden" name="description" value="{{ \App\Support\OldInput::scalar('description', $product->description ?? '') }}">
</div>

<div class="ui-form-actions">
    <span class="ui-lifecycle-actions">
        @can('archive', $product)
            <x-lifecycle-confirm :action="route('inventory.products.destroy', $product)" method="DELETE"
                label="Archive product" title="Archive product?"
                message="This product will no longer be available for new sales or purchases. Existing sales, inventory movements and historical records will remain available." />
        @endcan
        @can('reactivate', $product)
            <x-lifecycle-confirm :action="route('inventory.products.reactivate', $product)"
                label="Reactivate product" title="Reactivate product?"
                message="This product will become available for new sales, purchases and other permitted inventory operations again." />
        @endcan
    </span>
    <div class="ui-form-actions-end">
        <a href="{{ route('inventory.index') }}" class="ui-button">Cancel</a>
        <button type="submit" class="ui-button ui-button-primary">Save Changes</button>
    </div>
</div>

@can('delete', $product)
    <div class="ui-danger-zone {{ $deletionRefusal !== null ? 'is-unavailable' : '' }}">
        @if($deletionRefusal === null)
            <div>
                <strong>Permanently delete this product</strong>
                <p>It has no sales, purchases, returns or stock movements, so removing it destroys no history. This cannot be undone.</p>
            </div>
            <x-lifecycle-confirm :action="route('inventory.products.force-destroy', $product)" method="DELETE" tone="danger"
                label="Permanently delete" title="Permanently delete this product?"
                message="This cannot be undone. The product and its record will be removed entirely." />
        @else
            <div>
                <strong>Permanent deletion unavailable</strong>
                <p>This product has sales or inventory history and must be archived instead.</p>
            </div>
            <button type="button" class="ui-button" disabled title="{{ $deletionRefusal }}">Permanently delete</button>
        @endif
    </div>
@endcan
