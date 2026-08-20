<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\AdjustStock;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class InventorySalesReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_constraints_reject_negative_stock_and_reorder_levels(): void
    {
        $product = Product::factory()->create();

        foreach (['current_stock', 'reorder_level'] as $column) {
            $before = $product->fresh()->{$column};

            try {
                DB::table('products')->where('id', $product->id)->update([$column => '-0.001']);
                $this->fail("The {$column} constraint should reject a negative value.");
            } catch (QueryException) {
                $this->assertSame($before, $product->fresh()->{$column});
            }
        }
    }

    public function test_locked_inactive_product_is_rejected_by_action_without_side_effects(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $product = Product::factory()->create(['is_active' => false, 'current_stock' => '5']);

        try {
            app(AdjustStock::class)->execute($manager, $product, [
                'type' => 'restock', 'operation' => 'increase', 'quantity' => '2',
            ]);
            $this->fail('Inactive stock adjustment should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('product', $exception->errors());
        }

        $this->assertSame('5.000', $product->fresh()->current_stock);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'stock_adjusted', 'auditable_id' => $product->id]);
    }

    public function test_movement_and_audit_models_reject_updates_and_deletes(): void
    {
        $product = Product::factory()->create();
        $movement = $this->movement($product);
        $audit = $this->audit($product);

        foreach ([
            fn () => tap($movement, fn ($record) => $record->reason = 'Changed')->save(),
            fn () => $movement->delete(),
            fn () => tap($audit, fn ($record) => $record->action = 'changed')->save(),
            fn () => $audit->delete(),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Append-only record mutation should fail.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('append-only', $exception->getMessage());
            }
        }
    }

    public function test_money_formatter_preserves_decimal_strings_without_floats(): void
    {
        $this->assertSame('0.10', Money::format('0.10'));
        $this->assertSame('1.00', Money::format('1.00'));
        $this->assertSame('1,000.50', Money::format('1000.50'));
        $this->assertSame('9,999,999,999,999.99', Money::format('9999999999999.99'));
    }

    public function test_archived_sku_cannot_be_reused_and_route_binding_returns_not_found(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $category = ProductCategory::factory()->create();
        $product = Product::factory()->create(['category_id' => $category, 'sku' => 'PERMANENT-1']);
        $this->actingAs($admin)->delete(route('inventory.products.destroy', $product))->assertRedirect();

        $this->get(route('inventory.products.show', $product))->assertNotFound();
        $this->post(route('inventory.products.store'), $this->payload($category, ['sku' => 'PERMANENT-1']))
            ->assertSessionHasErrors('sku');
    }

    public function test_decimal_boundaries_reconcile_and_product_audit_is_allowlisted(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $category = ProductCategory::factory()->create();
        $this->actingAs($admin)->post(route('inventory.products.store'), $this->payload($category, [
            'cost_price' => '9999999999999.99',
            'selling_price' => '9999999999999.99',
            'initial_stock' => '999999999999.999',
            'reorder_level' => '999999999999.999',
        ]))->assertRedirect();

        $product = Product::query()->where('sku', 'BOUNDARY-1')->firstOrFail();
        $this->assertSame('9999999999999.99', $product->selling_price);
        $this->assertSame($product->current_stock, $product->movements()->sum('quantity_change'));
        $audit = AuditLog::query()->where('action', 'product_created')->sole();
        $this->assertSame('999999999999.999', $audit->new_values['current_stock']);

        app(AuditLogger::class)->record('product_updated', $product, $admin,
            newValues: ['password' => 'secret', 'current_stock' => new \stdClass],
            metadata: ['token' => 'secret', 'sku' => new \stdClass]);
        $sanitized = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertNull($sanitized->new_values);
        $this->assertNull($sanitized->metadata);
    }

    public function test_required_inventory_extensions_are_declared_and_loaded(): void
    {
        $requirements = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR)['require'];

        foreach (['bcmath', 'mbstring', 'pdo_mysql'] as $extension) {
            $this->assertTrue(extension_loaded($extension));
            $this->assertSame('*', $requirements['ext-'.$extension]);
        }
    }

    private function movement(Product $product): InventoryMovement
    {
        $movement = new InventoryMovement;
        $movement->product_id = $product->id;
        $movement->type = InventoryMovementType::Initial;
        $movement->quantity_change = '0';
        $movement->quantity_before = '0';
        $movement->quantity_after = '0';
        $movement->save();

        return $movement;
    }

    private function audit(Product $product): AuditLog
    {
        $audit = new AuditLog;
        $audit->action = 'test';
        $audit->auditable_type = $product->getMorphClass();
        $audit->auditable_id = $product->id;
        $audit->save();

        return $audit;
    }

    private function payload(ProductCategory $category, array $overrides = []): array
    {
        return array_merge([
            'category_id' => $category->id,
            'name' => 'Boundary Product',
            'sku' => 'BOUNDARY-1',
            'cost_price' => '1.00',
            'selling_price' => '2.00',
            'initial_stock' => '0',
            'reorder_level' => '0',
            'unit' => 'piece',
        ], $overrides);
    }
}
