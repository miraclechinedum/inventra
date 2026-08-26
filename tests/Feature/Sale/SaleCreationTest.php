<?php

namespace Tests\Feature\Sale;

use App\Actions\Sale\CreateSale;
use App\Enums\InventoryMovementType;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Support\SaleNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SaleCreationTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = User::factory()->create(['role' => UserRole::SalesRep]);
        $this->customer = Customer::factory()->create();
        $this->actingAs($this->seller);
    }

    public function test_one_product_sale_uses_authoritative_price_snapshots_payment_and_ledger(): void
    {
        $product = Product::factory()->create(['selling_price' => '1250.50', 'current_stock' => '10.000']);
        $this->post(route('sales.store'), $this->payload([
            'products' => [['product_id' => $product->id, 'quantity' => '2.000']],
            'amount_paid' => '2000.00',
        ]))->assertRedirect();

        $sale = Sale::query()->sole();
        $item = $sale->items()->sole();
        $movement = $product->movements()->where('type', InventoryMovementType::Sale)->sole();
        $this->assertSame(SaleNumber::fromId($sale->id), $sale->sale_number);
        $this->assertSame($this->customer->customer_code, $sale->customer_code_snapshot);
        $this->assertSame($this->customer->full_name, $sale->customer_name_snapshot);
        $this->assertSame($this->customer->phone, $sale->customer_phone_snapshot);
        $this->assertSame($this->seller->name, $sale->sold_by_name_snapshot);
        $this->assertSame('1250.50', $item->unit_price);
        $this->assertSame('2501.00', $item->line_total);
        $this->assertSame('2501.00', $sale->total_amount);
        $this->assertSame('501.00', $sale->balance_due);
        $this->assertSame(PaymentStatus::Partial, $sale->payment_status);
        $this->assertSame('8.000', $product->fresh()->current_stock);
        $this->assertSame('-2.000', $movement->quantity_change);
        $this->assertSame('10.000', $movement->quantity_before);
        $this->assertSame('8.000', $movement->quantity_after);
        $this->assertSame($sale->getMorphClass(), $movement->reference_type);
        $this->assertSame($sale->id, $movement->reference_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sale_created', 'auditable_id' => $sale->id, 'actor_id' => $this->seller->id]);
    }

    public function test_duplicate_lines_aggregate_and_decimal_money_rounds_half_up(): void
    {
        $product = Product::factory()->create(['selling_price' => '10.01', 'current_stock' => '10']);
        $this->post(route('sales.store'), $this->payload([
            'products' => [
                ['product_id' => $product->id, 'quantity' => '1.250'],
                ['product_id' => $product->id, 'quantity' => '0.250'],
            ],
            'amount_paid' => '15.02',
        ]))->assertRedirect();
        $sale = Sale::query()->sole();
        $this->assertCount(1, $sale->items);
        $this->assertSame('1.500', $sale->items->first()->quantity);
        $this->assertSame('15.02', $sale->total_amount);
        $this->assertSame(PaymentStatus::Paid, $sale->payment_status);
        $this->assertSame('8.500', $product->fresh()->current_stock);
    }

    public function test_payment_states_and_overpayment_are_server_authoritative(): void
    {
        $product = Product::factory()->create(['selling_price' => '100.00', 'current_stock' => '5']);
        $this->post(route('sales.store'), $this->payload(['products' => [['product_id' => $product->id, 'quantity' => '1']], 'amount_paid' => '0']))->assertRedirect();
        $unpaid = Sale::query()->firstOrFail();
        $this->assertSame(PaymentStatus::Unpaid, $unpaid->payment_status);
        $this->assertSame('100.00', $unpaid->total_amount);
        $this->assertSame('0.00', $unpaid->amount_paid);
        $this->assertSame('100.00', $unpaid->balance_due);

        $partialProduct = Product::factory()->create(['selling_price' => '100.00', 'current_stock' => '5']);
        $this->post(route('sales.store'), $this->payload(['products' => [['product_id' => $partialProduct->id, 'quantity' => '1']], 'amount_paid' => '50']))->assertRedirect();
        $partial = Sale::query()->latest('id')->firstOrFail();
        $this->assertSame(PaymentStatus::Partial, $partial->payment_status);
        $this->assertSame('50.00', $partial->balance_due);

        $paidProduct = Product::factory()->create(['selling_price' => '100.00', 'current_stock' => '5']);
        $this->post(route('sales.store'), $this->payload(['products' => [['product_id' => $paidProduct->id, 'quantity' => '1']], 'amount_paid' => '100']))->assertRedirect();
        $paid = Sale::query()->latest('id')->firstOrFail();
        $this->assertSame(PaymentStatus::Paid, $paid->payment_status);
        $this->assertSame('0.00', $paid->balance_due);

        $second = Product::factory()->create(['selling_price' => '100.00', 'current_stock' => '5']);
        $this->post(route('sales.store'), $this->payload(['products' => [['product_id' => $second->id, 'quantity' => '1']], 'amount_paid' => '100.01']))->assertSessionHasErrors('amount_paid');
        $this->assertSame('5.000', $second->fresh()->current_stock);
    }

    public function test_zero_total_sale_is_paid_with_zero_balance(): void
    {
        $product = Product::factory()->create(['selling_price' => '0.00', 'current_stock' => '1']);

        $this->post(route('sales.store'), $this->payload([
            'products' => [['product_id' => $product->id, 'quantity' => '1']],
            'amount_paid' => '0.00',
        ]))->assertRedirect();

        $sale = Sale::query()->sole();
        $this->assertSame('0.00', $sale->total_amount);
        $this->assertSame('0.00', $sale->amount_paid);
        $this->assertSame('0.00', $sale->balance_due);
        $this->assertSame(PaymentStatus::Paid, $sale->payment_status);
    }

    public function test_multi_product_failure_rolls_back_every_line(): void
    {
        $first = Product::factory()->create(['current_stock' => '10']);
        $second = Product::factory()->create(['current_stock' => '1']);
        $this->post(route('sales.store'), $this->payload(['products' => [
            ['product_id' => $first->id, 'quantity' => '2'],
            ['product_id' => $second->id, 'quantity' => '5'],
        ]]))->assertSessionHasErrors('products');
        $this->assertSame('10.000', $first->fresh()->current_stock);
        $this->assertSame('1.000', $second->fresh()->current_stock);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertDatabaseMissing('inventory_movements', ['type' => 'sale']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'sale_created']);
    }

    public function test_locked_customer_and_products_are_revalidated(): void
    {
        $action = app(CreateSale::class);
        $product = Product::factory()->create(['current_stock' => '5', 'is_active' => false]);
        $this->expectValidation(fn () => $action->execute($this->seller, $this->payload([
            'products' => [['product_id' => $product->id, 'quantity' => '1']],
        ])));

        $this->customer->is_active = false;
        $this->customer->save();
        $active = Product::factory()->create(['current_stock' => '5']);
        $this->expectValidation(fn () => $action->execute($this->seller, $this->payload([
            'products' => [['product_id' => $active->id, 'quantity' => '1']],
        ])));

        $archived = Product::factory()->create(['current_stock' => '5']);
        $archived->delete();
        $this->expectValidation(fn () => $action->execute($this->seller, $this->payload([
            'customer_id' => Customer::factory()->create()->id,
            'products' => [['product_id' => $archived->id, 'quantity' => '1']],
        ])));
    }

    public function test_authoritative_fields_and_malformed_payloads_are_rejected(): void
    {
        $product = Product::factory()->create();
        $payload = $this->payload(['products' => [[
            'product_id' => $product->id, 'quantity' => '1', 'unit_price' => '0.01', 'line_total' => '0.01',
            'product_name_snapshot' => 'Attacker', 'quantity_before' => '999', 'quantity_after' => '999',
        ]]]);
        $payload += ['sale_number' => 'HACK', 'subtotal' => '0', 'total_amount' => '0', 'status' => 'voided', 'payment_status' => 'paid', 'sold_by' => 999, 'sold_by_name_snapshot' => 'Attacker', 'customer_name_snapshot' => 'Attacker'];
        $this->post(route('sales.store'), $payload)->assertSessionHasErrors();
        $this->post(route('sales.store'), ['customer_id' => ['x'], 'products' => ['bad'], 'payment_method' => ['cash'], 'amount_paid' => ['0']])->assertSessionHasErrors();
        $this->assertDatabaseCount('sales', 0);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'products' => [],
            'payment_method' => 'cash',
            'amount_paid' => '0.00',
            'notes' => null,
        ], $overrides);
    }

    private function expectValidation(\Closure $callback): void
    {
        try {
            $callback();
            $this->fail('Expected controlled validation failure.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('sales', 0);
        }
    }
}
