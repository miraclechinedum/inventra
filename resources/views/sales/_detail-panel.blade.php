{{--
    The Sales-list side panel.

    One panel, one explicit mode — details or correction — so it can never be in two states at once.
    Details is built from data the row already carries plus the line items fetched from
    `sales.lines`; the correction form is fetched as a fragment, which is the same Blade partial the
    full correction page renders.

    Nothing here mutates a Sale. The correction form posts to the existing endpoint, which owns the
    policy, the eligibility check, the stock reconciliation and the audit trail. Voiding is not
    fired from this panel at all: it needs a typed reason, so the panel links to the Sale page that
    captures one. There is no delete — a recorded Sale is never destroyed.

    The footer opens the Return workflow, not the Sale correction one. The two are different acts:
    a Return books goods physically coming back and leaves the Sale intact, while a correction
    rewrites a Sale that was recorded wrongly. The panel's design — pick items, set quantities, give
    a reason, restock — describes a Return, so that is what it runs.

    Three regions, and only the middle one scrolls: a sale with fifteen lines must not push the
    footer actions out of reach.
--}}
<aside
    class="sales-detail-panel ui-sale-panel"
    x-cloak
    x-show="panelMode"
    x-on:keydown.escape.window="closePanel"
    role="dialog"
    aria-modal="false"
    aria-labelledby="sale-panel-title"
    tabindex="-1"
    x-ref="panel"
>
    <template x-if="sale">
        <div class="ui-sale-panel-inner">
            {{-- ── Header (never scrolls) ──────────────────────────────────────────────────── --}}
            <div class="ui-sale-panel-head">
                <div class="ui-sale-panel-heading">
                    {{-- Correction mode offers its way back to the details it came from, so the
                         operator is not forced to close the panel and find the row again. --}}
                    <button type="button" class="ui-sale-panel-back" x-show="panelMode === 'correction'"
                            x-on:click="showDetails">
                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                        Sale details
                    </button>
                    {{-- The sale number is already prefixed `S-`; nothing adds a second "Sale #".
                         The internal id and the ULID are never shown. --}}
                    <h2 id="sale-panel-title" x-text="panelMode === 'correction' ? 'Return items' : sale.number"></h2>
                    <p class="ui-sale-panel-when" x-text="panelMode === 'correction' ? sale.number : sale.recordedAt"></p>
                </div>
                <button type="button" class="ui-sale-panel-close" x-on:click="closePanel"
                        aria-label="Close sale detail" data-tooltip="Close">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            {{-- ── Details ─────────────────────────────────────────────────────────────────── --}}
            <template x-if="panelMode === 'details'">
                <div class="ui-sale-panel-scroll">
                    <div class="ui-sale-panel-body">
                        <div class="ui-sale-panel-customer">
                            <span class="sales-avatar is-large" aria-hidden="true" x-text="sale.initials"></span>
                            <span class="ui-sale-panel-customer-body">
                                <span class="ui-sale-panel-customer-name" x-text="sale.customer"></span>
                                {{-- A walk-in has no account to show. Its phone is displayed only
                                     where one was actually captured; none is ever invented. --}}
                                <span class="ui-sale-panel-customer-meta" x-show="sale.phone" x-text="sale.phone"></span>
                                <span class="ui-sale-panel-customer-meta" x-show="!sale.phone && sale.isWalkIn">No account on file</span>
                            </span>
                        </div>

                        <p class="ui-sale-panel-label">Items</p>

                        {{-- Loading, failure and content are three distinct states; the body is
                             never simply blank while the lines are on their way. --}}
                        <div class="ui-sale-panel-items-skeleton" x-show="linesLoading" aria-hidden="true">
                            <span></span><span></span><span></span>
                        </div>

                        <p class="ui-sale-panel-items-error" x-show="linesError">
                            <span x-text="linesError"></span>
                            <button type="button" class="ui-sale-panel-retry" x-on:click="loadLines">Retry</button>
                        </p>

                        <ul class="ui-sale-panel-items" x-show="!linesLoading && !linesError">
                            <template x-for="(line, index) in lines" x-bind:key="index">
                                <li>
                                    <span class="ui-sale-panel-item-name">
                                        <span x-text="line.name"></span><span class="ui-sale-panel-item-qty" x-text="'×' + line.quantity"></span>
                                    </span>
                                    <span class="ui-money" x-text="'₦' + line.total"></span>
                                </li>
                            </template>
                        </ul>

                        <dl class="ui-sale-panel-totals">
                            {{-- The breakdown appears only where the Sale actually carries a
                                 discount. Both figures are the Sale's own settled columns — nothing
                                 here recalculates money, so the panel cannot disagree with the
                                 receipt. --}}
                            <template x-if="sale.hasDiscount">
                                <div>
                                    <div>
                                        <dt>Subtotal</dt>
                                        <dd x-text="'₦' + sale.subtotal"></dd>
                                    </div>
                                    <div class="is-discount">
                                        <dt>Discount</dt>
                                        <dd x-text="'−₦' + sale.discount"></dd>
                                    </div>
                                </div>
                            </template>
                            <div class="is-total">
                                <dt>Total</dt>
                                <dd x-text="'₦' + sale.total"></dd>
                            </div>

                            {{-- The settlement position beneath the total: what the sale came to,
                                 what has been collected against it, and what is still owed.

                                 Amount paid and Balance due show for every sale, whatever its
                                 status, so the layout is the same wherever the operator looks.
                                 Returned, Refund due and Refunded appear only when they are not
                                 zero — rows of zeroes would say nothing and make the panel taller.

                                 Every figure is a stored column, not a sum taken here. Returning
                                 goods reduces `balance_due` without touching `total_amount`, which
                                 is why the total can read ₦168,000 while the balance reads less:
                                 the Sale stands as the record of what was sold, and the return is
                                 its own entry against it. --}}
                            <div class="is-settlement">
                                <dt>Amount paid</dt>
                                <dd x-text="'₦' + sale.paid"></dd>
                            </div>
                            <template x-if="sale.hasReturns">
                                <div class="is-settlement">
                                    <dt>Returned</dt>
                                    <dd x-text="'₦' + sale.returned"></dd>
                                </div>
                            </template>
                            <div class="is-settlement">
                                <dt>Balance due</dt>
                                <dd x-text="'₦' + sale.balance"></dd>
                            </div>
                            {{-- Credit a return created, which the business owes but has not yet
                                 handed over. Distinct from Refunded below, which is cash gone. --}}
                            <template x-if="sale.hasRefundDue">
                                <div class="is-settlement is-owing">
                                    <dt>Refund due</dt>
                                    <dd x-text="'₦' + sale.refundDue"></dd>
                                </div>
                            </template>
                            <template x-if="sale.hasRefunded">
                                <div class="is-settlement">
                                    <dt>Refunded</dt>
                                    <dd x-text="'₦' + sale.refunded"></dd>
                                </div>
                            </template>
                        </dl>

                        <p class="ui-sale-panel-status">
                            {{-- The same badge markup and the same tones as the table column, so
                                 one status can never be styled two ways. --}}
                            <span class="sales-badge" x-bind:class="'is-' + sale.paymentStatus">
                                <span class="sales-dot" aria-hidden="true"></span><span x-text="statusLabel"></span>
                            </span>
                            <span class="sales-badge is-voided" x-show="sale.status === 'voided'">Voided</span>
                        </p>

                        <p class="ui-sale-panel-recorded">
                            Recorded by <strong x-text="sale.seller"></strong><span x-show="sale.sellerRole" x-text="' · ' + sale.sellerRole"></span>
                        </p>
                    </div>

                    {{-- ── Footer actions (never scroll) ───────────────────────────────────────
                         A single action, as the design has it.

                         The action is a Return, and it says so. The panel it opens is the item
                         level workflow the design describes — pick the goods that came back, say
                         how many, give a reason, put the stock away — which is what Inventra calls
                         a Return. It posts to the existing audited `sales.returns.store`; nothing
                         here edits a recorded Sale, which stays standing as evidence of what was
                         sold. Correcting a mis-recorded Sale remains its own workflow on the Sale
                         page.

                         Receipt, Full detail, Process return and the void action all used to sit
                         here. None is gone from the application — they remain on the Sale page,
                         which the Sale ID still opens — but this panel is a concise summary rather
                         than a second Sale management screen, so it carries only this one action. --}}
                    <div class="ui-sale-panel-actions">
                        <button type="button" class="ui-sale-panel-correct"
                                x-show="sale.returnPanelUrl" x-on:click="openCorrection">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.9"
                                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                            Return items
                        </button>
                    </div>
                </div>
            </template>

            {{-- ── Correction ──────────────────────────────────────────────────────────────── --}}
            <template x-if="panelMode === 'correction'">
                <div class="ui-sale-panel-scroll" data-return-panel-fragment x-on:return-cancelled="showDetails">
                    {{-- Exactly one body state is visible at any moment, and the last is a
                         catch-all: if loading has finished, no error was set and no markup arrived,
                         something went wrong that nobody anticipated, and the panel says so rather
                         than showing the blank white box this screen kept showing. A branch can be
                         got wrong; silence cannot follow from it. --}}
                    <p class="ui-sale-panel-loading" x-show="correctionLoading">Loading return details…</p>

                    <div class="ui-sale-panel-body" x-show="!correctionLoading && !correctionError && !correctionHtml"
                         data-return-panel-fallback>
                        <p class="inventra-field-error">Unable to load return details.</p>
                        <button type="button" class="ui-button ui-sale-panel-retry-wide" x-on:click="openCorrection">Retry</button>
                        <button type="button" class="ui-button ui-sale-panel-retry-wide" x-on:click="showDetails">
                            Back to sale details
                        </button>
                    </div>

                    <div class="ui-sale-panel-body" x-show="correctionError">
                        <p class="inventra-field-error" x-text="correctionError"></p>
                        <button type="button" class="ui-button ui-sale-panel-retry-wide" x-on:click="openCorrection">Retry</button>
                        <button type="button" class="ui-button ui-sale-panel-retry-wide" x-on:click="showDetails">
                            Back to sale details
                        </button>
                    </div>
                    {{-- Server-rendered markup: the same form the full Record Return page posts,
                         so the two cannot offer different fields or send a different payload.

                         `x-html` is deliberately not used. It assigns innerHTML without walking the
                         inserted nodes, so the `x-data` arriving inside the fragment would never
                         initialise and every directive under it would sit inert.

                         `x-ref` is not used either, and that was the bug this replaced: this
                         element sits inside a `<template x-if>`, which is its own scope, so the ref
                         registered there and never appeared in the `salesList` component's own
                         `$refs`. The lookup silently returned undefined and the mount was skipped,
                         leaving exactly the blank body this panel kept showing. `$el` is the
                         element the directive is on, so it needs no lookup at all and cannot be
                         scoped away. --}}
                    <div class="ui-correct-wrap" data-return-panel-mount
                         x-show="correctionHtml" x-init="mountCorrection($el)"></div>
                </div>
            </template>
        </div>
    </template>
</aside>
