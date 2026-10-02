<?php

namespace Tests\Feature\Sale;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the Record Sale overlays against the class of bug that let the Cart modal open by itself
 * on a fresh page showing an approved *and* a declined discount at the same time.
 *
 * The cause was not in the markup: both `Alpine.data()` calls for this page were registered after
 * `Livewire.start()`, so `x-data="recordSale"` bound to nothing. With no component, no `x-show`
 * ever evaluated and no `x-cloak` was ever removed, which left every branch of every modal painted
 * at once with empty `x-text` values. These tests therefore check the script's registration order
 * as well as the templates.
 */
class RecordSaleModalStateTest extends TestCase
{
    use RefreshDatabase;

    private function page(): string
    {
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        Customer::factory()->create(['is_active' => true]);
        Product::factory()->create(['selling_price' => '1000.00', 'current_stock' => '10.000']);

        return $this->actingAs($rep)->get(route('sales.create'))->assertOk()->getContent();
    }

    private function script(): string
    {
        return file_get_contents(resource_path('js/app.js'));
    }

    /**
     * The real defect. A component registered after Alpine has started is never seen, so the page
     * renders as inert HTML with every conditional branch visible.
     */
    public function test_every_alpine_component_is_registered_before_alpine_starts(): void
    {
        $script = $this->script();
        // strpos, not mb_strpos: PREG_OFFSET_CAPTURE reports byte offsets, and this file contains
        // multi-byte characters. Comparing a character offset against a byte offset silently
        // misjudges the order.
        $startedAt = strpos($script, 'Livewire.start();');

        $this->assertNotFalse($startedAt, 'Livewire.start() should be called exactly once in app.js.');
        $this->assertSame(1, substr_count($script, 'Livewire.start();'));

        preg_match_all("/Alpine\.data\('([a-zA-Z]+)'/", $script, $matches, PREG_OFFSET_CAPTURE);
        $this->assertNotEmpty($matches[1], 'Expected Alpine components to be registered in app.js.');

        foreach ($matches[1] as [$name, $offset]) {
            $this->assertLessThan(
                $startedAt,
                $offset,
                "Alpine component '{$name}' is registered after Livewire.start(); it will never bind, "
                    .'leaving x-cloak in place and every x-show branch rendered at once.',
            );
        }
    }

    public function test_the_record_sale_page_declares_its_components(): void
    {
        $script = $this->script();

        foreach (['recordSale', 'saleComplete'] as $component) {
            $this->assertStringContainsString("Alpine.data('{$component}'", $script);
        }

        $this->assertStringContainsString('x-data="recordSale"', $this->page());
    }

    /** Nothing may be visible over the form until the operator asks for it. */
    public function test_every_overlay_is_cloaked_and_closed_on_a_fresh_page(): void
    {
        $partials = ['_cart-modal', '_discount-modal', '_waiting-modal', '_resume-modal', '_product-picker', '_complete-modal'];

        foreach ($partials as $partial) {
            $markup = file_get_contents(resource_path("views/sales/{$partial}.blade.php"));
            $this->assertMatchesRegularExpression(
                '/x-cloak\s/',
                $markup,
                "{$partial} must carry x-cloak so it cannot paint before Alpine initialises.",
            );
        }

        // And the flags those overlays read all start closed.
        $script = $this->script();
        foreach (['cartOpen: false', 'discountOpen: false', 'resumeOpen: false', 'productOpen: false'] as $initial) {
            $this->assertStringContainsString($initial, $script);
        }

        $this->assertStringContainsString("requestState: 'none'", $script);
        $this->assertStringContainsString('draftId: null', $script);
        $this->assertStringContainsString("approvedAmount: '0.00'", $script);
    }

    public function test_the_cloak_rule_actually_hides_cloaked_markup(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/\[x-cloak\]\s*\{\s*display:\s*none\s*!important/',
            $css,
            'x-cloak must resolve to display:none !important, or cloaked overlays still paint.',
        );
    }

    /**
     * The cart's three looks are mutually exclusive because they read one derived value, not
     * several independent booleans that could all be true at once.
     */
    public function test_the_cart_modal_branches_on_a_single_exclusive_state(): void
    {
        $markup = file_get_contents(resource_path('views/sales/_cart-modal.blade.php'));

        foreach (["cartState === 'approved'", "cartState === 'declined'"] as $condition) {
            $this->assertStringContainsString($condition, $markup);
        }

        // The old shape: separate conditions over the raw request status inside this modal.
        $this->assertStringNotContainsString("requestState === 'approved'", $markup);
        $this->assertStringNotContainsString("requestState === 'declined'", $markup);

        // cartState is derived and can only ever answer with one of three values.
        $script = $this->script();
        $this->assertStringContainsString('get cartState()', $script);
        $this->assertMatchesRegularExpression(
            "/get cartState\(\) \{\s*if \(this\.requestState === 'approved'\) return 'approved';\s*"
                ."if \(this\.requestState === 'declined'\) return 'declined';\s*return 'normal';/",
            $script,
        );
    }

    /** An empty cart has no total to show and nothing to sell, so it must not be openable. */
    public function test_the_cart_cannot_be_opened_or_submitted_while_empty(): void
    {
        $script = $this->script();

        $this->assertStringContainsString('get cartHasItems()', $script);
        $this->assertMatchesRegularExpression(
            '/openCart\(\) \{\s*if \(!this\.cartHasItems\) return;/',
            $script,
            'openCart must refuse an empty cart.',
        );
        $this->assertMatchesRegularExpression(
            '/submitFromCart\(\) \{\s*if \(!this\.cartHasItems\) return;/',
            $script,
            'submitFromCart must refuse an empty cart.',
        );

        // Every path that shows the cart goes through openCart, so the guard cannot be bypassed.
        $this->assertSame(
            1,
            mb_substr_count($script, 'this.cartOpen = true;'),
            'The cart may only be opened inside openCart(), which carries the empty-cart guard.',
        );
    }

    /**
     * The Record Sale form's shape, as the design specifies it.
     *
     * These are layout facts the design fixed deliberately — notes and a subtotal line were removed
     * from the form, the cart is reached through the discount workflow rather than a button, and
     * the payment tabs fill their row with the chosen one solid blue. They are asserted so a later
     * change has to be a decision rather than a regression.
     */
    public function test_the_form_matches_the_agreed_layout(): void
    {
        $page = $this->page();
        // The sale form specifically: the app layout renders a logout form before it.
        $opens = strpos($page, 'class="ui-sale-form"');
        $this->assertNotFalse($opens, 'Expected the Record Sale form on the page.');
        $form = substr($page, $opens, strpos($page, '</form>', $opens) - $opens);

        // Removed from the form.
        $this->assertStringNotContainsString('name="notes"', $form);
        $this->assertStringNotContainsString('Review cart', $page);
        $this->assertStringNotContainsString('>Subtotal<', $form);

        // The cart modal keeps its subtotal: an approved or declined discount needs the breakdown.
        $this->assertStringContainsString('>Subtotal<', $page);

        // Present, and named as the design names them.
        $this->assertStringContainsString('>Payment status</h2>', $form);
        $this->assertStringNotContainsString('>Payment</h2>', $form);
        $this->assertStringContainsString('Request Discount', $form);
        $this->assertStringContainsString('Record Sale', $form);
        $this->assertStringContainsString('>Cancel</a>', $form);

        // Everything the server needs is still posted. `payment_method` is deliberately absent:
        // the screen no longer asks, and StoreSaleRequest defaults an unstated method to cash.
        foreach (['is_walk_in', 'customer_id', 'sale_date', 'amount_paid', 'sale_draft_id'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $form);
        }

        $this->assertStringNotContainsString('name="payment_method"', $form);
    }

    public function test_the_payment_tabs_fill_the_row_and_the_chosen_one_is_blue(): void
    {
        $css = file_get_contents(resource_path('css/prototype.css'));

        $this->assertMatchesRegularExpression('/\.ui-segment \{[^}]*width: 100%/', $css,
            'The payment status tabs must fill the width of their row.');
        $this->assertMatchesRegularExpression('/\.ui-segment-option \{[^}]*flex: 1 1 0/', $css,
            'Each tab must take an equal share of the row.');
        $this->assertMatchesRegularExpression('/\.ui-segment-option\.is-active \{[^}]*background: var\(--inventra-primary/', $css,
            'The chosen tab must be solid blue.');
        $this->assertMatchesRegularExpression('/\.ui-segment-option\.is-active \{[^}]*color: #fff/', $css,
            'The chosen tab needs white text to stay legible on blue.');
    }

    /** "+ Add new customer  or  Walk-in customer" is a link, a word and a button on one baseline. */
    public function test_the_customer_alternatives_sit_on_one_line(): void
    {
        $page = $this->page();

        foreach (['ui-sale-alt-link', 'ui-sale-alt-or', 'ui-sale-alt-action'] as $part) {
            $this->assertStringContainsString($part, $page);
        }

        $css = file_get_contents(resource_path('css/prototype.css'));
        $this->assertMatchesRegularExpression('/\.ui-sale-alt \{[^}]*display: flex[^}]*align-items: center/', $css);
        // The anchor and the button share a height, so the two control types cannot misalign.
        $this->assertMatchesRegularExpression(
            '/\.ui-sale-alt-link, \.ui-sale-alt-action \{[^}]*align-items: center[^}]*height: 20px/',
            $css,
        );
    }

    /**
     * Clicking a customer result must actually select the customer.
     *
     * It did not: the option used `x-on:click`, and pressing the mouse over it blurred the input
     * first. The combobox is only on screen while no buyer is chosen, so the blur tore the list out
     * of the document before the click could land — the dropdown closed and nothing was selected.
     * Selection now runs on `mousedown.prevent`, which keeps focus on the input, and the list is
     * closed by a click-away on the combobox root rather than by the input's blur.
     */
    public function test_a_customer_result_is_selected_on_mousedown_not_click(): void
    {
        $markup = file_get_contents(resource_path('views/sales/create.blade.php'));

        $this->assertStringContainsString('x-on:mousedown.prevent="chooseCustomer(row)"', $markup,
            'Selecting a customer must run on mousedown with the default prevented, or the input '
                .'blurs and hides the list before the click arrives.');
        $this->assertStringNotContainsString('x-on:click="chooseCustomer(row)"', $markup);

        // Closing belongs to the combobox root, which contains both the input and the list.
        $this->assertStringContainsString('x-on:click.outside="closeCustomerList"', $markup);

        // Focus must not reopen a list the operator has just finished with.
        $this->assertStringContainsString('x-on:focus="customerFocus"', $markup);
        $this->assertStringNotContainsString('x-on:focus="customerInput"', $markup);
    }

    /** Mouse and keyboard share one selection method, so the two cannot drift apart. */
    public function test_mouse_and_keyboard_selection_share_one_method(): void
    {
        $script = $this->script();

        $this->assertMatchesRegularExpression(
            '/chooseCustomerAtCursor\(event\) \{.*?this\.chooseCustomer\(row\);/s',
            $script,
            'Keyboard selection must go through chooseCustomer, the same method the mouse uses.',
        );

        // Focusing with a buyer already chosen must not reopen the list.
        $this->assertMatchesRegularExpression(
            '/customerFocus\(\) \{\s*if \(this\.customer !== null \|\| this\.walkIn\) return;/',
            $script,
        );
    }

    /** A slow answer for "M" must not replace a fast one for "Mi". */
    public function test_stale_search_responses_cannot_overwrite_newer_ones(): void
    {
        $script = $this->script();

        foreach (['customerSearchTicket', 'productSearchTicket'] as $ticket) {
            $this->assertStringContainsString($ticket.': 0', $script,
                "{$ticket} must exist so an overtaken search cannot publish its results.");
            $this->assertStringContainsString('const ticket = ++this.'.$ticket.';', $script);
        }
    }

    /**
     * The Sales index owns its Record Sale action, so the shell's copy is suppressed there.
     *
     * Two identical primary actions on one screen is the kind of thing that only shows up in a
     * rendered page, so this asserts against the real response rather than the view fragment.
     */
    public function test_the_sales_index_shows_exactly_one_record_sale_action(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $sales = $this->actingAs($admin)->get(route('sales.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count(mb_strtolower($sales), 'record sale'),
            'The Sales index must show exactly one Record Sale action.');
        $this->assertSame(1, substr_count($sales, '<h1'), 'and exactly one page title.');

        // Every other screen keeps the shell action, so suppressing it is scoped to this route.
        $this->actingAs($admin)->get(route('customers.index'))->assertOk()->assertSee('Record sale');
    }

    /**
     * The S/N column collapsed into Sale ID because `.inventra-content th.ui-sn { width: 1% }` out-
     * specified the page's own width under `table-layout: fixed`. The Sales table now owns its
     * widths through a <colgroup> and uses its own class names, so no inherited rule reaches it.
     */
    public function test_the_sales_table_owns_its_column_widths(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $html = $this->actingAs($admin)->get(route('sales.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<colgroup>', $html);
        $this->assertSame(7, substr_count($html, '<col class="sales-col-'));
        // The inherited class is gone from this table entirely.
        $this->assertStringNotContainsString('ui-sn', $html);

        // And the rule other tables rely on is untouched.
        $css = file_get_contents(resource_path('css/prototype.css'));
        $this->assertMatchesRegularExpression('/\.inventra-content th\.ui-sn[^}]*width: 1%/', $css);
    }

    /**
     * The CSP-safe Alpine build resolves identifiers against the component only, and evaluates a
     * single expression. Globals and multi-statement attributes fail silently in the browser.
     */
    public function test_no_sales_template_uses_a_csp_unsafe_expression(): void
    {
        foreach (glob(resource_path('views/sales/*.blade.php')) as $file) {
            $markup = file_get_contents($file);
            $name = basename($file);

            preg_match_all('/\sx-(?:on:[a-z.\-]+|bind:[a-z\-]+|show|if|text|model|data)="([^"]*)"/', $markup, $matches);

            foreach ($matches[1] as $expression) {
                $this->assertStringNotContainsString(';', $expression,
                    "{$name}: multi-statement Alpine expression is not supported by the CSP build: {$expression}");

                $this->assertDoesNotMatchRegularExpression(
                    '/\b(JSON|Object|Number|String|Math|Date|window|document|parseInt|parseFloat)\b/',
                    $expression,
                    "{$name}: global '{$expression}' is not resolvable by the CSP build; move it into the component.",
                );
            }
        }
    }
}
