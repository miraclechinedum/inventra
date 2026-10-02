<?php

namespace Tests\Feature\Returns;

use App\Actions\Sale\CreateSale;
use App\Actions\Sale\IssueReturnRequest;
use App\Actions\Sale\RecordSaleReturn;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use App\Support\ReturnSettlementPreview;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Recording a Return from the Sales-list side panel.
 *
 * The panel is a second doorway onto the existing Return workflow, not a second implementation of
 * it: the fragment issues the same token, posts to the same `store`, and every figure it shows is
 * recomputed by RecordSaleReturn under a row lock. These check the doorway — that it opens, that it
 * offers only what may actually be returned, that it prices from the sale's own history, and that
 * it cannot be talked into returning more than is left.
 */
class ReturnPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $rep;

    private Customer $customer;

    private Product $filter;

    private Product $plug;

    protected function setUp(): void
    {
        parent::setUp();

        // The suite must never touch the development database. Asserted here rather than trusting
        // the environment name, because only the database's own name is proof of which one it is.
        $this->assertSame(
            'inventra_test',
            DB::connection()->getDatabaseName(),
            'Refusing to run: these tests write, and this is not the test database.'
        );

        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Ada Admin']);
        $this->manager = User::factory()->create(['role' => UserRole::Manager]);
        $this->rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $this->customer = Customer::factory()->create(['first_name' => 'Miracle', 'last_name' => 'Chinedum', 'is_active' => true]);
        $this->filter = Product::factory()->create(['name' => 'Toyota Camry Oil Filter', 'selling_price' => '42000.00', 'current_stock' => '50.000']);
        $this->plug = Product::factory()->create(['name' => 'Spark plug', 'selling_price' => '2400.00', 'current_stock' => '80.000']);
    }

    /** @param  list<array{0: Product, 1: string}>  $lines */
    private function sale(array $lines, string $paid = '0.00', bool $walkIn = false): Sale
    {
        return app(CreateSale::class)->execute($this->admin, [
            'is_walk_in' => $walkIn,
            'customer_id' => $walkIn ? null : $this->customer->id,
            'sale_date' => CarbonImmutable::now(config('business.timezone'))->toDateString(),
            'products' => array_map(fn (array $line): array => [
                'product_id' => $line[0]->id, 'quantity' => $line[1],
            ], $lines),
            'payment_method' => 'cash',
            'amount_paid' => $paid,
        ]);
    }

    /** The failure this whole change exists to fix: the panel body must carry a real form. */
    public function test_the_panel_fragment_loads_and_is_not_blank(): void
    {
        $sale = $this->sale([[$this->filter, '4']]);

        $html = $this->actingAs($this->admin)
            ->get(route('sales.returns.panel', $sale))
            ->assertOk()
            ->getContent();

        $this->assertNotSame('', trim($html));
        // A fragment, not a page: a whole document would be stripped of its form when injected.
        $this->assertStringNotContainsString('<!DOCTYPE', $html);
        $this->assertStringNotContainsString('<html', $html);
        // The parts the design calls for.
        // Stable hooks, so a test proves the real parts arrived rather than matching prose.
        $this->assertStringContainsString('data-return-panel-form', $html);
        $this->assertStringContainsString('data-return-panel-items', $html);
        $this->assertStringContainsString('data-return-panel-footer', $html);
        $this->assertStringContainsString('Select items to return', $html);
        $this->assertStringContainsString('Toyota Camry Oil Filter', $html);
        $this->assertStringContainsString('Faulty', $html);
        $this->assertStringContainsString('Wrong item', $html);
        $this->assertStringContainsString('Changed mind', $html);
        $this->assertStringContainsString('Process return', $html);
        $this->assertStringContainsString('name="request_token"', $html);
        $this->assertStringContainsString(route('sales.returns.store', $sale), $html);
    }

    /**
     * The panel body has a state for every outcome, including one nobody planned for.
     *
     * The blank body this panel kept showing was not an error that failed to render — it was no
     * branch matching at all, so nothing rendered and nothing said why. The fallback closes that:
     * if loading has finished, no error was set and no markup arrived, the panel still says
     * something. A branch can be written wrongly; silence cannot follow from it.
     */
    public function test_the_panel_body_always_has_a_visible_state(): void
    {
        $panel = file_get_contents(resource_path('views/sales/_detail-panel.blade.php'));

        // Loading, explicit error, catch-all, and the mounted form.
        $this->assertStringContainsString('Loading return details…', $panel);
        $this->assertStringContainsString('x-show="correctionError"', $panel);
        $this->assertStringContainsString('data-return-panel-fallback', $panel);
        $this->assertStringContainsString('Unable to load return details.', $panel);
        // Both failure states offer a way forward and a way back.
        $this->assertStringContainsString('Retry', $panel);
        $this->assertStringContainsString('Back to sale details', $panel);

        // The catch-all is the exact negation of the other three, so they cannot all be false.
        $this->assertStringContainsString(
            'x-show="!correctionLoading && !correctionError && !correctionHtml"',
            $panel
        );
    }

    /**
     * The fragment is mounted through the element handed to it, not through a scoped `$refs` lookup.
     *
     * That lookup was the bug: the mount point sits inside a `<template x-if>`, which is its own
     * Alpine scope, so `x-ref` registered there and never reached the list component's `$refs`.
     * `mountCorrection` returned at its guard and the panel stayed blank, silently.
     */
    public function test_the_fragment_is_mounted_without_a_scoped_ref_lookup(): void
    {
        $panel = file_get_contents(resource_path('views/sales/_detail-panel.blade.php'));
        $script = file_get_contents(resource_path('js/app.js'));

        // The element passes itself in.
        $this->assertStringContainsString('x-init="mountCorrection($el)"', $panel);
        $this->assertStringNotContainsString('x-ref="correctionMount"', $panel);
        $this->assertStringNotContainsString('$refs.correctionMount', $script);
        // And `x-html` is not used, which would insert the markup without initialising Alpine on it.
        $this->assertStringNotContainsString('x-html="correctionHtml"', $panel);
    }

    public function test_the_panel_shows_historical_prices_not_current_ones(): void
    {
        $sale = $this->sale([[$this->filter, '4']]);

        // The catalogue moves on; the sale does not.
        $this->filter->update(['selling_price' => '99999.00', 'name' => 'Renamed After The Sale']);

        $html = $this->actingAs($this->admin)->get(route('sales.returns.panel', $sale))->assertOk()->getContent();

        // Sold at 42,000 each, and named as it was sold.
        $this->assertStringContainsString('Toyota Camry Oil Filter', $html);
        $this->assertStringContainsString('42,000', $html);
        $this->assertStringNotContainsString('99,999', $html);
        $this->assertStringNotContainsString('Renamed After The Sale', $html);
        // Whole naira, as everywhere else on these screens.
        $this->assertStringNotContainsString('42,000.00', $html);
    }

    public function test_the_panel_offers_only_the_remaining_returnable_quantity(): void
    {
        $sale = $this->sale([[$this->filter, '5']]);
        $item = $sale->items()->sole();

        // Two already sent back through the real workflow.
        $this->recordReturn($sale, [['sale_item_id' => $item->id, 'quantity' => '2', 'disposition' => 'restock']]);

        $html = $this->actingAs($this->admin)->get(route('sales.returns.panel', $sale))->assertOk()->getContent();

        // Bought 5, two returned, so three remain — not five.
        $this->assertStringContainsString('Bought 5', $html);
        $this->assertStringContainsString('2 returned', $html);
        $this->assertStringContainsString('&quot;remaining&quot;:&quot;3&quot;', $html);
    }

    public function test_a_fully_returned_line_cannot_be_selected_again(): void
    {
        $sale = $this->sale([[$this->filter, '2']]);
        $item = $sale->items()->sole();

        $this->recordReturn($sale, [['sale_item_id' => $item->id, 'quantity' => '2', 'disposition' => 'restock']]);

        $html = $this->actingAs($this->admin)->get(route('sales.returns.panel', $sale))->assertOk()->getContent();

        $this->assertStringContainsString('Already returned in full', $html);
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringContainsString('&quot;exhausted&quot;:true', $html);
    }

    public function test_a_multi_item_sale_lists_every_line(): void
    {
        $sale = $this->sale([[$this->filter, '2'], [$this->plug, '4']]);

        $html = $this->actingAs($this->admin)->get(route('sales.returns.panel', $sale))->assertOk()->getContent();

        $this->assertStringContainsString('Toyota Camry Oil Filter', $html);
        $this->assertStringContainsString('Spark plug', $html);
        $this->assertSame(2, substr_count($html, 'ui-return-pick'));
    }

    public function test_a_walk_in_sale_can_be_returned_against(): void
    {
        $sale = $this->sale([[$this->filter, '1']], '42000.00', walkIn: true);

        $this->actingAs($this->admin)->get(route('sales.returns.panel', $sale))
            ->assertOk()
            ->assertSee('Select items to return');
    }

    /**
     * Returning goods on a part-paid Sale cancels what is owed before it refunds anything.
     *
     * This is the figure the panel must not get wrong. On a ₦168,000 sale where only ₦40,000 was
     * ever handed over, sending everything back does not put ₦168,000 in the customer's hand — it
     * clears the ₦128,000 still owed and returns the ₦40,000 actually taken.
     */
    public function test_the_settlement_preview_splits_balance_reduction_from_refundable_cash(): void
    {
        $sale = $this->sale([[$this->filter, '4']], '40000.00');
        $item = $sale->items()->sole();

        $preview = ReturnSettlementPreview::for($sale, [
            ['quantity' => '4', 'unit_price' => (string) $item->unit_price],
        ]);

        $this->assertSame('168000.00', $preview['merchandise']);
        $this->assertSame('128000.00', $preview['reduction'], 'what the customer no longer owes');
        $this->assertSame('40000.00', $preview['credit'], 'only money actually taken can come back');

        // And the recorded return agrees with the preview, because both split the same way.
        $return = $this->recordReturn($sale, [
            ['sale_item_id' => $item->id, 'quantity' => '4', 'disposition' => 'restock'],
        ]);

        $this->assertSame('168000.00', $return->merchandise_value);
        $this->assertSame('128000.00', $return->receivable_reduction);
        $this->assertSame('40000.00', $return->refundable_credit_created);
    }

    /** An unpaid sale refunds nothing: there is no cash to give back, only debt to cancel. */
    public function test_an_unpaid_sale_reduces_the_balance_and_refunds_nothing(): void
    {
        $sale = $this->sale([[$this->filter, '2']], '0.00');
        $item = $sale->items()->sole();

        $preview = ReturnSettlementPreview::for($sale, [
            ['quantity' => '2', 'unit_price' => (string) $item->unit_price],
        ]);

        $this->assertSame('84000.00', $preview['reduction']);
        $this->assertSame('0.00', $preview['credit']);
    }

    /** A fully paid sale owes nothing, so the whole value is refundable. */
    public function test_a_paid_sale_makes_the_whole_value_refundable(): void
    {
        $sale = $this->sale([[$this->filter, '2']], '84000.00');
        $item = $sale->items()->sole();

        $preview = ReturnSettlementPreview::for($sale, [
            ['quantity' => '2', 'unit_price' => (string) $item->unit_price],
        ]);

        $this->assertSame('0.00', $preview['reduction']);
        $this->assertSame('84000.00', $preview['credit']);
    }

    public function test_the_panel_is_closed_to_roles_that_may_not_record_returns(): void
    {
        $sale = $this->sale([[$this->filter, '1']]);

        // Admin and Manager may; a Sales Rep may not, exactly as the store endpoint enforces.
        $this->actingAs($this->admin)->get(route('sales.returns.panel', $sale))->assertOk();
        $this->actingAs($this->manager)->get(route('sales.returns.panel', $sale))->assertOk();
        $this->actingAs($this->rep)->get(route('sales.returns.panel', $sale))->assertForbidden();
    }

    public function test_the_panel_is_closed_to_a_guest(): void
    {
        $sale = $this->sale([[$this->filter, '1']]);

        $this->get(route('sales.returns.panel', $sale))->assertRedirect(route('login'));
    }

    /** The row offers the panel only to roles that may use it. */
    public function test_the_sales_row_offers_the_return_panel_by_role(): void
    {
        $sale = $this->sale([[$this->filter, '1']]);

        $adminHtml = $this->actingAs($this->admin)->get(route('sales.index'))->assertOk()->getContent();
        $this->assertStringContainsString('returnPanelUrl', $adminHtml);
        // The row carries its data as JSON in an attribute, so the URL arrives with its slashes
        // escaped by `json_encode` and its quotes escaped by Blade.
        $this->assertStringContainsString(
            e(str_replace('/', '\\/', route('sales.returns.panel', $sale))),
            $adminHtml
        );

        // A Sales Rep sees only their own sales, so they need one of their own to inspect. It
        // carries no return panel URL, because a Rep may not record returns.
        $repSale = app(CreateSale::class)->execute($this->rep, [
            'is_walk_in' => false,
            'customer_id' => $this->customer->id,
            'sale_date' => CarbonImmutable::now(config('business.timezone'))->toDateString(),
            'products' => [['product_id' => $this->plug->id, 'quantity' => '1']],
            'payment_method' => 'cash',
            'amount_paid' => '0.00',
        ]);

        $repHtml = $this->actingAs($this->rep)->get(route('sales.index'))->assertOk()->getContent();
        $this->assertStringContainsString($repSale->sale_number, $repHtml);
        $this->assertStringContainsString('&quot;returnPanelUrl&quot;:null', $repHtml);
    }

    /** Over-returning is refused by the action, whatever the form submitted. */
    public function test_the_server_refuses_more_than_the_remaining_quantity(): void
    {
        $sale = $this->sale([[$this->filter, '3']]);
        $item = $sale->items()->sole();

        $this->expectExceptionMessage('Returned quantity exceeds the remaining returnable quantity.');
        $this->recordReturn($sale, [['sale_item_id' => $item->id, 'quantity' => '4', 'disposition' => 'restock']]);
    }

    /** A replayed token returns the original Return rather than recording a second one. */
    public function test_a_repeated_submission_does_not_return_the_units_twice(): void
    {
        $sale = $this->sale([[$this->filter, '4']]);
        $item = $sale->items()->sole();
        $lines = [['sale_item_id' => $item->id, 'quantity' => '2', 'disposition' => 'restock']];

        $token = app(IssueReturnRequest::class)->execute($this->admin, $sale, session()->driver());
        $payload = ['request_token' => $token, 'reason' => 'Faulty', 'items' => $lines];

        $first = app(RecordSaleReturn::class)->execute($this->admin, $sale, $payload, session()->getId());
        $second = app(RecordSaleReturn::class)->execute($this->admin, $sale, $payload, session()->getId());

        // The same Return, not a second one — and the stock moved once.
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $sale->returns()->count());
        $this->assertSame('2.000', (string) DB::table('sale_return_items')->where('sale_item_id', $item->id)->sum('quantity_returned'));
    }

    /** Restocking puts back exactly what came back, once, through an inventory movement. */
    public function test_stock_is_restored_exactly_once(): void
    {
        $sale = $this->sale([[$this->filter, '4']]);
        $item = $sale->items()->sole();
        $afterSale = $this->filter->fresh()->current_stock;

        $this->recordReturn($sale, [['sale_item_id' => $item->id, 'quantity' => '2', 'disposition' => 'restock']]);

        $this->assertSame(
            bcadd((string) $afterSale, '2', 3),
            (string) $this->filter->fresh()->current_stock
        );
        $this->assertSame(1, $this->filter->movements()->where('type', 'sale_return')->count());
    }

    /** Goods kept off the shelf are returned financially but not restocked. */
    public function test_a_non_restock_return_does_not_move_stock(): void
    {
        $sale = $this->sale([[$this->filter, '2']]);
        $item = $sale->items()->sole();
        $afterSale = $this->filter->fresh()->current_stock;

        $this->recordReturn($sale, [['sale_item_id' => $item->id, 'quantity' => '1', 'disposition' => 'non_restock']]);

        $this->assertSame((string) $afterSale, (string) $this->filter->fresh()->current_stock);
        $this->assertSame(0, $this->filter->movements()->where('type', 'sale_return')->count());
    }

    /** Opening the panel is a read: it must not alter the Sale or its stock. */
    public function test_opening_the_panel_mutates_nothing(): void
    {
        $sale = $this->sale([[$this->filter, '2']]);
        $before = $sale->fresh()->only(['total_amount', 'amount_paid', 'balance_due', 'payment_status', 'status']);
        $stock = $this->filter->fresh()->current_stock;

        $this->actingAs($this->admin)->get(route('sales.returns.panel', $sale))->assertOk();

        $this->assertSame($before, $sale->fresh()->only(['total_amount', 'amount_paid', 'balance_due', 'payment_status', 'status']));
        $this->assertSame((string) $stock, (string) $this->filter->fresh()->current_stock);
        $this->assertSame(0, $sale->returns()->count());
    }

    /** The Return leaves an audit record, as every money-moving act does. */
    public function test_the_return_is_audited(): void
    {
        $sale = $this->sale([[$this->filter, '2']]);
        $item = $sale->items()->sole();

        $return = $this->recordReturn($sale, [['sale_item_id' => $item->id, 'quantity' => '1', 'disposition' => 'restock']]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'sale_return_recorded',
            'auditable_id' => $return->id,
            'actor_id' => $this->admin->id,
        ]);
    }

    /**
     * The summary sentence for a fully paid Sale: cash comes back, nothing is owed.
     */
    #[DataProvider('paidQuantities')]
    public function test_a_paid_sale_previews_a_cash_refund(string $quantity, string $sentence): void
    {
        $sale = $this->sale([[$this->filter, '4']], '168000.00');
        $item = $sale->items()->sole();

        $body = $this->actingAs($this->admin)->getJson(route('sales.returns.preview', $sale).'?'.http_build_query([
            'items' => [['sale_item_id' => $item->id, 'quantity' => $quantity]],
            'restock' => 1,
        ]))->assertOk()->json();

        $this->assertSame($sentence, $body['sentence']);
        // Nothing is owed, so the whole value is refundable and no balance clause appears.
        $this->assertSame('0.00', $body['balance_reduction']);
        $this->assertStringNotContainsString('reduce balance', $body['sentence']);
    }

    /** @return array<string, array{string, string}> */
    public static function paidQuantities(): array
    {
        return [
            'one unit is singular' => ['1', 'Refund ₦42,000 · restock 1 unit'],
            'two units are plural' => ['2', 'Refund ₦84,000 · restock 2 units'],
            'three units' => ['3', 'Refund ₦126,000 · restock 3 units'],
            'all four' => ['4', 'Refund ₦168,000 · restock 4 units'],
        ];
    }

    /**
     * A part-paid Sale splits the effect, and never calls unpaid money a refund.
     *
     * ₦40,000 was handed over against a ₦168,000 sale. Returning everything cancels the ₦128,000
     * still owed and hands back only the ₦40,000 actually taken.
     */
    public function test_a_partial_sale_previews_the_refund_and_the_balance_reduction_separately(): void
    {
        $sale = $this->sale([[$this->filter, '4']], '40000.00');
        $item = $sale->items()->sole();

        $body = $this->actingAs($this->admin)->getJson(route('sales.returns.preview', $sale).'?'.http_build_query([
            'items' => [['sale_item_id' => $item->id, 'quantity' => '4']],
            'restock' => 1,
        ]))->assertOk()->json();

        $this->assertSame('Refund ₦40,000 · reduce balance ₦128,000 · restock 4 units', $body['sentence']);
        $this->assertSame('40000.00', $body['cash_refund']);
        $this->assertSame('128000.00', $body['balance_reduction']);
    }

    /** An unpaid Sale has no cash to give back, so no refund is claimed. */
    public function test_an_unpaid_sale_previews_only_a_balance_reduction(): void
    {
        $sale = $this->sale([[$this->filter, '2']], '0.00');
        $item = $sale->items()->sole();

        $body = $this->actingAs($this->admin)->getJson(route('sales.returns.preview', $sale).'?'.http_build_query([
            'items' => [['sale_item_id' => $item->id, 'quantity' => '2']],
            'restock' => 1,
        ]))->assertOk()->json();

        $this->assertSame('Reduce balance ₦84,000 · restock 2 units', $body['sentence']);
        $this->assertSame('0.00', $body['cash_refund']);
        // A zero effect is never named.
        $this->assertStringNotContainsString('Refund', $body['sentence']);
        $this->assertStringNotContainsString('₦0', $body['sentence']);
    }

    /** Goods kept off the shelf are counted, but not as a restock. */
    public function test_a_non_restock_return_is_described_as_not_restocked(): void
    {
        $sale = $this->sale([[$this->filter, '3']], '126000.00');
        $item = $sale->items()->sole();

        $query = fn (int $restock, string $quantity): array => $this->actingAs($this->admin)
            ->getJson(route('sales.returns.preview', $sale).'?'.http_build_query([
                'items' => [['sale_item_id' => $item->id, 'quantity' => $quantity]],
                'restock' => $restock,
            ]))->assertOk()->json();

        $one = $query(0, '1');
        $this->assertSame('Refund ₦42,000 · 1 unit not restocked', $one['sentence']);
        $this->assertSame('0', $one['restock_units']);
        $this->assertSame('1', $one['non_restock_units']);

        // Plural, and still not a restock.
        $two = $query(0, '2');
        $this->assertSame('Refund ₦84,000 · 2 units not restocked', $two['sentence']);
        $this->assertStringNotContainsString('restock 2', $two['sentence']);
    }

    /** The preview describes the whole pending selection, not one line at a time. */
    public function test_multiple_selected_lines_are_previewed_together(): void
    {
        // 2 × 42,000 + 4 × 2,400 = 93,600, paid in full so the whole return is refundable.
        $sale = $this->sale([[$this->filter, '2'], [$this->plug, '4']], '93600.00');
        $filterItem = $sale->items()->where('product_id', $this->filter->id)->sole();
        $plugItem = $sale->items()->where('product_id', $this->plug->id)->sole();

        $body = $this->actingAs($this->admin)->getJson(route('sales.returns.preview', $sale).'?'.http_build_query([
            'items' => [
                ['sale_item_id' => $filterItem->id, 'quantity' => '2'],
                ['sale_item_id' => $plugItem->id, 'quantity' => '1'],
            ],
            'restock' => 1,
        ]))->assertOk()->json();

        // 2 × 42,000 + 1 × 2,400 = 86,400 across three units.
        $this->assertSame('86400.00', $body['return_value']);
        $this->assertSame('3', $body['restock_units']);
        $this->assertStringContainsString('restock 3 units', $body['sentence']);
    }

    /** The preview prices from the Sale's own history, whatever the catalogue says now. */
    public function test_the_preview_uses_the_historical_unit_price(): void
    {
        $sale = $this->sale([[$this->filter, '2']], '84000.00');
        $item = $sale->items()->sole();

        $this->filter->update(['selling_price' => '99999.00']);

        $body = $this->actingAs($this->admin)->getJson(route('sales.returns.preview', $sale).'?'.http_build_query([
            'items' => [['sale_item_id' => $item->id, 'quantity' => '2']],
            'restock' => 1,
        ]))->assertOk()->json();

        $this->assertSame('84000.00', $body['return_value']);
    }

    /** A quantity beyond what is left is clamped, not priced. */
    public function test_the_preview_clamps_to_the_remaining_returnable_quantity(): void
    {
        $sale = $this->sale([[$this->filter, '2']], '84000.00');
        $item = $sale->items()->sole();

        $body = $this->actingAs($this->admin)->getJson(route('sales.returns.preview', $sale).'?'.http_build_query([
            'items' => [['sale_item_id' => $item->id, 'quantity' => '9']],
            'restock' => 1,
        ]))->assertOk()->json();

        // Only two were ever sold, so only two can be previewed.
        $this->assertSame('84000.00', $body['return_value']);
        $this->assertSame('2', $body['restock_units']);
    }

    /** An item from another Sale cannot be priced through this Sale's preview. */
    public function test_the_preview_ignores_items_that_are_not_on_this_sale(): void
    {
        $sale = $this->sale([[$this->filter, '1']], '42000.00');
        $other = $this->sale([[$this->plug, '2']], '0.00');
        $foreign = $other->items()->sole();

        $body = $this->actingAs($this->admin)->getJson(route('sales.returns.preview', $sale).'?'.http_build_query([
            'items' => [['sale_item_id' => $foreign->id, 'quantity' => '2']],
            'restock' => 1,
        ]))->assertOk()->json();

        $this->assertSame('0.00', $body['return_value']);
    }

    /** The preview is behind the same role check as the panel and the recorded return. */
    public function test_the_preview_is_closed_to_roles_that_may_not_record_returns(): void
    {
        $sale = $this->sale([[$this->filter, '1']], '42000.00');
        $item = $sale->items()->sole();
        $query = '?'.http_build_query(['items' => [['sale_item_id' => $item->id, 'quantity' => '1']], 'restock' => 1]);

        $this->actingAs($this->admin)->getJson(route('sales.returns.preview', $sale).$query)->assertOk();
        $this->actingAs($this->rep)->getJson(route('sales.returns.preview', $sale).$query)->assertForbidden();
    }

    /**
     * The preview and the recorded return are the same arithmetic, not two that happen to agree.
     *
     * This is the check that matters: whatever the sentence promised, the ledger must do.
     */
    public function test_the_preview_agrees_with_the_recorded_return(): void
    {
        $sale = $this->sale([[$this->filter, '4']], '40000.00');
        $item = $sale->items()->sole();

        $body = $this->actingAs($this->admin)->getJson(route('sales.returns.preview', $sale).'?'.http_build_query([
            'items' => [['sale_item_id' => $item->id, 'quantity' => '4']],
            'restock' => 1,
        ]))->assertOk()->json();

        $return = $this->recordReturn($sale, [
            ['sale_item_id' => $item->id, 'quantity' => '4', 'disposition' => 'restock'],
        ]);

        $this->assertSame($body['return_value'], $return->merchandise_value);
        $this->assertSame($body['balance_reduction'], $return->receivable_reduction);
        $this->assertSame($body['cash_refund'], $return->refundable_credit_created);
    }

    /** The panel carries the preview endpoint and no client-side money arithmetic. */
    public function test_the_summary_is_server_supplied(): void
    {
        $sale = $this->sale([[$this->filter, '1']], '42000.00');

        $html = $this->actingAs($this->admin)->get(route('sales.returns.panel', $sale))->assertOk()->getContent();
        $this->assertStringContainsString('data-preview-url', $html);
        $this->assertStringContainsString(e(route('sales.returns.preview', $sale)), $html);

        // The browser no longer multiplies prices or splits the settlement itself.
        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringNotContainsString('Number(line.unitPriceRaw)', $script);
        $this->assertStringNotContainsString('const credit = merchandise - reduction', $script);
    }

    /** @param  list<array<string, string>>  $items */
    private function recordReturn(Sale $sale, array $items, string $reason = 'Faulty'): SaleReturn
    {
        $token = app(IssueReturnRequest::class)->execute($this->admin, $sale, session()->driver());

        return app(RecordSaleReturn::class)->execute($this->admin, $sale, [
            'request_token' => $token,
            'reason' => $reason,
            'items' => $items,
        ], session()->getId());
    }
}
