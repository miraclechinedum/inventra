{{--
    Request discount.

    Nothing is sold here. Submitting creates a SaleDraft and a pending discount request against it:
    no Sale row, no stock movement, no payment. The preview below is arithmetic for the person
    asking — the amount an Admin later approves is read back from the database, never from here.
--}}
<div
    class="ui-modal"
    x-cloak
    x-show="discountOpen"
    x-on:keydown.escape.window="closeDiscount"
    role="dialog"
    aria-modal="true"
    aria-labelledby="discount-modal-title"
>
    <div class="ui-modal-backdrop" x-on:click="closeDiscount" aria-hidden="true"></div>
    <div class="ui-modal-panel">
        <div class="ui-modal-head">
            <h2 id="discount-modal-title">Request discount</h2>
            <button type="button" class="ui-icon-action" x-on:click="closeDiscount" aria-label="Close" data-tooltip="Close">&times;</button>
        </div>
        <div class="ui-modal-body">
            <div class="ui-form-body ui-sale-modal-body">
                <div class="ui-sale-modal-row">
                    <span>Sale total</span>
                    <span class="ui-money" x-text="money(subtotalKobo)"></span>
                </div>

                <div>
                    <label class="inventra-field-label" for="discount-amount">Custom discount (₦)</label>
                    <input id="discount-amount" type="text" inputmode="decimal" class="inventra-input"
                           x-ref="discountAmount" x-model="discountAmount" placeholder="0.00">
                </div>

                <div>
                    <label class="inventra-field-label" for="discount-reason">Reason</label>
                    <textarea id="discount-reason" rows="3" class="inventra-input ui-sale-notes"
                              x-model="discountReason"
                              placeholder="Why this customer should receive a discount"></textarea>
                    <p class="ui-sale-hint">At least 10 characters. An administrator reads this before deciding.</p>
                </div>

                <div class="ui-sale-modal-row is-preview">
                    <span>New total</span>
                    <span class="ui-money" x-text="money(discountPreviewKobo)"></span>
                </div>

                <p class="inventra-field-error" x-cloak x-show="discountError" x-text="discountError"></p>
            </div>
            <div class="ui-form-actions ui-form-actions-end">
                <button type="button" class="ui-button" x-on:click="closeDiscount">Cancel</button>
                <button type="button" class="inventra-primary-action" x-on:click="sendDiscountRequest"
                        x-bind:disabled="!discountValid || discountBusy">
                    <span x-show="!discountBusy">Send for approval</span>
                    <span x-cloak x-show="discountBusy">Sending…</span>
                </button>
            </div>
        </div>
    </div>
</div>
