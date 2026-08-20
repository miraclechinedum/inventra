<?php

namespace Tests\Feature\Inventory;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_and_all_roles_can_view_inventory(): void
    {
        $this->get('/inventory')->assertRedirect(route('login'));

        foreach (UserRole::cases() as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/inventory')->assertOk();
        }
    }

    public function test_manager_can_manage_products_but_cannot_archive(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $product = Product::factory()->create();
        $this->actingAs($manager)->get(route('inventory.products.create'))->assertOk();
        $this->get(route('inventory.products.edit', $product))->assertOk();
        $this->post(route('inventory.products.adjust', $product), $this->adjustment())->assertRedirect();
        $this->delete(route('inventory.products.destroy', $product))->assertForbidden();
    }

    public function test_sales_rep_is_strictly_read_only_and_cannot_view_movements_or_cost(): void
    {
        $sales = User::factory()->create(['role' => UserRole::SalesRep]);
        $category = ProductCategory::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'cost_price' => '98765.43']);
        $this->actingAs($sales);

        $this->get(route('inventory.products.show', $product))->assertOk()->assertDontSee('98765.43')->assertDontSee('Cost price');
        $this->get(route('inventory.products.create'))->assertForbidden();
        $this->post(route('inventory.products.store'), $this->productPayload($category))->assertForbidden();
        $this->get(route('inventory.products.edit', $product))->assertForbidden();
        $this->put(route('inventory.products.update', $product), $this->productPayload($category))->assertForbidden();
        $this->post(route('inventory.products.adjust', $product), $this->adjustment())->assertForbidden();
        $this->delete(route('inventory.products.destroy', $product))->assertForbidden();
        $this->get(route('inventory.products.movements', $product))->assertForbidden();
        $this->post(route('inventory.categories.store'), ['name' => 'Denied'])->assertForbidden();
    }

    private function adjustment(): array
    {
        return ['type' => 'restock', 'operation' => 'increase', 'quantity' => '1', 'reason' => 'Test'];
    }

    private function productPayload(ProductCategory $category): array
    {
        return ['category_id' => $category->id, 'name' => 'Denied', 'sku' => 'DENIED-1', 'cost_price' => '1.00', 'selling_price' => '2.00', 'initial_stock' => '0', 'reorder_level' => '0', 'unit' => 'piece'];
    }
}
