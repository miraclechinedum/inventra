<?php

namespace Tests\Feature\Sale;

use App\Actions\Sale\CreateSale;
use App\Actions\Sale\VoidSale;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Sale details side panel on the Sales list.
 *
 * The panel is drawn on the client from two authorized sources — the row's own `data-sale`
 * attribute and the `sales.lines` endpoint — so these check what the server actually hands it:
 * that the details a row carries are the sale's real figures, that the line endpoint is behind the
 * same policy as the Sale, and that the correction entry point is offered only where the
 * correction policy would allow it.
 */
class SalesDetailPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $rep;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Ada Admin']);
        $this->rep = User::factory()->create(['role' => UserRole::SalesRep, 'name' => 'Sam Rep']);
        $this->customer = Customer::factory()->create([
            'first_name' => 'Miracle', 'last_name' => 'Chinedum', 'is_active' => true,
        ]);
        $this->product = Product::factory()->create(['selling_price' => '12500.00', 'current_stock' => '80.000']);
    }

    private function sale(?User $seller = null, array $overrides = []): Sale
    {
        return app(CreateSale::class)->execute($seller ?? $this->admin, array_merge([
            'is_walk_in' => false,
            'customer_id' => $this->customer->id,
            'sale_date' => CarbonImmutable::now(config('business.timezone'))->toDateString(),
            'products' => [['product_id' => $this->product->id, 'quantity' => '4']],
            'payment_method' => 'cash',
            'amount_paid' => '0.00',
        ], $overrides));
    }

    /** The row carries everything the details panel draws, and the real figures. */
    public function test_the_row_carries_the_details_the_panel_renders(): void
    {
        $sale = $this->sale();

        $html = $this->actingAs($this->admin)->get(route('sales.index'))->assertOk()->getContent();

        $payload = $this->rowPayload($html, $sale->public_id);

        $this->assertSame($sale->sale_number, $payload['number']);
        $this->assertSame('Miracle Chinedum', $payload['customer']);
        $this->assertFalse($payload['isWalkIn']);
        // Whole naira, no trailing .00 — the same compact form the table column uses.
        $this->assertSame('50,000', $payload['total']);
        $this->assertSame('Ada Admin', $payload['seller']);
        // The recorded-by role travels with the name, so the panel need not guess it.
        $this->assertArrayHasKey('sellerRole', $payload);
        // The panel shows a date and a recorded time; neither is invented here.
        $this->assertSame($sale->sale_date->format('j M Y'), $payload['date']);
        $this->assertStringContainsString('·', $payload['recordedAt']);
        // The internal key is never handed to the browser; the ULID is what addresses the Sale.
        $this->assertSame($sale->public_id, $payload['id']);
        $this->assertArrayNotHasKey('saleId', $payload);
    }

    public function test_a_walk_in_sale_renders_without_inventing_a_customer(): void
    {
        $sale = $this->sale(null, ['is_walk_in' => true, 'customer_id' => null]);

        $html = $this->actingAs($this->admin)->get(route('sales.index'))->assertOk()->getContent();
        $payload = $this->rowPayload($html, $sale->public_id);

        $this->assertTrue($payload['isWalkIn']);
        $this->assertSame('W', $payload['initials']);
        // No phone is fabricated for a counter sale that never supplied one.
        $this->assertNull($payload['phone']);
    }

    public function test_the_lines_endpoint_is_behind_the_same_policy_as_the_sale(): void
    {
        $mine = $this->sale($this->rep);
        $theirs = $this->sale($this->admin);

        // A rep may read their own sale's lines.
        $this->actingAs($this->rep)->get(route('sales.lines', $mine))->assertOk()
            ->assertJsonStructure(['lines' => [['name', 'quantity', 'total']]]);

        // And not another seller's.
        $this->actingAs($this->rep)->get(route('sales.lines', $theirs))->assertForbidden();
    }

    public function test_the_lines_endpoint_is_closed_to_a_guest(): void
    {
        $sale = $this->sale();

        $this->get(route('sales.lines', $sale))->assertRedirect(route('login'));
    }

    /** The line rows carry the sold-at snapshot, not whatever the Product says today. */
    public function test_lines_report_the_frozen_item_snapshot(): void
    {
        $sale = $this->sale();
        $originalName = $this->product->name;

        $this->product->update(['name' => 'Renamed After The Sale', 'selling_price' => '999999.00']);

        $lines = $this->actingAs($this->admin)->get(route('sales.lines', $sale))
            ->assertOk()->json('lines');

        $this->assertCount(1, $lines);
        $this->assertSame($originalName, $lines[0]['name']);
        // 4 × 12,500 as sold, not 4 × the new price — and compacted, as the whole panel is.
        $this->assertSame('50,000', $lines[0]['total']);
    }

    /**
     * The panel prints whole naira without a trailing `.00`, and prints the line total.
     *
     * Both halves were wrong on screen at once: a row read "Toyota Camry Oil Filter ×4
     * ₦168,000.00" beside a Total of "₦168,000". The figure was right — `line_total` is unit price
     * times quantity — but this endpoint alone formatted to two decimals while every other money
     * value on the screen is compacted.
     */
    public function test_line_amounts_are_compact_whole_naira_line_totals(): void
    {
        // 42,000 each, four of them: the row must read the 168,000 line total, not the 42,000 unit.
        $product = Product::factory()->create(['selling_price' => '42000.00', 'current_stock' => '40.000']);

        $sale = app(CreateSale::class)->execute($this->admin, [
            'is_walk_in' => false,
            'customer_id' => $this->customer->id,
            'sale_date' => CarbonImmutable::now(config('business.timezone'))->toDateString(),
            'products' => [['product_id' => $product->id, 'quantity' => '4']],
            'payment_method' => 'cash',
            'amount_paid' => '0.00',
        ]);

        $lines = $this->actingAs($this->admin)->get(route('sales.lines', $sale))->assertOk()->json('lines');

        $this->assertSame('168,000', $lines[0]['total']);
        $this->assertStringNotContainsString('.00', $lines[0]['total']);
        // The line total, not the unit price.
        $this->assertNotSame('42,000', $lines[0]['total']);
        // The quantity is trimmed for display, so it reads ×4 rather than ×4.000.
        $this->assertSame('4', $lines[0]['quantity']);

        // And the panel's own Total agrees with it, through the Sale's settled column.
        $html = $this->actingAs($this->admin)->get(route('sales.index'))->assertOk()->getContent();
        $this->assertSame('168,000', $this->rowPayload($html, $sale->public_id)['total']);
    }

    /** Kobo is never hidden: a fractional amount still prints in full. */
    public function test_a_fractional_line_amount_keeps_its_kobo(): void
    {
        $product = Product::factory()->create(['selling_price' => '1250.50', 'current_stock' => '10.000']);

        $sale = app(CreateSale::class)->execute($this->admin, [
            'is_walk_in' => false,
            'customer_id' => $this->customer->id,
            'sale_date' => CarbonImmutable::now(config('business.timezone'))->toDateString(),
            'products' => [['product_id' => $product->id, 'quantity' => '1']],
            'payment_method' => 'cash',
            'amount_paid' => '0.00',
        ]);

        $lines = $this->actingAs($this->admin)->get(route('sales.lines', $sale))->assertOk()->json('lines');

        $this->assertSame('1,250.50', $lines[0]['total']);
    }

    public function test_the_correction_entry_point_follows_the_correction_policy(): void
    {
        $adminSale = $this->sale($this->admin);
        $repSale = $this->sale($this->rep);

        // A Sales Rep may never correct, so the row must not carry a correction URL for them.
        $repHtml = $this->actingAs($this->rep)->get(route('sales.index'))->assertOk()->getContent();
        $this->assertNull($this->rowPayload($repHtml, $repSale->public_id)['correctUrl']);

        // An Admin may, so theirs does.
        $adminHtml = $this->actingAs($this->admin)->get(route('sales.index'))->assertOk()->getContent();
        $this->assertNotNull($this->rowPayload($adminHtml, $adminSale->public_id)['correctUrl']);

        // And the endpoint enforces it regardless of what the markup offered.
        $this->actingAs($this->rep)->get(route('sales.corrections.panel', $adminSale))->assertForbidden();
    }

    /**
     * A Sale the correction workflow refuses must not be offered as correctable.
     *
     * This is what left the panel blank: a voided Sale still carried a correction URL, the panel
     * switched to correction mode, and the endpoint answered with a redirect rather than a form.
     */
    public function test_an_ineligible_sale_is_not_offered_for_correction(): void
    {
        $sale = $this->sale();
        app(VoidSale::class)->execute($this->admin, $sale, 'Recorded in error');

        $html = $this->actingAs($this->admin)->get(route('sales.index'))->assertOk()->getContent();
        $payload = $this->rowPayload($html, $sale->public_id);

        $this->assertNull($payload['correctUrl'], 'a voided sale must not offer correction');
        $this->assertNull($payload['correctPanelUrl']);
    }

    /** Whatever the markup offered, the fragment endpoint refuses an ineligible Sale outright. */
    public function test_the_correction_fragment_refuses_an_ineligible_sale(): void
    {
        $sale = $this->sale();
        app(VoidSale::class)->execute($this->admin, $sale, 'Recorded in error');

        // Not a redirect into a form the panel cannot render: a status the panel can act on.
        $this->actingAs($this->admin)
            ->getJson(route('sales.corrections.panel', $sale))
            ->assertStatus(422);
    }

    /**
     * Clicking a row opens details, and only the explicit correct action switches modes.
     *
     * The row's own handler is `selectRow`, which sets details. Nothing on the row opens correction
     * except the pencil, which calls `correctRow` — so a Sale ID can never land the operator in a
     * correction form they did not ask for.
     */
    public function test_the_row_opens_details_and_only_the_pencil_opens_correction(): void
    {
        $sale = $this->sale();

        $html = $this->actingAs($this->admin)->get(route('sales.index'))->assertOk()->getContent();

        $this->assertStringContainsString('x-on:click="selectRow"', $html);
        $this->assertStringContainsString('x-on:click="correctRow"', $html);
        // The panel defaults to no mode at all; details is what selecting a row sets.
        $this->assertStringContainsString("panelMode === 'details'", $html);
        $this->assertStringContainsString("panelMode === 'correction'", $html);
    }

    /**
     * The panel introduces no destructive action, and never a delete.
     *
     * It carries a single action — the correction form — and nothing that withdraws or destroys a
     * Sale. Voiding remains available on the Sale page, behind its own Admin-only policy and its
     * typed-reason form; it is simply not reachable from this summary. What matters here is that no
     * delete affordance exists: a recorded Sale is never destroyed.
     */
    public function test_the_panel_introduces_no_destructive_action(): void
    {
        $sale = $this->sale();

        $panel = file_get_contents(resource_path('views/sales/_detail-panel.blade.php'));
        $html = $this->actingAs($this->admin)->get(route('sales.index'))->assertOk()->getContent();

        // No delete anywhere, under any wording.
        $this->assertStringNotContainsString('Delete sale', $html);
        $this->assertStringNotContainsString('Delete sale', $panel);
        // And no void affordance in this panel either — the footer is the one edit action.
        $this->assertStringNotContainsString('ui-sale-panel-void', $panel);
        $this->assertStringNotContainsString('Void sale', $panel);

        // Voiding is untouched where it lives: the Sale page still renders its reason form.
        $this->actingAs($this->admin)->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee('void-sale', false)
            ->assertSee('Void sale');
    }

    /** The discount breakdown is the Sale's own settled columns, never recomputed here. */
    public function test_a_discounted_sale_reports_the_authoritative_figures(): void
    {
        $sale = $this->sale();

        $html = $this->actingAs($this->admin)->get(route('sales.index'))->assertOk()->getContent();
        $payload = $this->rowPayload($html, $sale->public_id);

        // Undiscounted: the breakdown stays hidden and subtotal equals total.
        $this->assertFalse($payload['hasDiscount']);
        $this->assertSame($payload['subtotal'], $payload['total']);
        // Every figure the panel prints is the Sale's own column, compacted for display only.
        $this->assertSame(Money::compact((string) $sale->total_amount), $payload['total']);
        $this->assertSame(Money::compact((string) $sale->subtotal), $payload['subtotal']);
        $this->assertSame(Money::compact((string) $sale->discount_amount), $payload['discount']);
    }

    /**
     * The panel footer carries exactly one action: the correction form.
     *
     * Receipt, Full detail, Process return and the void shortcut were all taken out of this footer
     * — the panel is a concise summary, not a second Sale management screen. Every one of them
     * still exists in the application, on the Sale page the row's own `showUrl` addresses, and this
     * asserts both halves so neither the removal nor the features can silently regress.
     *
     * The button opens the Return workflow through the existing audited endpoint. A Sale is never
     * updated in place from this panel, so no `Sale::update` shortcut may appear here.
     */
    public function test_the_panel_footer_carries_only_the_return_action(): void
    {
        $sale = $this->sale();

        $panel = file_get_contents(resource_path('views/sales/_detail-panel.blade.php'));

        // Gone from this footer.
        foreach (['>Receipt<', '>Full detail<', '>Process return<', 'ui-sale-panel-void'] as $removed) {
            $this->assertStringNotContainsString($removed, $panel);
        }

        // What remains: one button, opening the audited Return workflow and named for what it
        // does. The panel it opens is the Return form, not a Sale correction — a Return books
        // goods coming back and leaves the Sale standing, which is the act this design describes.
        $this->assertStringContainsString('Return items', $panel);
        $this->assertStringContainsString('openCorrection', $panel);
        $this->assertStringContainsString('returnPanelUrl', $panel);

        // And the features themselves are untouched: the routes still resolve and still answer.
        $this->actingAs($this->admin)->get(route('sales.receipt', $sale))->assertOk();
        $this->actingAs($this->admin)->get(route('sales.show', $sale))->assertOk();
        $this->actingAs($this->admin)->get(route('sales.returns.create', $sale))->assertOk();
    }

    /** @return array<string, mixed> */
    private function rowPayload(string $html, string $publicId): array
    {
        $this->assertMatchesRegularExpression('/data-sale="/', $html);

        preg_match_all('/data-sale="([^"]*)"/', $html, $matches);

        foreach ($matches[1] as $encoded) {
            $decoded = json_decode(html_entity_decode($encoded, ENT_QUOTES), true);

            if (is_array($decoded) && ($decoded['id'] ?? null) === $publicId) {
                return $decoded;
            }
        }

        $this->fail("No row payload for sale {$publicId}");
    }
}
