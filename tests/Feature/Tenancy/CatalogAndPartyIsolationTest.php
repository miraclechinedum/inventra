<?php

namespace Tests\Feature\Tenancy;

use App\Actions\Inventory\AdjustStock;
use App\Actions\Inventory\CreateProduct;
use App\Dashboard\DashboardOverview;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\User;
use App\Tenancy\BusinessContextException;
use App\Tenancy\CurrentBusiness;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

/**
 * Two businesses with deliberately overlapping catalogue and party data. Every value that is unique
 * within a business is reused by the other, so these tests show both that tenants may share values
 * and that no path lets one tenant read, change or reference the other's rows.
 */
class CatalogAndPartyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Business $a;

    private Business $b;

    private User $adminA;

    private User $adminB;

    private ProductCategory $categoryA;

    private ProductCategory $categoryB;

    private Product $productA;

    private Product $productB;

    private Customer $customerA;

    private Customer $customerB;

    private int $supplierA;

    private int $supplierB;

    private int $expenseCategoryA;

    private int $expenseCategoryB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Business::factory()->create(['name' => 'Alpha Spares']);
        $this->b = Business::factory()->create(['name' => 'Bravo Hardware']);
        $this->adminA = User::factory()->forBusiness($this->a)->create(['role' => UserRole::Admin]);
        $this->adminB = User::factory()->forBusiness($this->b)->create(['role' => UserRole::Admin]);

        $this->categoryA = ProductCategory::factory()->forBusiness($this->a)->create(['name' => 'Filters']);
        $this->categoryB = ProductCategory::factory()->forBusiness($this->b)->create(['name' => 'Filters']);
        $this->productA = Product::factory()->forBusiness($this->a)->create([
            'category_id' => $this->categoryA->id, 'sku' => 'OIL-001', 'name' => 'Alpha Oil Filter',
            'current_stock' => '50.000', 'reorder_level' => '5.000', 'cost_price' => '100.00',
        ]);
        $this->productB = Product::factory()->forBusiness($this->b)->create([
            'category_id' => $this->categoryB->id, 'sku' => 'OIL-001', 'name' => 'Bravo Oil Filter',
            'current_stock' => '1.000', 'reorder_level' => '5.000', 'cost_price' => '900000.00',
        ]);
        $this->customerA = Customer::factory()->forBusiness($this->a)->create(['first_name' => 'Ada', 'last_name' => 'Alpha', 'phone' => '+2348031234567']);
        $this->customerB = Customer::factory()->forBusiness($this->b)->create(['first_name' => 'Bola', 'last_name' => 'Bravo', 'phone' => '+2348031234567']);
        $this->supplierA = $this->insert('suppliers', $this->a, ['supplier_code' => 'SUP-SHARED', 'name' => 'Alpha Supply', 'is_active' => true, 'created_by' => $this->adminA->id]);
        $this->supplierB = $this->insert('suppliers', $this->b, ['supplier_code' => 'SUP-SHARED', 'name' => 'Bravo Supply', 'is_active' => true, 'created_by' => $this->adminB->id]);
        $this->expenseCategoryA = $this->insert('expense_categories', $this->a, ['category_code' => 'EXPCAT-SHARED', 'name' => 'Alpha Rent', 'is_active' => true, 'created_by' => $this->adminA->id]);
        $this->expenseCategoryB = $this->insert('expense_categories', $this->b, ['category_code' => 'EXPCAT-SHARED', 'name' => 'Bravo Rent', 'is_active' => true, 'created_by' => $this->adminB->id]);
    }

    /* ------------------------------------------------------------------- uniqueness */

    public function test_shared_values_are_accepted_across_businesses_and_rejected_within_one(): void
    {
        // setUp already stored every shared value once in each business; a second copy inside A fails.
        $duplicates = [
            'category name' => fn () => $this->insert('product_categories', $this->a, ['name' => 'Filters', 'is_active' => true]),
            'sku' => fn () => $this->insert('products', $this->a, [
                'public_id' => (string) str()->ulid(), 'category_id' => $this->categoryA->id, 'sku' => 'OIL-001', 'name' => 'Copy',
                'unit' => 'piece', 'cost_price' => '1.00', 'selling_price' => '1.00', 'current_stock' => '0', 'reorder_level' => '0', 'is_active' => true,
            ]),
            'customer phone' => fn () => $this->insert('customers', $this->a, ['customer_code' => 'CUST-COPY', 'first_name' => 'Copy', 'phone' => '+2348031234567', 'is_active' => true]),
            'customer code' => fn () => $this->insert('customers', $this->a, ['customer_code' => $this->customerA->customer_code, 'first_name' => 'Copy', 'phone' => '+2348039999999', 'is_active' => true]),
            'supplier code' => fn () => $this->insert('suppliers', $this->a, ['supplier_code' => 'SUP-SHARED', 'name' => 'Copy', 'is_active' => true, 'created_by' => $this->adminA->id]),
            'expense category code' => fn () => $this->insert('expense_categories', $this->a, ['category_code' => 'EXPCAT-SHARED', 'name' => 'Copy', 'is_active' => true, 'created_by' => $this->adminA->id]),
        ];

        foreach ($duplicates as $label => $attempt) {
            try {
                $attempt();
                $this->fail("A duplicate {$label} inside one business must be rejected.");
            } catch (QueryException $exception) {
                $this->assertSame('23000', $exception->errorInfo[0], $label);
            }
        }

        // The customer code shared across businesses is accepted, as the others were in setUp.
        $this->insert('customers', $this->b, ['customer_code' => $this->customerA->customer_code, 'first_name' => 'Twin', 'phone' => '+2348037777777', 'is_active' => true]);
    }

    public function test_forms_enforce_uniqueness_within_the_business_only(): void
    {
        $this->actingAs($this->adminA)->post(route('inventory.categories.store'), ['name' => 'Filters'])->assertSessionHasErrors('name');
        $this->actingAs($this->adminA)->post(route('inventory.products.store'), $this->productPayload(['sku' => 'OIL-001']))->assertSessionHasErrors('sku');
        $this->actingAs($this->adminA)->post(route('customers.store'), $this->customerPayload(['phone' => '08031234567']))->assertSessionHasErrors('phone');

        $this->actingAs($this->adminB)->post(route('inventory.categories.store'), ['name' => 'Gaskets'])->assertSessionHasNoErrors();
        $this->actingAs($this->adminA)->post(route('inventory.categories.store'), ['name' => 'Gaskets'])->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('product_categories')->where('name', 'Gaskets')->count());

        $this->actingAs($this->adminA)->getJson(route('inventory.products.check-duplicate', ['field' => 'sku', 'value' => 'OIL-001']))->assertJson(['exists' => true]);
        DB::table('products')->where('id', $this->productA->id)->update(['sku' => 'ALPHA-ONLY']);
        // B's identical SKU is invisible to A's duplicate check.
        $this->actingAs($this->adminA)->getJson(route('inventory.products.check-duplicate', ['field' => 'sku', 'value' => 'OIL-001']))->assertJson(['exists' => false]);
    }

    /* ------------------------------------------------------------------- products */

    public function test_product_pages_show_only_the_businesss_own_catalogue(): void
    {
        $this->actingAs($this->adminA)->get(route('inventory.index'))->assertOk()->assertSee('Alpha Oil Filter')->assertDontSee('Bravo Oil Filter');
        $this->actingAs($this->adminB)->get(route('inventory.index'))->assertOk()->assertSee('Bravo Oil Filter')->assertDontSee('Alpha Oil Filter');
        $this->actingAs($this->adminA)->get(route('inventory.categories.index'))->assertOk()->assertSee('Filters');
    }

    public function test_another_businesss_product_is_not_found_on_every_route(): void
    {
        $product = $this->productB;
        $stockBefore = DB::table('products')->where('id', $product->id)->value('current_stock');

        foreach ([
            ['get', route('inventory.products.show', $product)],
            ['get', route('inventory.products.edit', $product)],
            ['get', route('inventory.products.movements', $product)],
            ['get', route('inventory.products.image', $product)],
            ['put', route('inventory.products.update', $product)],
            ['post', route('inventory.products.adjust', $product)],
            ['post', route('inventory.products.deactivate', $product)],
            ['delete', route('inventory.products.destroy', $product)],
            ['delete', route('inventory.products.force-destroy', $product)],
        ] as [$method, $url]) {
            $this->actingAs($this->adminA)->{$method}($url, ['type' => 'restock', 'operation' => 'increase', 'quantity' => '5', 'reason' => 'x'])
                ->assertNotFound();
        }

        $this->assertSame($stockBefore, DB::table('products')->where('id', $product->id)->value('current_stock'));
        $this->assertTrue((bool) DB::table('products')->where('id', $product->id)->value('is_active'));
        $this->assertNull(DB::table('products')->where('id', $product->id)->value('deleted_at'));
    }

    public function test_a_product_cannot_be_given_another_businesss_category(): void
    {
        $this->actingAs($this->adminA)->post(route('inventory.products.store'), $this->productPayload(['sku' => 'NEW-1', 'category_id' => $this->categoryB->id]))
            ->assertSessionHasErrors('category_id');
        $this->assertFalse(DB::table('products')->where('sku', 'NEW-1')->exists());

        $this->actingAs($this->adminA)->put(route('inventory.products.update', $this->productA), $this->productPayload(['sku' => 'OIL-001', 'category_id' => $this->categoryB->id]))
            ->assertSessionHasErrors('category_id');
        $this->assertSame($this->categoryA->id, DB::table('products')->where('id', $this->productA->id)->value('category_id'));

        // The action refuses it without the form...
        app(CurrentBusiness::class)->run($this->a, function (): void {
            try {
                app(CreateProduct::class)->execute($this->adminA, $this->productPayload(['sku' => 'NEW-2', 'category_id' => $this->categoryB->id]));
                $this->fail('The action must refuse another business\'s category.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('category_id', $exception->errors());
            }
        });

        // ...and the database refuses it without the application.
        $this->expectException(QueryException::class);
        DB::table('products')->where('id', $this->productA->id)->update(['category_id' => $this->categoryB->id]);
    }

    public function test_search_autocomplete_and_the_sale_customer_lookup_are_isolated(): void
    {
        $products = $this->actingAs($this->adminA)->getJson(route('search', ['q' => 'Oil']))->assertOk()->json('products');
        $this->assertSame(['Alpha Oil Filter'], array_column($products, 'name'));

        $customers = $this->actingAs($this->adminA)->getJson(route('search', ['q' => '+23480312']))->assertOk()->json('customers');
        $this->assertCount(1, $customers);
        $this->assertStringContainsString('Ada', json_encode($customers));
        $this->assertStringNotContainsString('Bola', json_encode($customers));

        $lookup = $this->actingAs($this->adminA)->getJson(route('sales.customers.search', ['q' => 'B']))->assertOk()->getContent();
        $this->assertStringNotContainsString('Bola', $lookup);
    }

    public function test_low_stock_and_inventory_value_are_computed_per_business(): void
    {
        $current = app(CurrentBusiness::class);

        $alpha = $current->run($this->a, fn () => app(DashboardOverview::class)->for($this->adminA));
        $bravo = $current->run($this->b, fn () => app(DashboardOverview::class)->for($this->adminB));

        // A: 50 in stock at 100.00, nothing low. B: 1 in stock at 900,000.00, below its reorder level.
        $this->assertSame(0, $alpha['kpis']['lowStockCount']);
        $this->assertSame('5000.00', $alpha['kpis']['inventoryValue']);
        $this->assertSame(1, $bravo['kpis']['lowStockCount']);
        $this->assertSame('900000.00', $bravo['kpis']['inventoryValue']);
        $this->assertSame(0, $current->run($this->a, fn () => Product::query()->active()->lowStock()->count()));
    }

    /* ------------------------------------------------------------------ inventory */

    public function test_inventory_history_and_movements_are_isolated(): void
    {
        $this->actingAs($this->adminB)->post(route('inventory.products.adjust', $this->productB), [
            'type' => 'restock', 'operation' => 'increase', 'quantity' => '4', 'reason' => 'Delivery',
        ])->assertRedirect();

        $movement = DB::table('inventory_movements')->where('product_id', $this->productB->id)->latest('id')->first();
        $this->assertSame($this->b->id, $movement->business_id, 'A movement takes its product\'s business');

        $this->actingAs($this->adminA)->get(route('inventory.products.movements', $this->productB))->assertNotFound();
        $this->assertSame(0, app(CurrentBusiness::class)->run($this->a, fn () => InventoryMovement::query()->where('product_id', $this->productB->id)->count()));

        // A movement can never claim one business while pointing at another's product.
        $this->expectException(QueryException::class);
        DB::table('inventory_movements')->insert([
            'business_id' => $this->a->id, 'product_id' => $this->productB->id, 'type' => 'adjustment',
            'quantity_change' => '1', 'quantity_before' => '0', 'quantity_after' => '1', 'created_at' => now(),
        ]);
    }

    public function test_a_stock_adjustment_cannot_reach_another_businesss_product(): void
    {
        $before = DB::table('products')->where('id', $this->productB->id)->value('current_stock');

        app(CurrentBusiness::class)->run($this->a, function (): void {
            try {
                // The locked re-read goes through the tenant scope, so B's product is simply absent.
                app(AdjustStock::class)->execute($this->adminA, $this->productB, [
                    'type' => 'restock', 'operation' => 'increase', 'quantity' => '5', 'reason' => 'x',
                ]);
                $this->fail('A stock adjustment must not reach another business\'s product.');
            } catch (ModelNotFoundException) {
                // expected
            }
        });

        $this->assertSame($before, DB::table('products')->where('id', $this->productB->id)->value('current_stock'));
    }

    /* ---------------------------------------------------------------- categories */

    public function test_category_edit_update_and_lifecycle_routes_do_not_reach_another_business(): void
    {
        foreach ([
            ['get', route('inventory.categories.edit', $this->categoryB)],
            ['put', route('inventory.categories.update', $this->categoryB)],
            ['post', route('inventory.categories.deactivate', $this->categoryB)],
        ] as [$method, $url]) {
            $this->actingAs($this->adminA)->{$method}($url, ['name' => 'Hijacked'])->assertNotFound();
        }

        $this->assertSame('Filters', DB::table('product_categories')->where('id', $this->categoryB->id)->value('name'));
        $this->assertTrue((bool) DB::table('product_categories')->where('id', $this->categoryB->id)->value('is_active'));
    }

    public function test_quick_create_stays_inside_the_business(): void
    {
        $created = $this->actingAs($this->adminA)->postJson(route('inventory.categories.quick-store'), ['name' => 'Belts'])->assertCreated()->json();
        $this->assertSame($this->a->id, DB::table('product_categories')->where('id', $created['id'])->value('business_id'));

        // A name B already uses is still new to A.
        DB::table('product_categories')->where('id', $this->categoryA->id)->update(['name' => 'Alpha Filters']);
        $second = $this->actingAs($this->adminA)->postJson(route('inventory.categories.quick-store'), ['name' => 'Filters'])->assertCreated()->json();
        $this->assertNotSame($this->categoryB->id, $second['id']);
        $this->assertSame($this->a->id, DB::table('product_categories')->where('id', $second['id'])->value('business_id'));
    }

    /* ------------------------------------------------------------------ customers */

    public function test_customer_pages_and_actions_do_not_reach_another_business(): void
    {
        $this->actingAs($this->adminA)->get(route('customers.index'))->assertOk()->assertSee('Ada')->assertDontSee('Bola');

        foreach ([
            ['get', route('customers.show', $this->customerB)],
            ['get', route('customers.edit', $this->customerB)],
            ['get', route('customers.activity', $this->customerB)],
            ['put', route('customers.update', $this->customerB)],
            ['post', route('customers.deactivate', $this->customerB)],
            ['post', route('customers.consent', $this->customerB)],
        ] as [$method, $url]) {
            $this->actingAs($this->adminA)->{$method}($url, $this->customerPayload(['first_name' => 'Hijacked', 'whatsapp_opt_in' => '1']))->assertNotFound();
        }

        $this->assertSame('Bola', DB::table('customers')->where('id', $this->customerB->id)->value('first_name'));
        $this->assertTrue((bool) DB::table('customers')->where('id', $this->customerB->id)->value('is_active'));

        $export = $this->actingAs($this->adminA)->get(route('customers.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Ada', $export);
        $this->assertStringNotContainsString('Bola', $export);
    }

    public function test_a_new_customer_belongs_to_the_creating_business_and_may_reuse_anothers_phone(): void
    {
        $this->actingAs($this->adminB)->post(route('customers.store'), $this->customerPayload(['phone' => '08035550000', 'first_name' => 'Chidi']))->assertRedirect();
        $this->actingAs($this->adminA)->post(route('customers.store'), $this->customerPayload(['phone' => '08035550000', 'first_name' => 'Chidi']))->assertRedirect();

        $this->assertEqualsCanonicalizing([$this->a->id, $this->b->id], DB::table('customers')->where('phone', '+2348035550000')->pluck('business_id')->all());

        $this->actingAs($this->adminA)->post(route('customers.store'), $this->customerPayload(['phone' => '08035550000', 'business_id' => $this->b->id]))
            ->assertSessionHasErrors('business_id');
    }

    /* ------------------------------------------------------ suppliers & expense categories */

    public function test_supplier_lists_and_records_are_isolated(): void
    {
        $this->actingAs($this->adminA)->get(route('suppliers.index'))->assertOk()->assertSee('Alpha Supply')->assertDontSee('Bravo Supply');
        $this->actingAs($this->adminA)->get(route('suppliers.index', ['search' => 'SUP-SHARED']))->assertOk()->assertDontSee('Bravo Supply');
        $this->actingAs($this->adminA)->get(route('suppliers.show', $this->supplierB))->assertNotFound();
        $this->actingAs($this->adminA)->put(route('suppliers.update', $this->supplierB), ['name' => 'Hijacked'])->assertNotFound();
        $this->actingAs($this->adminA)->post(route('suppliers.deactivate', $this->supplierB))->assertNotFound();
        $this->assertSame('Bravo Supply', DB::table('suppliers')->where('id', $this->supplierB)->value('name'));

        $this->actingAs($this->adminA)->post(route('suppliers.store'), ['name' => 'Alpha Second'])->assertRedirect();
        $this->assertSame($this->a->id, DB::table('suppliers')->where('name', 'Alpha Second')->value('business_id'));
    }

    public function test_expense_categories_are_isolated_across_list_create_edit_and_lifecycle(): void
    {
        $this->actingAs($this->adminA)->get(route('expense-categories.index'))->assertOk()->assertSee('Alpha Rent')->assertDontSee('Bravo Rent');
        $this->actingAs($this->adminA)->get(route('expense-categories.show', $this->expenseCategoryB))->assertNotFound();
        $this->actingAs($this->adminA)->get(route('expense-categories.edit', $this->expenseCategoryB))->assertNotFound();
        $this->actingAs($this->adminA)->put(route('expense-categories.update', $this->expenseCategoryB), ['name' => 'Hijacked'])->assertNotFound();
        $this->actingAs($this->adminA)->post(route('expense-categories.deactivate', $this->expenseCategoryB))->assertNotFound();
        $this->assertSame('Bravo Rent', DB::table('expense_categories')->where('id', $this->expenseCategoryB)->value('name'));

        $this->actingAs($this->adminA)->post(route('expense-categories.store'), ['name' => 'Alpha Fuel'])->assertRedirect();
        $this->assertSame($this->a->id, DB::table('expense_categories')->where('name', 'Alpha Fuel')->value('business_id'));
    }

    /* ---------------------------------------------------------- scope & ownership */

    public function test_tenant_models_fail_closed_without_a_business_in_context(): void
    {
        app(CurrentBusiness::class)->forget();

        $refused = [];

        foreach ([Product::class, ProductCategory::class, InventoryMovement::class, Customer::class, Supplier::class, ExpenseCategory::class] as $model) {
            try {
                $model::query()->count();
            } catch (BusinessContextException) {
                $refused[] = $model;
            }
        }

        $this->assertCount(6, $refused, 'Every tenant-owned model must refuse to read without a business in context');
    }

    public function test_ownership_can_never_be_moved_to_another_business(): void
    {
        $this->expectException(LogicException::class);

        $customer = app(CurrentBusiness::class)->run($this->a, fn () => Customer::query()->findOrFail($this->customerA->id));
        $customer->forceFill(['business_id' => $this->b->id])->save();
    }

    public function test_sequential_requests_for_different_businesses_do_not_share_context(): void
    {
        $this->actingAs($this->adminA)->get(route('inventory.products.show', $this->productA))->assertOk();
        $this->actingAs($this->adminB)->get(route('inventory.products.show', $this->productA))->assertNotFound();
        $this->actingAs($this->adminB)->get(route('inventory.products.show', $this->productB))->assertOk();
        $this->actingAs($this->adminA)->get(route('inventory.products.show', $this->productB))->assertNotFound();
    }

    /* -------------------------------------------------------------------- helpers */

    private function insert(string $table, Business $business, array $values): int
    {
        return DB::table($table)->insertGetId($values + ['business_id' => $business->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function productPayload(array $overrides = []): array
    {
        return array_merge([
            'category_id' => $this->categoryA->id, 'name' => 'New Product', 'sku' => 'NEW-SKU',
            'cost_price' => '10.00', 'selling_price' => '15.00', 'initial_stock' => '3', 'reorder_level' => '1', 'unit' => 'piece',
        ], $overrides);
    }

    private function customerPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'New', 'last_name' => 'Customer', 'phone' => '08030001111',
            'email' => 'new@example.com', 'address' => '1 Road', 'city' => 'Lagos', 'notes' => null,
        ], $overrides);
    }
}
