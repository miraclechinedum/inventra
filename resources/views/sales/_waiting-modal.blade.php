{{--
    Waiting for approval.

    The request is already on record — it was written to the database before this appeared — so
    nothing here is holding the sale together. The page polls for the Admin's decision, and both
    exits below are honest about what they do:

      Continue without discount  leaves the request standing for the Admin but stops this sale
                                 waiting for it. No draft id is submitted, so no discount can apply.
      Save as pending            simply leaves. The draft and its request persist, and Record Sale
                                 offers them back under "Resume a saved sale".
--}}
<div
    class="ui-modal"
    x-cloak
    x-show="waiting"
    role="dialog"
    aria-modal="true"
    aria-labelledby="waiting-modal-title"
>
    <div class="ui-modal-backdrop" aria-hidden="true"></div>
    <div class="ui-modal-panel">
        <div class="ui-modal-head">
            <h2 id="waiting-modal-title">Waiting for approval</h2>
            <span class="ui-sale-pill is-pending">Pending</span>
        </div>
        <div class="ui-modal-body">
            <div class="ui-form-body ui-sale-modal-body">
                <p class="ui-sale-waiting-lead">
                    An administrator has been asked to approve this discount. This sale has not been
                    recorded and no stock has moved.
                </p>

                <dl class="ui-sale-facts">
                    <div>
                        <dt>Discount requested</dt>
                        <dd class="ui-money" x-text="money(kobo(discountAmount))"></dd>
                    </div>
                    <div>
                        <dt>Customer</dt>
                        <dd x-text="buyerLabel"></dd>
                    </div>
                    <div>
                        <dt>Sale total</dt>
                        <dd class="ui-money" x-text="money(subtotalKobo)"></dd>
                    </div>
                    <div>
                        <dt>Waiting</dt>
                        <dd><span x-text="elapsed"></span> <span class="ui-sale-spinner" aria-hidden="true"></span></dd>
                    </div>
                </dl>

                <p class="ui-sale-hint" role="status" aria-live="polite">
                    Checking for a decision automatically. You can keep this open, carry on without
                    the discount, or leave and pick this sale up later.
                </p>
            </div>
            <div class="ui-form-actions ui-form-actions-end">
                <button type="button" class="ui-button" x-on:click="continueWithoutDiscount">Continue without discount</button>
                <button type="button" class="ui-button" x-on:click="saveAsPending">Save as pending</button>
            </div>
        </div>
    </div>
</div>
