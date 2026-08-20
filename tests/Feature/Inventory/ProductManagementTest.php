<?php

namespace Tests\Feature\Inventory;

use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ProductCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->category = ProductCategory::factory()->create(['created_by' => $this->admin]);
        $this->actingAs($this->admin);
    }

    public function test_product_creation_with_initial_stock_is_atomic_and_canonical(): void
    {
        $this->post(route('inventory.products.store'), $this->payload([
            'sku' => ' inv-001 ',
            'initial_stock' => '12.500',
            'cost_price' => '1250.5',
        ]))->assertRedirect();

        $product = Product::query()->where('sku', 'INV-001')->firstOrFail();
        $movement = $product->movements()->firstOrFail();
        $this->assertSame('1250.50', $product->cost_price);
        $this->assertSame('12.500', $product->current_stock);
        $this->assertSame(InventoryMovementType::Initial, $movement->type);
        $this->assertSame('0.000', $movement->quantity_before);
        $this->assertSame('12.500', $movement->quantity_after);
        $this->assertSame($this->admin->id, $movement->performed_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product_created', 'actor_id' => $this->admin->id, 'auditable_id' => $product->id]);
    }

    public function test_zero_initial_stock_creates_a_self_describing_opening_movement(): void
    {
        $this->post(route('inventory.products.store'), $this->payload())->assertRedirect();
        $product = Product::query()->where('sku', 'INV-001')->firstOrFail();
        $this->assertSame('0.000', $product->current_stock);
        $movement = $product->movements()->sole();
        $this->assertSame(InventoryMovementType::Initial, $movement->type);
        $this->assertSame('0.000', $movement->quantity_change);
        $this->assertSame('0.000', $movement->quantity_before);
        $this->assertSame('0.000', $movement->quantity_after);
    }

    public function test_product_validation_rejects_duplicates_invalid_values_arrays_and_tampering(): void
    {
        Product::factory()->create(['sku' => 'INV-001', 'category_id' => $this->category]);
        $this->post(route('inventory.products.store'), $this->payload())->assertSessionHasErrors('sku');
        $this->post(route('inventory.products.store'), $this->payload([
            'sku' => ['nested'], 'name' => ['nested'], 'cost_price' => '-1', 'selling_price' => ['2'],
            'initial_stock' => '-2', 'reorder_level' => '-1', 'category_id' => 999999,
        ]))->assertSessionHasErrors(['sku', 'name', 'cost_price', 'selling_price', 'initial_stock', 'reorder_level', 'category_id']);
        $this->post(route('inventory.products.store'), $this->payload([
            'sku' => 'TAMPER-1', 'current_stock' => '999', 'created_by' => 999, 'is_active' => false, 'deleted_at' => now(),
        ]))->assertSessionHasErrors(['current_stock', 'created_by', 'is_active', 'deleted_at']);
    }

    public function test_edit_updates_profile_but_rejects_direct_stock_and_identity_tampering(): void
    {
        $product = Product::factory()->create(['category_id' => $this->category, 'current_stock' => '8.000']);
        $payload = $this->payload(['name' => 'Updated Item', 'sku' => ' updated-2 ', 'current_stock' => '999', 'updated_by' => 999, 'is_active' => false]);
        unset($payload['initial_stock']);
        $this->put(route('inventory.products.update', $product), $payload)->assertSessionHasErrors(['current_stock', 'updated_by', 'is_active']);
        $this->assertSame('8.000', $product->fresh()->current_stock);

        unset($payload['current_stock'], $payload['updated_by'], $payload['is_active']);
        $this->put(route('inventory.products.update', $product), $payload)->assertRedirect();
        $this->assertSame('UPDATED-2', $product->fresh()->sku);
        $this->assertSame('8.000', $product->fresh()->current_stock);
    }

    public function test_low_stock_is_derived_at_below_equal_and_above_reorder_level(): void
    {
        $below = Product::factory()->create(['current_stock' => '4', 'reorder_level' => '5']);
        $equal = Product::factory()->create(['current_stock' => '5', 'reorder_level' => '5']);
        $above = Product::factory()->create(['current_stock' => '6', 'reorder_level' => '5']);
        $this->assertTrue($below->isLowStock());
        $this->assertTrue($equal->isLowStock());
        $this->assertFalse($above->isLowStock());
        $this->assertEqualsCanonicalizing([$below->id, $equal->id], Product::query()->lowStock()->pluck('id')->all());
    }

    public function test_admin_archives_product_without_deleting_movements(): void
    {
        $product = Product::factory()->create(['category_id' => $this->category]);
        $this->post(route('inventory.products.adjust', $product), ['type' => 'restock', 'operation' => 'increase', 'quantity' => '2', 'reason' => 'History']);
        $movementId = $product->movements()->firstOrFail()->id;

        $this->delete(route('inventory.products.destroy', $product))->assertRedirect(route('inventory.index'));
        $this->assertSoftDeleted($product);
        $this->assertDatabaseHas('inventory_movements', ['id' => $movementId, 'product_id' => $product->id]);
        $this->get('/inventory')->assertOk()->assertDontSee($product->sku);
    }

    public function test_manager_can_deactivate_and_reactivate_a_product_with_audit_history(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $product = Product::factory()->create(['category_id' => $this->category, 'is_active' => true]);

        $this->actingAs($manager)->post(route('inventory.products.deactivate', $product))->assertRedirect();
        $this->assertFalse($product->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'product_deactivated',
            'actor_id' => $manager->id,
            'auditable_id' => $product->id,
        ]);

        $this->post(route('inventory.products.activate', $product))->assertRedirect();
        $this->assertTrue($product->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'product_activated',
            'actor_id' => $manager->id,
            'auditable_id' => $product->id,
        ]);
    }

    public function test_product_views_never_expose_identity_secrets(): void
    {
        $product = Product::factory()->create(['category_id' => $this->category]);
        $response = $this->get(route('inventory.products.show', $product))->assertOk();
        $response->assertDontSee($this->admin->password)->assertDontSee('quick_pin_hash')->assertDontSee('remember_token');
        $this->assertTrue(Hash::check('password', $this->admin->password));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'category_id' => $this->category->id,
            'name' => 'Inventory Item',
            'sku' => 'INV-001',
            'description' => 'A useful product.',
            'cost_price' => '1000.00',
            'selling_price' => '1500.00',
            'initial_stock' => '0',
            'reorder_level' => '5',
            'unit' => 'piece',
        ], $overrides);
    }
}
