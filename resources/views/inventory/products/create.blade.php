@php
    $selectedCategory = (string) \App\Support\OldInput::scalar('category_id', '');
    $selectedUnit = (string) \App\Support\OldInput::scalar('unit', 'piece');
@endphp
<x-app-layout title="Add product">
    <form
        method="POST"
        action="{{ route('inventory.products.store') }}"
        enctype="multipart/form-data"
        class="ui-form-sheet"
        id="add-product-form"
        x-data="productForm"
        data-check-url="{{ route('inventory.products.check-duplicate') }}"
        x-on:submit="submit"
    >
        @csrf

        <div class="ui-form-sheet-head">
            <nav class="ui-form-crumbs" aria-label="Breadcrumb">
                <a href="{{ route('inventory.index') }}">Inventory</a>
                <span aria-hidden="true">&rsaquo;</span>
                <strong aria-current="page">Add product</strong>
            </nav>
            <a href="{{ route('inventory.index') }}" class="ui-form-dismiss" aria-label="Close and return to inventory" data-tooltip="Close">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false"><path d="M12 4L4 12" stroke="currentColor" stroke-width="1.33333" stroke-linecap="round"/><path d="M4 4L12 12" stroke="currentColor" stroke-width="1.33333" stroke-linecap="round"/></svg>
            </a>
        </div>

        <div class="ui-form-body">
            @php
                $ownFields = ['name', 'sku', 'category_id', 'selling_price', 'cost_price', 'initial_stock', 'reorder_level', 'image'];
                $unplacedErrors = collect($errors->keys())->reject(fn ($key) => in_array($key, $ownFields, true));
            @endphp
            <div role="alert" class="rounded-xl bg-red-50 p-4 text-red-700" x-cloak x-show="summary">
                <p class="font-semibold">Some fields are empty, please check and re-fill.</p>
            </div>
            @if($unplacedErrors->isNotEmpty())
                <div role="alert" class="rounded-xl bg-red-50 p-4 text-red-700">
                    <p class="font-semibold">We could not save this. Please review the following:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        @foreach($unplacedErrors as $key)
                            @foreach($errors->get($key) as $message)<li>{{ $message }}</li>@endforeach
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Product image ------------------------------------------------------------------ --}}
            <div class="ui-field" x-data="productImageField">
                <span class="ui-field-label" id="product-image-label">Product image</span>
                <div class="ui-image-field">
                    <span class="ui-image-drop">
                        <template x-if="preview">
                            <img x-bind:src="preview" alt="Preview of the selected product image">
                        </template>
                        <template x-if="!preview">
                            <svg width="26" height="26" viewBox="0 0 26 26" fill="none" aria-hidden="true" focusable="false"><path d="M14.6667 3.25H6.5C5.11929 3.25 4 4.36929 4 5.75V20.25C4 21.6307 5.11929 22.75 6.5 22.75H19.5C20.8807 22.75 22 21.6307 22 20.25V12.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M4.5 18.5L9.5 13.5C10.2 12.85 11.1 12.85 11.8 13.5L16.5 18" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M19.5 3V9.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M16.25 6.25H22.75" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                        </template>
                    </span>

                    <div class="ui-image-actions">
                        {{-- The real control. Kept in the accessibility tree and reachable by keyboard;
                             the two buttons below are the pointer affordance the design asks for. --}}
                        <input
                            type="file"
                            class="ui-image-input"
                            id="image"
                            name="image"
                            accept="image/jpeg,image/png,image/webp"
                            x-ref="file"
                            x-on:change="changed"
                            aria-labelledby="product-image-label"
                            aria-describedby="image-hint @error('image') image-error @enderror"
                            @error('image') aria-invalid="true" @enderror
                        >
                        <div class="ui-image-buttons">
                            <button type="button" class="ui-button ui-button-primary" x-on:click="pick">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false"><path d="M14 10V12.6667C14 13.403 13.403 14 12.6667 14H3.33333C2.59695 14 2 13.403 2 12.6667V10" stroke="currentColor" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/><path d="M11 5L8 2L5 5" stroke="currentColor" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 2V10.6667" stroke="currentColor" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                Upload
                            </button>
                            <button type="button" class="ui-button" x-on:click="capture">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false"><path d="M14 12.3333C14 13.0697 13.403 13.6667 12.6667 13.6667H3.33333C2.59695 13.6667 2 13.0697 2 12.3333V5.66667C2 4.93029 2.59695 4.33333 3.33333 4.33333H5.33333L6.33333 2.33333H9.66667L10.6667 4.33333H12.6667C13.403 4.33333 14 4.93029 14 5.66667V12.3333Z" stroke="currentColor" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 11.3333C9.28866 11.3333 10.3333 10.2887 10.3333 9C10.3333 7.71134 9.28866 6.66667 8 6.66667C6.71134 6.66667 5.66667 7.71134 5.66667 9C5.66667 10.2887 6.71134 11.3333 8 11.3333Z" stroke="currentColor" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                Camera
                            </button>
                        </div>
                        <p class="ui-image-chosen" x-cloak x-show="name">
                            <b x-text="name"></b>
                            <button type="button" x-on:click="clear">Remove</button>
                        </p>
                        @error('image')<p class="ui-field-error" id="image-error">{{ $message }}</p>@enderror
                        <p class="ui-field-hint" id="image-hint">Square image, up to 2MB. Optional.</p>
                    </div>
                </div>
            </div>

            {{-- Primary fields ----------------------------------------------------------------- --}}
            <div class="ui-form-grid" style="margin-top:22px">
                <x-field-shell label="Product name" name="name" required full>
                    <input type="text" id="name" name="name" maxlength="255" placeholder="e.g. Toyota Camry oil filter"
                        value="{{ \App\Support\OldInput::scalar('name', '') }}" autocomplete="off"
                        x-on:input="watchField('name')"
                        x-bind:aria-invalid="duplicates.name && !overridden.name ? 'true' : null"
                        @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                    <p class="ui-field-warning" x-cloak x-show="duplicates.name && !overridden.name" role="status">
                        <span aria-hidden="true">&#9888;</span>
                        A product named '<span x-text="duplicateNameLabel"></span>' already exists.
                        <button type="button" x-on:click="overridden.name = true">Create anyway</button>
                        <span aria-hidden="true">&middot;</span>
                        <a x-bind:href="duplicateNameUrl" target="_blank" rel="noopener">View existing</a>
                    </p>
                </x-field-shell>

                <x-field-shell label="SKU" name="sku" required hint="Letters, digits and . _ / - only.">
                    <input type="text" id="sku" name="sku" maxlength="64" placeholder="e.g. FLT-0921"
                        value="{{ \App\Support\OldInput::scalar('sku', '') }}" autocomplete="off" spellcheck="false"
                        x-on:input="watchField('sku')"
                        x-bind:aria-invalid="duplicates.sku ? 'true' : null"
                        aria-describedby="sku-hint @error('sku') sku-error @enderror"
                        @error('sku') aria-invalid="true" @enderror>
                    <p class="ui-field-error" x-cloak x-show="duplicates.sku" role="status">
                        <span aria-hidden="true">&#9432;</span>
                        A product with SKU <span x-text="duplicateSkuCode"></span> already exists &mdash;
                        <span x-text="duplicateSkuLabel"></span>.
                        <a x-bind:href="duplicateSkuUrl" target="_blank" rel="noopener">View product</a>
                    </p>
                </x-field-shell>

                <div class="ui-field">
                    <div class="ui-field-heading">
                        <label class="ui-field-label" for="category_id_search">Category<span class="is-required" aria-hidden="true">*</span></label>
                        @if($canManageCategories)
                            <a class="ui-inline-link" href="{{ route('inventory.categories.index') }}">Manage categories</a>
                        @endif
                    </div>
                    <x-category-combobox :categories="$categories" :selected="$selectedCategory" :can-create="$canManageCategories" />
                    @error('category_id')<p class="ui-field-error" id="category_id-error">{{ $message }}</p>@enderror
                    @if($categories->isEmpty())
                        <p class="ui-field-hint">
                            No active categories yet.
                            @if($canManageCategories)<a class="ui-inline-link" href="{{ route('inventory.categories.create') }}">Add a category</a> before saving this product.@else Ask an administrator to add one.@endif
                        </p>
                    @endif
                </div>

                <x-field-shell label="Selling price (₦)" name="selling_price" required>
                    <span class="ui-money">
                        <span aria-hidden="true">₦</span>
                        <input type="text" id="selling_price" name="selling_price" inputmode="decimal" placeholder="0.00"
                            value="{{ \App\Support\OldInput::scalar('selling_price', '') }}" autocomplete="off"
                            x-on:input="watchSellingPrice($event)"
                            @error('selling_price') aria-invalid="true" aria-describedby="selling_price-error" @enderror>
                    </span>
                </x-field-shell>

                <x-field-shell label="Cost price (₦)" name="cost_price" required>
                    <span class="ui-money">
                        <span aria-hidden="true">₦</span>
                        <input type="text" id="cost_price" name="cost_price" inputmode="decimal" placeholder="0.00"
                            value="{{ \App\Support\OldInput::scalar('cost_price', '') }}" autocomplete="off"
                            x-on:input="watchCostPrice($event)"
                            @error('cost_price') aria-invalid="true" aria-describedby="cost_price-error" @enderror>
                    </span>
                    <p class="ui-field-warning" x-cloak x-show="costExceedsSelling && !overridden.cost" role="status">
                        <span aria-hidden="true">&#9888;</span>
                        Cost price is higher than selling price.
                        <button type="button" x-on:click="overridden.cost = true">Save anyway</button>
                        <span aria-hidden="true">&middot;</span>
                        <button type="button" x-on:click="goBack">Go back</button>
                    </p>
                </x-field-shell>

                <x-field-shell label="Initial stock" name="initial_stock" required>
                    <x-quantity-stepper name="initial_stock" label="initial stock" step="1" min="0" />
                </x-field-shell>

                <x-field-shell label="Reorder level" name="reorder_level" required>
                    <x-quantity-stepper name="reorder_level" label="reorder level" step="1" min="0" />
                </x-field-shell>
            </div>

            {{-- Unit and Description are not part of this screen's design. Unit still needs a
                 value the backend accepts, so it is submitted as the domain default rather than
                 asked of the user here; it stays editable from the Edit Product screen. --}}
            <input type="hidden" name="unit" value="{{ $selectedUnit }}">
        </div>

        {{-- Action bar ------------------------------------------------------------------------- --}}
        <div class="ui-form-actions" id="add-product-actions">
            <button type="submit" name="save_and_add_another" value="1" class="ui-button ui-button-subtle">Save &amp; add another</button>
            <div class="ui-form-actions-end">
                <a href="{{ route('inventory.index') }}" class="ui-button">Cancel</a>
                <button type="submit" class="ui-button ui-button-primary">Save Product</button>
            </div>
        </div>
    </form>
</x-app-layout>
