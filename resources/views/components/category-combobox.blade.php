@props(['categories', 'selected' => null, 'canCreate' => false])
{{--
    Searchable category picker.

    The value the server acts on is the hidden category_id input; the visible text box only helps
    the user find one. That means a browser with no JavaScript still posts whatever id was already
    selected, and a tampered text box changes nothing — StoreProductRequest continues to validate
    category_id against active categories on its own.

    Markup follows the WAI-ARIA combobox pattern: a text input with role=combobox that owns a
    listbox, options exposed as role=option, and the active row tracked by aria-activedescendant.
--}}
@php
    $selectedId = (string) \App\Support\OldInput::scalar('category_id', $selected ?? '');
    $payload = $categories->map(fn ($category) => ['id' => $category->id, 'name' => $category->name])->values();
@endphp
<div
    class="ui-combobox"
    x-data="categoryCombobox"
    data-categories="{{ $payload->toJson() }}"
    data-selected-id="{{ $selectedId }}"
    data-can-create="{{ $canCreate ? '1' : '0' }}"
    data-create-url="{{ route('inventory.categories.quick-store') }}"
    x-on:keydown.escape.stop="close"
>
    <input type="hidden" name="category_id" x-model="selectedId">

    <div class="ui-combobox-control">
        <input
            type="text"
            id="category_id_search"
            class="ui-combobox-input"
            role="combobox"
            aria-controls="category-listbox"
            aria-autocomplete="list"
            autocomplete="off"
            spellcheck="false"
            placeholder="Choose or type"
            x-model="query"
            x-bind:aria-expanded="open ? 'true' : 'false'"
            x-bind:aria-activedescendant="activeId"
            x-on:input="input"
            x-on:focus="show"
            x-on:blur="blur"
            x-on:click="show"
            x-on:keydown.arrow-down.prevent="move(1)"
            x-on:keydown.arrow-up.prevent="move(-1)"
            x-on:keydown.enter="enter($event)"
            @error('category_id') aria-invalid="true" aria-describedby="category_id-error" @enderror
        >
        <span class="ui-combobox-caret" aria-hidden="true"></span>
    </div>

    <ul class="ui-combobox-list" id="category-listbox" role="listbox" aria-label="Categories" x-cloak x-show="open">
        <template x-for="(row, index) in rows" x-bind:key="row.create ? 'create' : row.id">
            <li
                class="ui-combobox-option"
                x-bind:id="'category-option-' + index"
                role="option"
                x-bind:class="{ 'is-active': active === index, 'is-create': row.create }"
                x-bind:aria-selected="active === index ? 'true' : 'false'"
                x-on:mousedown.prevent="row.create ? create() : choose(row)"
                x-on:mousemove="active = index"
            >
                <template x-if="!row.create"><span x-text="row.name"></span></template>
                <template x-if="row.create">
                    <span class="ui-combobox-create">
                        <span aria-hidden="true">+</span>
                        <span>Create "<span x-text="query.trim()"></span>"</span>
                    </span>
                </template>
            </li>
        </template>

        <li class="ui-combobox-empty" x-show="!rows.length" role="option" aria-disabled="true" aria-selected="false">
            No matching category found.
        </li>
    </ul>

    <p class="ui-field-error" x-cloak x-show="error" x-text="error" role="alert"></p>
    <p class="ui-combobox-busy" x-cloak x-show="busy" aria-live="polite">Creating category&hellip;</p>
</div>
