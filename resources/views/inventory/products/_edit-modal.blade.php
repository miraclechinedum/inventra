{{--
    Edit Product modal for the inventory listing.

    The edit pencil is a real link to the full edit page: without JavaScript, or if the fetch fails,
    it simply navigates there. When the script is available it intercepts the click, pulls that
    page's form fragment and shows it here, so the modal and the page can never drift apart — there
    is one form, one request class and one set of rules behind both.
--}}
<div
    class="ui-modal"
    id="edit-product-modal"
    x-data="editProductModal"
    x-cloak
    x-show="open"
    x-on:keydown.escape.window="close"
    role="dialog"
    aria-modal="true"
    aria-labelledby="edit-product-title"
>
    <div class="ui-modal-backdrop" x-on:click="close" aria-hidden="true"></div>
    <div class="ui-modal-panel" x-ref="panel">
        <div class="ui-modal-head">
            <h2 id="edit-product-title">Edit product</h2>
            <button type="button" class="ui-form-dismiss" x-on:click="close" aria-label="Close" data-tooltip="Close">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M12 4L4 12" stroke="currentColor" stroke-width="1.33333" stroke-linecap="round"/><path d="M4 4L12 12" stroke="currentColor" stroke-width="1.33333" stroke-linecap="round"/></svg>
            </button>
        </div>
        <div class="ui-modal-body" x-ref="body" aria-live="polite">
            <p class="ui-modal-loading" x-show="loading">Loading&hellip;</p>
        </div>
    </div>
</div>
