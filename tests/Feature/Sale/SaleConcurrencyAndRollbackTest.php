<?php

namespace Tests\Feature\Sale;

use App\Actions\Sale\CreateSale;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SaleConcurrencyAndRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_uses_for_update_and_orders_product_locks_ascending(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $seller = User::factory()->create(['role' => UserRole::SalesRep]);
        $customer = Customer::factory()->create();
        $first = Product::factory()->create(['current_stock' => '5']);
        $second = Product::factory()->create(['current_stock' => '5']);
        app(CreateSale::class)->execute($seller, $this->payload($customer, [
            ['product_id' => $second->id, 'quantity' => '1'],
            ['product_id' => $first->id, 'quantity' => '1'],
        ]));

        $locks = collect($queries)->filter(fn (string $sql): bool => Str::contains(Str::lower($sql), 'for update'));
        $this->assertGreaterThanOrEqual(2, $locks->count());
        $productLock = $locks->first(fn (string $sql): bool => Str::contains($sql, 'from `products`'));
        $this->assertStringContainsString('order by `id` asc', Str::lower($productLock));
    }

    public function test_two_competing_sales_cannot_oversell_authoritative_stock(): void
    {
        $seller = User::factory()->create(['role' => UserRole::SalesRep]);
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['current_stock' => '5']);
        $payload = $this->payload($customer, [['product_id' => $product->id, 'quantity' => '4']]);
        $action = app(CreateSale::class);
        $action->execute($seller, $payload);

        try {
            $action->execute($seller, $payload);
            $this->fail('The second sale should not oversell.');
        } catch (ValidationException) {
            $this->assertSame('1.000', $product->fresh()->current_stock);
            $this->assertDatabaseCount('sales', 1);
            $this->assertDatabaseCount('sale_items', 1);
        }
    }

    public function test_audit_failure_rolls_back_sale_items_movements_and_stock(): void
    {
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));
        $seller = User::factory()->create(['role' => UserRole::SalesRep]);
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['current_stock' => '5']);

        try {
            (new CreateSale($audit))->execute($seller, $this->payload($customer, [['product_id' => $product->id, 'quantity' => '2']]));
            $this->fail('The sale should roll back.');
        } catch (RuntimeException) {
            $this->assertSame('5.000', $product->fresh()->current_stock);
            $this->assertDatabaseCount('sales', 0);
            $this->assertDatabaseCount('sale_items', 0);
            $this->assertDatabaseMissing('inventory_movements', ['type' => 'sale']);
        }
    }

    public function test_movement_failure_rolls_back_sale_items_stock_and_audit(): void
    {
        $dispatcher = InventoryMovement::getEventDispatcher();
        $event = 'eloquent.creating: '.InventoryMovement::class;
        $existingListeners = $dispatcher->getListeners($event);
        $dispatcher->listen($event, function (InventoryMovement $movement): void {
            if ($movement->type->value === 'sale') {
                throw new RuntimeException('ledger unavailable');
            }
        });
        $seller = User::factory()->create(['role' => UserRole::SalesRep]);
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['current_stock' => '5']);

        try {
            app(CreateSale::class)->execute($seller, $this->payload($customer, [['product_id' => $product->id, 'quantity' => '2']]));
            $this->fail('The sale should roll back.');
        } catch (RuntimeException) {
            $this->assertSame('5.000', $product->fresh()->current_stock);
            $this->assertDatabaseCount('sales', 0);
            $this->assertDatabaseCount('sale_items', 0);
            $this->assertDatabaseMissing('audit_logs', ['action' => 'sale_created']);
        } finally {
            $dispatcher->forget($event);
            foreach ($existingListeners as $listener) {
                $dispatcher->listen($event, $listener);
            }
        }
    }

    private function payload(Customer $customer, array $products): array
    {
        return ['customer_id' => $customer->id, 'products' => $products, 'payment_method' => 'cash', 'amount_paid' => '0', 'notes' => null];
    }
}
