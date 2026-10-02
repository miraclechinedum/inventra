{{--
    Resume a saved sale.

    Each row is a SaleDraft this user created and has not yet spent, with the discount request that
    was raised against it. Nothing here is reconstructed from the browser: the cart, the buyer and
    the trading day come back from the database exactly as they were fingerprinted, which is what
    lets an approval granted earlier still apply.
--}}
<div
    class="ui-modal"
    x-cloak
    x-show="resumeOpen"
    x-on:keydown.escape.window="closeResume"
    role="dialog"
    aria-modal="true"
    aria-labelledby="resume-modal-title"
>
    <div class="ui-modal-backdrop" x-on:click="closeResume" aria-hidden="true"></div>
    <div class="ui-modal-panel">
        <div class="ui-modal-head">
            <h2 id="resume-modal-title">Saved sales</h2>
            <button type="button" class="ui-icon-action" x-on:click="closeResume" aria-label="Close" data-tooltip="Close">&times;</button>
        </div>
        <div class="ui-modal-body">
            <div class="ui-form-body">
                <ul class="ui-sale-resume-list">
                    <template x-for="draft in resumable" x-bind:key="draft.sale_draft_id">
                        <li class="ui-sale-resume-item">
                            <span class="ui-sale-resume-main">
                                <span class="ui-sale-resume-who" x-text="resumeLabel(draft)"></span>
                                <span class="ui-sale-resume-meta"
                                      x-text="'Discount asked: ' + money(kobo(draft.requested_amount)) + ' · ' + draft.sale_date"></span>
                            </span>
                            <span class="ui-sale-pill"
                                  x-bind:class="'is-' + draft.status"
                                  x-text="draft.status"></span>
                            <button type="button" class="ui-button" x-on:click="resume(draft)">Resume</button>
                        </li>
                    </template>
                </ul>
            </div>
        </div>
    </div>
</div>
