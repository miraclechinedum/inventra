<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\AdjustStock;
use App\Actions\Purchase\ReceivePurchase;
use App\Actions\Sale\CorrectSale;
use App\Actions\Sale\CreateSale;
use App\Actions\Sale\IssueReturnRequest;
use App\Actions\Sale\RecordSaleReturn;
use App\Actions\Supplier\CreateSupplier;
use App\Enums\ProductUnit;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseRequest;
use App\Models\Sale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SubmitsSales;
use Tests\TestCase;

/**
 * Quantities follow the unit they are counted in.
 *
 * Litres are measured, to three decimal places; every other unit is counted in whole numbers, in
 * sales, pre-sale discount requests, corrections, returns, purchases, stock adjustments and opening
 * stock. Returns follow the unit the goods were sold in, and records made before the rule are never
 * refused for being what they already are.
 */
class UnitQuantityRulesTest extends TestCase
{
    use RefreshDatabase, SubmitsSales;

    private User $admin;

    private Customer $customer;

    private Product $filter;

    private Product $oil;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'inventra_test',
            DB::connection()->getDatabaseName(),
            'Refusing to run: these tests write, and this is not the test database.'
        );

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->customer = Customer::factory()->create(['is_active' => true]);
        $this->filter = Product::factory()->create(['name' => 'Oil Filter', 'unit' => ProductUnit::Piece, 'selling_price' => '1000.00', 'current_stock' => '20.000']);
        $this->oil = Product::factory()->create(['name' => 'Engine Oil', 'unit' => ProductUnit::Litre, 'selling_price' => '333.33', 'current_stock' => '10.000']);
    }

    private function sale(array $products, string $paid = '0.00'): Sale
    {
        return app(CreateSale::class)->execute($this->admin, [
            'is_walk_in' => false, 'customer_id' => $this->customer->id,
            'sale_date' => CarbonImmutable::now(config('business.timezone'))->toDateString(),
            'products' => $products, 'payment_method' => 'cash', 'amount_paid' => $paid,
        ]);
    }

    private function refused(callable $attempt, string $field, string $message): void
    {
        try {
            $attempt();
            $this->fail('The quantity should have been refused.');
        } catch (ValidationException $exception) {
            $this->assertSame([$message], $exception->errors()[$field]);
        }
    }

    public function test_the_unit_decides_whether_a_quantity_may_be_fractional(): void
    {
        $this->assertTrue(ProductUnit::Litre->accepts('0.125'));
        foreach ([ProductUnit::Piece, ProductUnit::Pair, ProductUnit::Set, ProductUnit::Pack, ProductUnit::Box] as $unit) {
            $this->assertTrue($unit->accepts('3.000'));
            $this->assertFalse($unit->accepts('2.500'), "{$unit->value} is counted");
            $this->assertSame('1', $unit->step());
        }
        $this->assertSame('0.001', ProductUnit::Litre->step());
    }

    public function test_a_sale_takes_whole_counted_units_and_fractional_litres(): void
    {
        $this->refused(fn () => $this->sale([['product_id' => $this->filter->id, 'quantity' => '1.5']]),
            'products', 'Oil Filter is counted in whole units (piece). Enter a whole number.');

        // Two lines of one product are judged on their total, as they are sold.
        $this->refused(fn () => $this->sale([['product_id' => $this->filter->id, 'quantity' => '0.5'], ['product_id' => $this->filter->id, 'quantity' => '1']]),
            'products', 'Oil Filter is counted in whole units (piece). Enter a whole number.');
        $this->sale([['product_id' => $this->filter->id, 'quantity' => '0.5'], ['product_id' => $this->filter->id, 'quantity' => '0.5']]);
        $this->assertSame('19.000', $this->filter->fresh()->current_stock);

        // Half a litre at ₦333.33 is ₦166.665, rounded half-up per line.
        $sale = $this->sale([['product_id' => $this->oil->id, 'quantity' => '0.5']]);
        $this->assertSame('166.67', $sale->items()->sole()->line_total);
        $this->assertSame('9.500', $this->oil->fresh()->current_stock);
        $this->assertSame(2, Sale::query()->count());
    }

    public function test_the_record_sale_screen_offers_the_units_step(): void
    {
        $page = $this->actingAs($this->admin)->get(route('sales.create'))->assertOk();
        $products = collect($page->viewData('products'))->keyBy('id');

        $this->assertFalse($products[$this->filter->id]['fractional']);
        $this->assertTrue($products[$this->oil->id]['fractional']);
        $page->assertSee('x-bind:step="quantityStep(line)"', false);

        $this->actingAs($this->admin)->post(route('sales.store'), $this->withSaleToken([
            'is_walk_in' => '0', 'customer_id' => $this->customer->id,
            'sale_date' => CarbonImmutable::now(config('business.timezone'))->toDateString(),
            'products' => [['product_id' => $this->filter->id, 'quantity' => '2.5']], 'amount_paid' => '0.00',
        ]))->assertSessionHasErrors(['products' => 'Oil Filter is counted in whole units (piece). Enter a whole number.']);
    }

    public function test_a_pre_sale_discount_request_follows_the_same_rule(): void
    {
        $this->actingAs($this->admin)->postJson(route('sales.discounts.draft.store'), [
            'amount' => '100', 'reason' => 'Bulk order on a counted part', 'is_walk_in' => false,
            'customer_id' => $this->customer->id, 'sale_date' => CarbonImmutable::now(config('business.timezone'))->toDateString(),
            'products' => [['product_id' => $this->filter->id, 'quantity' => '1.25']],
        ])->assertStatus(422)->assertJsonValidationErrors('products');
    }

    public function test_a_correction_changes_counted_lines_by_whole_units_and_keeps_legacy_lines(): void
    {
        $sale = $this->sale([['product_id' => $this->filter->id, 'quantity' => '3']]);

        $this->refused(fn () => app(CorrectSale::class)->execute($this->admin, $sale->fresh(), [
            'reason' => 'Recorded the wrong count at the counter.', 'products' => [['product_id' => $this->filter->id, 'quantity' => '2.5']],
        ]), 'products', 'Oil Filter is counted in whole units (piece). Enter a whole number.');

        // A line recorded fractionally before the rule is not refused for staying as it was.
        $legacy = $this->sale([['product_id' => $this->filter->id, 'quantity' => '2'], ['product_id' => $this->oil->id, 'quantity' => '1']]);
        DB::table('sale_items')->where('sale_id', $legacy->id)->where('product_id', $this->filter->id)->update(['quantity' => '1.500']);
        app(CorrectSale::class)->execute($this->admin, $legacy->fresh(), [
            'reason' => 'Correcting only the oil line.',
            'products' => [['product_id' => $this->filter->id, 'quantity' => '1.5'], ['product_id' => $this->oil->id, 'quantity' => '0.75']],
        ]);
        $this->assertSame('0.750', $legacy->fresh()->items()->where('product_id', $this->oil->id)->sole()->quantity);
    }

    public function test_a_return_follows_the_unit_the_goods_were_sold_in(): void
    {
        $counted = $this->sale([['product_id' => $this->filter->id, 'quantity' => '3']]);
        $this->refused(fn () => $this->return($counted, '0.5'), 'items', 'Oil Filter is counted in whole units (piece). Enter a whole number.');

        $measured = $this->sale([['product_id' => $this->oil->id, 'quantity' => '0.5']]);
        $this->return($measured, '0.25');
        $this->return($measured, '0.25');
        $this->assertSame('10.000', $this->oil->fresh()->current_stock);

        // A legacy fractional remainder may be returned exactly; nothing less than whole units before it.
        $legacy = $this->sale([['product_id' => $this->filter->id, 'quantity' => '2']]);
        DB::table('sale_items')->where('sale_id', $legacy->id)->update(['quantity' => '1.500', 'line_total' => '1500.00']);
        DB::table('sales')->where('id', $legacy->id)->update(['subtotal' => '1500.00', 'total_amount' => '1500.00', 'balance_due' => '1500.00']);
        $this->refused(fn () => $this->return($legacy, '0.5'), 'items', 'Oil Filter is counted in whole units (piece). Enter a whole number.');
        $this->return($legacy, '1');
        $this->return($legacy, '0.5');
        $this->assertSame('1.500', (string) DB::table('sale_return_items')->where('sale_item_id', $legacy->items()->sole()->id)->sum('quantity_returned'));
    }

    public function test_the_return_panel_offers_a_typed_amount_only_for_measured_goods(): void
    {
        $sale = $this->sale([['product_id' => $this->filter->id, 'quantity' => '2'], ['product_id' => $this->oil->id, 'quantity' => '1.5']]);

        $panel = $this->actingAs($this->admin)->get(route('sales.returns.panel', $sale))->assertOk();
        $lines = collect($panel->viewData('lines'))->keyBy('name');

        $this->assertFalse($lines['Oil Filter']['fractional']);
        $this->assertTrue($lines['Engine Oil']['fractional']);
        $panel->assertSee('class="ui-return-quantity"', false)->assertSee('max="1.500"', false);
    }

    public function test_purchases_receive_whole_counted_units_and_fractional_litres(): void
    {
        $supplier = app(CreateSupplier::class)->execute($this->admin, ['name' => 'Delta Parts']);

        $this->refused(fn () => $this->receive($supplier, $this->filter, '4.5'), 'items', 'Oil Filter is counted in whole units (piece). Enter a whole number.');
        $this->receive($supplier, $this->oil, '12.25');

        $this->assertSame('20.000', $this->filter->fresh()->current_stock);
        $this->assertSame('22.250', $this->oil->fresh()->current_stock);
    }

    public function test_stock_adjustments_move_counted_units_whole_and_may_clear_a_legacy_fraction(): void
    {
        $adjust = fn (Product $product, string $type, string $operation, string $quantity) => app(AdjustStock::class)->execute(
            $this->admin, $product->fresh(), ['type' => $type, 'operation' => $operation, 'quantity' => $quantity, 'reason' => 'Counted'],
        );
        $message = 'Oil Filter is counted in whole units (piece). Enter a whole number.';

        $this->refused(fn () => $adjust($this->filter, 'restock', 'increase', '0.5'), 'quantity', $message);
        $this->refused(fn () => $adjust($this->filter, 'correction', 'set', '19.5'), 'quantity', $message);
        $adjust($this->oil, 'adjustment', 'increase', '0.25');
        $this->assertSame('10.250', $this->oil->fresh()->current_stock);

        // A balance recorded fractionally before the rule can be restocked by whole units, and
        // brought back to a whole number by the exact fraction, but never given a new fraction.
        DB::table('products')->where('id', $this->filter->id)->update(['current_stock' => '2.500']);
        $adjust($this->filter, 'restock', 'increase', '1');
        $this->assertSame('3.500', $this->filter->fresh()->current_stock);
        $adjust($this->filter, 'adjustment', 'decrease', '0.5');
        $this->assertSame('3.000', $this->filter->fresh()->current_stock);
        $this->refused(fn () => $adjust($this->filter, 'adjustment', 'decrease', '0.5'), 'quantity', $message);
    }

    public function test_the_purchase_and_adjustment_forms_offer_the_units_step(): void
    {
        $this->actingAs($this->admin)->get(route('purchases.create'))->assertOk()
            ->assertSee('value="'.$this->filter->id.'" data-quantity-step="1"', false)
            ->assertSee('value="'.$this->oil->id.'" data-quantity-step="0.001"', false)
            ->assertSee('data-purchase-quantity', false);

        $this->actingAs($this->admin)->get(route('inventory.products.show', $this->filter))->assertOk()
            ->assertSee('name="quantity" type="number" min="0" step="1"', false)
            ->assertSee('Counted in whole units (piece).');
        $this->actingAs($this->admin)->get(route('inventory.products.show', $this->oil))->assertOk()
            ->assertSee('name="quantity" type="number" min="0" step="0.001"', false);

        // A counted product still holding a fraction from before the rule may be corrected by one.
        DB::table('products')->where('id', $this->filter->id)->update(['current_stock' => '2.500']);
        $this->actingAs($this->admin)->get(route('inventory.products.show', $this->filter))->assertOk()
            ->assertSee('name="quantity" type="number" min="0" step="0.001"', false);
    }

    public function test_opening_stock_follows_the_unit(): void
    {
        $category = ProductCategory::factory()->create(['created_by' => $this->admin, 'is_active' => true]);
        $payload = fn (string $unit, string $stock, string $sku): array => [
            'category_id' => $category->id, 'name' => 'New '.$sku, 'sku' => $sku, 'selling_price' => '100.00',
            'cost_price' => '50.00', 'initial_stock' => $stock, 'reorder_level' => '1', 'unit' => $unit,
        ];

        $this->actingAs($this->admin)->post(route('inventory.products.store'), $payload('box', '2.5', 'BOX-1'))
            ->assertSessionHasErrors(['initial_stock' => 'Opening stock is counted in whole units (box). Enter a whole number.']);
        $this->actingAs($this->admin)->post(route('inventory.products.store'), $payload('litre', '2.5', 'OIL-1'))
            ->assertSessionHasNoErrors();

        $this->assertFalse(Product::query()->where('sku', 'BOX-1')->exists());
        $this->assertSame('2.500', Product::query()->where('sku', 'OIL-1')->sole()->current_stock);
    }

    private function return(Sale $sale, string $quantity): void
    {
        $item = $sale->items()->sole();
        $token = app(IssueReturnRequest::class)->execute($this->admin, $sale->fresh(), session()->driver());

        app(RecordSaleReturn::class)->execute($this->admin, $sale->fresh(), [
            'request_token' => $token, 'reason' => 'Returned',
            'items' => [['sale_item_id' => $item->id, 'quantity' => $quantity, 'disposition' => 'restock']],
        ], session()->getId());
    }

    private function receive($supplier, Product $product, string $quantity): void
    {
        $token = Str::random(64);
        $request = new PurchaseRequest;
        $request->token_hash = hash('sha256', $token);
        $request->actor_id = $this->admin->id;
        $request->session_id = 'unit-session';
        $request->expires_at = now()->addMinutes(30);
        $request->save();

        app(ReceivePurchase::class)->execute($this->admin, [
            'request_token' => $token, 'supplier_id' => $supplier->id,
            'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => '10.00']],
        ], 'unit-session');
    }
}
