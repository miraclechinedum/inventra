{{--
    Cart review — one modal, exactly one of three states.

    Normal    no discount was asked for, or the wait was abandoned.
    Approved  green banner; the discount is included in the total.
    Declined  red banner; the requested amount is shown struck through as context only and the
              total stays exactly what it was. A declined discount is never applied, here or at the
              server.

    Every branch below tests `cartState`, which is a single derived string, so the three states are
    mutually exclusive by construction — the template cannot express "approved and declined" because
    there is only one value to read. Earlier this modal tested several independent conditions, which
    is what allowed both banners to render at once.

    The figures are the component's running totals, shown so the sale can be read before it is
    committed. CreateSale recomputes all of them and takes the discount amount from the database.
--}}
<div
    class="ui-modal"
    x-cloak
    x-show="cartOpen"
    x-on:keydown.escape.window="closeCart"
    role="dialog"
    aria-modal="true"
    aria-labelledby="cart-modal-title"
>
    <div class="ui-modal-backdrop" x-on:click="closeCart" aria-hidden="true"></div>
    <div class="ui-modal-panel">
        <div class="ui-modal-head">
            <h2 id="cart-modal-title">Cart</h2>
            <button type="button" class="ui-icon-action" x-on:click="closeCart" aria-label="Close" data-tooltip="Close">&times;</button>
        </div>
        <div class="ui-modal-body">
            {{-- x-if, not x-show: a branch that does not apply is not in the document at all, so a
                 state it does not belong to cannot show its banner even for a frame. --}}
            <template x-if="cartState === 'approved'">
                <div class="ui-sale-banner is-approved" role="status">
                    <span class="ui-sale-banner-title">Discount approved</span>
                    <span class="ui-sale-banner-amount" x-text="'− ' + money(discountKobo) + ' by admin'"></span>
                </div>
            </template>
            <template x-if="cartState === 'declined'">
                <div class="ui-sale-banner is-declined" role="status">
                    <span class="ui-sale-banner-title">Discount declined</span>
                    <span class="ui-sale-banner-amount" x-text="'− ' + money(kobo(discountAmount)) + ' by admin'"></span>
                </div>
            </template>

            <div class="ui-form-body ui-sale-modal-body">
                <ul class="ui-sale-cart-list">
                    <template x-for="(line, index) in filledLines" x-bind:key="index">
                        <li class="ui-sale-cart-item">
                            <span class="ui-sale-cart-name">
                                <span x-text="line.product.name"></span>
                                <span class="ui-sale-cart-qty" x-text="line.quantity + ' × ' + money(kobo(line.product.price))"></span>
                            </span>
                            <span class="ui-money" x-text="money(lineTotal(line))"></span>
                        </li>
                    </template>
                </ul>

                <div class="ui-sale-summary-lines">
                    <div class="ui-sale-summary-row">
                        <span>Subtotal</span>
                        <span class="ui-money" x-text="money(subtotalKobo)"></span>
                    </div>

                    <template x-if="cartState === 'approved'">
                        <div class="ui-sale-summary-row is-discount">
                            <span>Discount <span class="ui-sale-tag">APPROVED</span></span>
                            <span class="ui-money" x-text="'−' + money(discountKobo)"></span>
                        </div>
                    </template>

                    {{-- Declined: shown so the operator can see what was asked and refused, struck
                         through so it cannot be mistaken for money coming off the total. --}}
                    <template x-if="cartState === 'declined'">
                        <div class="ui-sale-summary-row is-declined">
                            <span>Requested discount <span class="ui-sale-tag is-declined">DECLINED</span></span>
                            <span class="ui-money ui-sale-struck" x-text="'−' + money(kobo(discountAmount))"></span>
                        </div>
                    </template>

                    <div class="ui-sale-summary-row is-total">
                        <span>Total</span>
                        <span class="ui-money" x-text="money(totalKobo)"></span>
                    </div>
                </div>
            </div>

            <div class="ui-form-actions ui-form-actions-end">
                <button type="button" class="ui-button" x-on:click="closeCart">Keep editing</button>
                <button type="button" class="inventra-primary-action" x-on:click="submitFromCart"
                        x-bind:disabled="!ready || submitting">Continue to payment &rarr;</button>
            </div>
        </div>
    </div>
</div>
