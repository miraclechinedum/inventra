{{--
    Shared by the create and edit pages. The action, method and buttons differ; the fields, their
    validation display and their old-input handling do not.
--}}
@csrf
@if(($method ?? null) === 'PUT')@method('PUT')@endif

<div class="ui-form-sheet-head">
    <nav class="ui-form-crumbs" aria-label="Breadcrumb">
        <a href="{{ route('inventory.categories.index') }}">Product categories</a>
        <span aria-hidden="true">&rsaquo;</span>
        <strong aria-current="page">{{ $heading }}</strong>
    </nav>
    <a href="{{ route('inventory.categories.index') }}" class="ui-form-dismiss" aria-label="Close and return to product categories" data-tooltip="Close">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false"><path d="M12 4L4 12" stroke="currentColor" stroke-width="1.33333" stroke-linecap="round"/><path d="M4 4L12 12" stroke="currentColor" stroke-width="1.33333" stroke-linecap="round"/></svg>
    </a>
</div>

<div class="ui-form-body">
    <div class="ui-form-grid">
        <x-field-shell label="Category name" name="name" required full>
            <input type="text" id="name" name="name" maxlength="255" placeholder="e.g. Filters" autocomplete="off"
                value="{{ \App\Support\OldInput::scalar('name', $category->name ?? '') }}"
                @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
        </x-field-shell>

        <x-field-shell label="Description" name="description" full hint="Optional. Shown on the category list to explain what belongs here.">
            <textarea id="description" name="description" rows="3" maxlength="1000"
                placeholder="Optional notes about this category."
                aria-describedby="description-hint @error('description') description-error @enderror"
                @error('description') aria-invalid="true" @enderror>{{ \App\Support\OldInput::scalar('description', $category->description ?? '') }}</textarea>
        </x-field-shell>
    </div>
</div>

<div class="ui-form-actions">
    @if(! isset($category))
        <button type="submit" name="save_and_add_another" value="1" class="ui-button ui-button-subtle">Save &amp; add another</button>
    @else
        <span></span>
    @endif
    <div class="ui-form-actions-end">
        <a href="{{ route('inventory.categories.index') }}" class="ui-button">Cancel</a>
        <button type="submit" class="ui-button ui-button-primary">{{ $submitLabel }}</button>
    </div>
</div>
