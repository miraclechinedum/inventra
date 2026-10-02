{{--
    Product picker.

    Searches active, sellable products only — the endpoint behind it applies `active()` — so an
    archived product can never be added to a sale from here. Stock and selling price are shown
    because both help the person at the counter; cost price is not in the payload at all.
--}}
<div
    class="ui-modal"
    x-cloak
    x-show="productOpen"
    x-on:keydown.escape.window="closeProductPicker"
    role="dialog"
    aria-modal="true"
    aria-labelledby="product-picker-title"
>
    <div class="ui-modal-backdrop" x-on:click="closeProductPicker" aria-hidden="true"></div>
    <div class="ui-modal-panel">
        <div class="ui-modal-head">
            <h2 id="product-picker-title">Add product</h2>
            <button type="button" class="ui-icon-action" x-on:click="closeProductPicker" aria-label="Close" data-tooltip="Close">&times;</button>
        </div>
        <div class="ui-modal-body">
            <div class="ui-form-body">
                <label class="inventra-field-label" for="product-search">Search products</label>
                <div class="ui-combobox-control">
                    <input
                        id="product-search"
                        type="text"
                        class="inventra-input ui-combobox-input"
                        placeholder="Product name or SKU"
                        autocomplete="off"
                        role="combobox"
                        aria-expanded="true"
                        aria-controls="product-options"
                        aria-autocomplete="list"
                        x-ref="productInput"
                        x-model="productQuery"
                        x-on:input="productInput"
                        x-on:keydown.arrow-down.prevent="moveProduct(1)"
                        x-on:keydown.arrow-up.prevent="moveProduct(-1)"
                        x-on:keydown.enter="chooseProductAtCursor"
                    >
                </div>

                <ul class="ui-sale-picker-list" id="product-options" role="listbox" aria-label="Products">
                    <template x-for="(row, index) in productResults" x-bind:key="row.id">
                        <li
                            class="ui-sale-picker-option"
                            role="option"
                            aria-selected="false"
                            x-bind:class="index === productActive ? 'is-active' : ''"
                            x-on:click="chooseProduct(row)"
                            x-on:mousemove="productActive = index"
                        >
                            <span class="ui-sale-picker-main">
                                <span class="ui-sale-picker-name" x-text="row.name"></span>
                                <span class="ui-sale-picker-sku" x-text="row.sku"></span>
                            </span>
                            <span class="ui-sale-picker-meta">
                                <span class="ui-money" x-text="money(kobo(row.price))"></span>
                                <span class="ui-sale-picker-stock"
                                      x-bind:class="outOfStock(row) ? 'is-out' : ''"
                                      x-text="row.stock + ' ' + row.unit + ' in stock'"></span>
                            </span>
                        </li>
                    </template>
                    <li class="ui-combobox-empty" x-show="!productResults.length && !productBusy">No matching product.</li>
                    <li class="ui-combobox-empty" x-show="productBusy">Searching…</li>
                </ul>
            </div>
        </div>
    </div>
</div>
