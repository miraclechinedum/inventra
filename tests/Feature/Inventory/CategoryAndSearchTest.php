<?php

namespace Tests\Feature\Inventory;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryAndSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => UserRole::Manager]);
        $this->actingAs($this->manager);
    }

    public function test_manager_creates_updates_and_controls_category_state(): void
    {
        $this->post(route('inventory.categories.store'), ['name' => '  Drinks  ', 'description' => 'Beverages'])->assertRedirect();
        $category = ProductCategory::query()->where('name', 'Drinks')->firstOrFail();
        $this->put(route('inventory.categories.update', $category), ['name' => 'Cold Drinks', 'description' => 'Updated'])->assertRedirect();
        $this->post(route('inventory.categories.deactivate', $category))->assertRedirect();
        $this->assertFalse($category->fresh()->is_active);
        $this->post(route('inventory.categories.activate', $category))->assertRedirect();
        $this->assertTrue($category->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'category_updated', 'actor_id' => $this->manager->id]);
    }

    public function test_duplicate_array_category_and_dependent_deactivation_are_controlled_errors(): void
    {
        $category = ProductCategory::factory()->create(['name' => 'Drinks']);
        $this->post(route('inventory.categories.store'), ['name' => 'drinks'])->assertSessionHasErrors('name');
        $this->post(route('inventory.categories.store'), ['name' => ['Drinks']])->assertSessionHasErrors('name');
        Product::factory()->create(['category_id' => $category]);
        $this->post(route('inventory.categories.deactivate', $category))->assertSessionHasErrors('category');
        $this->assertTrue($category->fresh()->is_active);
    }

    public function test_search_filters_pagination_and_literal_wildcards_are_safe(): void
    {
        $one = ProductCategory::factory()->create(['name' => 'One']);
        $two = ProductCategory::factory()->create(['name' => 'Two']);
        Product::factory()->create(['category_id' => $one, 'name' => '% Percent Product', 'sku' => 'PERCENT-1', 'current_stock' => '1', 'reorder_level' => '2']);
        Product::factory()->create(['category_id' => $two, 'name' => '_ Under Product', 'sku' => 'UNDER-1', 'current_stock' => '5', 'reorder_level' => '2', 'is_active' => false]);
        Product::factory()->create(['category_id' => $two, 'name' => '\\ Back Product', 'sku' => 'BACK-1']);
        Product::factory()->create(['category_id' => $two, 'name' => 'Ordinary Product', 'sku' => 'ORDINARY-1']);

        $this->get('/inventory?search=PERCENT')->assertSee('PERCENT-1')->assertDontSee('ORDINARY-1');
        $this->get('/inventory?search=%25')->assertSee('% Percent Product')->assertDontSee('Ordinary Product');
        $this->get('/inventory?search=_')->assertSee('_ Under Product')->assertDontSee('Ordinary Product');
        $this->get('/inventory?search=%5C')->assertSee('\\ Back Product')->assertDontSee('Ordinary Product');
        $this->get('/inventory?category='.$one->id)->assertSee('PERCENT-1')->assertDontSee('ORDINARY-1');
        $this->get('/inventory?stock=low')->assertSee('PERCENT-1')->assertDontSee('ORDINARY-1');
        $this->get('/inventory?status=inactive')->assertSee('UNDER-1')->assertDontSee('PERCENT-1');
        $this->get('/inventory?search[]=x&category[]=1&stock[]=low&status[]=active')->assertOk();
        Product::factory()->count(16)->create();
        $this->get('/inventory')->assertSee('Next');
    }
}
