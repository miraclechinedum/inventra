<?php

namespace Tests\Feature\Inventory;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The searchable category field and the access rules around categories.
 *
 * Two abilities are deliberately distinct: choosing a category while working on a product, which
 * every role that may touch products needs, and managing categories, which only Administrators and
 * Managers may do. The screen, the navigation and the creation endpoint all derive from the second.
 */
class CategoryComboboxAndAccessTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function category(string $name, bool $active = true): ProductCategory
    {
        return ProductCategory::factory()->create([
            'name' => $name,
            'is_active' => $active,
            'created_by' => $this->admin->id,
        ]);
    }

    // ─────────────────────────────── searching & selecting ───────────────────────────────────────

    public function test_the_product_form_ships_the_active_categories_the_field_searches(): void
    {
        $this->category('Filters');
        $this->category('Fuel Filters');
        $this->category('Fan Belts');
        $this->category('Retired Parts', active: false);

        foreach ([UserRole::Admin, UserRole::Manager] as $role) {
            $response = $this->actingAs($this->user($role))->get(route('inventory.products.create'))->assertOk();
            // Filtering happens in the browser over exactly this list, so the payload is the contract.
            $response->assertSee('Filters')->assertSee('Fuel Filters')->assertSee('Fan Belts');
            $response->assertDontSee('Retired Parts');
            $response->assertSee('x-data="categoryCombobox"', false);
        }
    }

    public function test_a_sales_representative_may_select_a_category_where_product_access_allows_it(): void
    {
        $category = $this->category('Filters');
        $product = Product::factory()->create(['category_id' => $category->id, 'is_active' => true]);
        $rep = $this->user(UserRole::SalesRep);

        // A Sales Representative may read a product and therefore its category.
        $this->actingAs($rep)->get(route('inventory.products.show', $product))->assertOk()->assertSee('Filters');
        $this->assertTrue($rep->can('select', ProductCategory::class));
    }

    public function test_the_current_category_is_preselected_when_editing_a_product(): void
    {
        $chosen = $this->category('Brake Pads');
        $this->category('Filters');
        $product = Product::factory()->create(['category_id' => $chosen->id]);

        $this->actingAs($this->admin)
            ->get(route('inventory.products.edit', $product))
            ->assertOk()
            // The combobox receives the current id as its starting selection.
            ->assertSee('x-data="categoryCombobox"', false)
            ->assertSee('data-selected-id="'.$chosen->id.'"', false);
    }

    public function test_old_input_survives_a_validation_error_on_the_edit_form(): void
    {
        $first = $this->category('Brake Pads');
        $second = $this->category('Filters');
        $product = Product::factory()->create(['category_id' => $first->id]);

        $this->actingAs($this->admin)
            ->from(route('inventory.products.edit', $product))
            ->followingRedirects()
            ->put(route('inventory.products.update', $product), [
                'category_id' => $second->id,
                'name' => '',
                'sku' => $product->sku,
                'cost_price' => '10.00',
                'selling_price' => '20.00',
                'reorder_level' => '1',
                'unit' => 'piece',
            ])
            ->assertOk()
            // The attempted category, not the stored one, is what comes back.
            ->assertSee('data-selected-id="'.$second->id.'"', false);
    }

    // ───────────────────────────────── inline create option ──────────────────────────────────────

    public function test_the_inline_create_option_is_offered_to_an_administrator_and_a_manager(): void
    {
        foreach ([UserRole::Admin, UserRole::Manager] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('inventory.products.create'))
                ->assertOk()
                ->assertSee('Create "', false)
                // data-can-create alone decides whether the create row can ever appear.
                ->assertSee('data-can-create="1"', false);
        }
    }

    public function test_a_sales_representative_is_never_offered_the_inline_create_option(): void
    {
        $category = $this->category('Filters');
        $product = Product::factory()->create(['category_id' => $category->id]);
        $rep = $this->user(UserRole::SalesRep);

        // The product form itself is already closed to this role.
        $this->actingAs($rep)->get(route('inventory.products.create'))->assertForbidden();
        $this->actingAs($rep)->get(route('inventory.products.edit', $product))->assertForbidden();
        $this->assertFalse($rep->can('create', ProductCategory::class));
    }

    // ──────────────────────────────── inline creation endpoint ───────────────────────────────────

    public function test_an_administrator_and_a_manager_can_create_a_category_inline(): void
    {
        foreach ([[UserRole::Admin, 'Inline Admin Cat'], [UserRole::Manager, 'Inline Manager Cat']] as [$role, $name]) {
            $response = $this->actingAs($this->user($role))
                ->postJson(route('inventory.categories.quick-store'), ['name' => $name])
                ->assertCreated()
                ->assertJsonStructure(['id', 'name', 'created']);

            $category = ProductCategory::query()->where('name', $name)->firstOrFail();
            $this->assertTrue($category->is_active);
            $this->assertSame($category->id, $response->json('id'));
            // Only what the combobox needs comes back.
            $this->assertSame(['id', 'name', 'created'], array_keys($response->json()));
            $this->assertDatabaseHas('audit_logs', ['action' => 'category_created', 'auditable_id' => $category->id]);
        }
    }

    public function test_a_sales_representative_creating_a_category_inline_is_refused(): void
    {
        $this->actingAs($this->user(UserRole::SalesRep))
            ->postJson(route('inventory.categories.quick-store'), ['name' => 'Smuggled'])
            ->assertForbidden();

        $this->assertDatabaseMissing('product_categories', ['name' => 'Smuggled']);
    }

    public function test_a_guest_cannot_reach_the_inline_creation_endpoint(): void
    {
        $this->postJson(route('inventory.categories.quick-store'), ['name' => 'Anonymous'])->assertUnauthorized();
        $this->assertDatabaseMissing('product_categories', ['name' => 'Anonymous']);
    }

    public function test_the_newly_created_category_is_returned_so_the_field_can_select_it(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson(route('inventory.categories.quick-store'), ['name' => 'Wiper Blades'])
            ->assertCreated();

        $category = ProductCategory::query()->where('name', 'Wiper Blades')->firstOrFail();
        $this->assertSame($category->id, $response->json('id'));
        $this->assertSame('Wiper Blades', $response->json('name'));

        // That id is then accepted as the product's category, which is the point of returning it.
        $this->actingAs($this->admin)->post(route('inventory.products.store'), [
            'category_id' => $response->json('id'),
            'name' => 'Rear Wiper', 'sku' => 'WPR-1', 'cost_price' => '900.00', 'selling_price' => '1500.00',
            'initial_stock' => '0', 'reorder_level' => '1', 'unit' => 'piece',
        ])->assertRedirect();

        $this->assertSame($category->id, Product::query()->where('sku', 'WPR-1')->firstOrFail()->category_id);
    }

    // ──────────────────────────────────── duplicates ─────────────────────────────────────────────

    public function test_a_duplicate_name_is_refused_whatever_its_casing_or_padding(): void
    {
        $this->category('Filters');

        foreach ([' filters ', 'FILTERS', 'FiLtErS', '  Filters', 'filters'] as $variant) {
            $this->actingAs($this->admin)
                ->postJson(route('inventory.categories.quick-store'), ['name' => $variant])
                ->assertStatus(422)
                ->assertJsonValidationErrors('name');
        }

        $this->assertSame(1, ProductCategory::query()->where('name', 'like', '%ilters%')->count());
    }

    public function test_an_array_shaped_name_is_rejected_rather_than_normalized(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('inventory.categories.quick-store'), ['name' => ['Nested']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_the_field_stays_within_what_the_csp_safe_alpine_build_can_evaluate(): void
    {
        // That build evaluates only identifiers and literals in attributes: an x-data carrying a
        // call with arguments (JSON.parse(...), a member expression) throws at runtime and leaves
        // the field an inert text box. Configuration therefore travels in data attributes.
        $markup = (string) file_get_contents(resource_path('views/components/category-combobox.blade.php'));

        $this->assertStringContainsString('x-data="categoryCombobox"', $markup);
        $this->assertStringNotContainsString('JSON.parse', $markup);
        $this->assertDoesNotMatchRegularExpression('/x-data="categoryCombobox\s*\(/', $markup);
    }

    // ─────────────────────────────── management page access ──────────────────────────────────────

    public function test_an_administrator_and_a_manager_can_open_the_categories_screen(): void
    {
        $this->category('Filters');

        foreach ([UserRole::Admin, UserRole::Manager] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('inventory.categories.index'))
                ->assertOk()
                ->assertSee('Product categories')
                ->assertSee('Filters');

            $this->actingAs($this->user($role))->get(route('inventory.categories.create'))->assertOk();
        }
    }

    public function test_a_sales_representative_is_refused_every_category_management_route(): void
    {
        $category = $this->category('Filters');
        $rep = $this->user(UserRole::SalesRep);

        // Hiding the navigation is not the control; each route refuses on its own.
        $this->actingAs($rep)->get(route('inventory.categories.index'))->assertForbidden();
        $this->actingAs($rep)->get(route('inventory.categories.create'))->assertForbidden();
        $this->actingAs($rep)->get(route('inventory.categories.edit', $category))->assertForbidden();
        $this->actingAs($rep)->post(route('inventory.categories.store'), ['name' => 'Denied'])->assertForbidden();
        $this->actingAs($rep)->put(route('inventory.categories.update', $category), ['name' => 'Renamed'])->assertForbidden();
        $this->actingAs($rep)->post(route('inventory.categories.deactivate', $category))->assertForbidden();
        $this->actingAs($rep)->post(route('inventory.categories.activate', $category))->assertForbidden();

        $this->assertDatabaseMissing('product_categories', ['name' => 'Denied']);
        $this->assertSame('Filters', $category->fresh()->name);
        $this->assertTrue($category->fresh()->is_active);
    }

    // ───────────────────────────────────── navigation ────────────────────────────────────────────

    public function test_the_categories_navigation_item_follows_the_policy_not_the_role_name(): void
    {
        $this->category('Filters');
        $link = route('inventory.categories.index');

        foreach ([UserRole::Admin, UserRole::Manager] as $role) {
            $this->actingAs($this->user($role))->get(route('dashboard'))->assertOk()->assertSee($link, false);
        }

        // A Sales Representative is offered no route they would be refused at.
        $this->actingAs($this->user(UserRole::SalesRep))->get(route('dashboard'))->assertOk()->assertDontSee($link, false);
    }

    // ──────────────────────────────── management page behaviour ──────────────────────────────────

    public function test_the_categories_screen_searches_and_filters_by_status(): void
    {
        $this->category('Filters');
        $this->category('Brake Pads');
        $this->category('Retired Parts', active: false);

        $this->actingAs($this->admin)->get(route('inventory.categories.index', ['search' => 'Fil']))
            ->assertOk()->assertSee('Filters')->assertDontSee('Brake Pads');

        $this->actingAs($this->admin)->get(route('inventory.categories.index', ['status' => 'inactive']))
            ->assertOk()->assertSee('Retired Parts')->assertDontSee('Brake Pads');

        // A wildcard typed into the search box is matched literally, not treated as a pattern.
        $this->actingAs($this->admin)->get(route('inventory.categories.index', ['search' => '%']))
            ->assertOk()->assertDontSee('Brake Pads');
    }

    public function test_saving_a_category_from_the_page_redirects_so_a_refresh_cannot_duplicate_it(): void
    {
        $this->actingAs($this->admin)
            ->post(route('inventory.categories.store'), ['name' => 'Page Made', 'description' => 'From the screen'])
            ->assertRedirect(route('inventory.categories.index'));

        $this->actingAs($this->admin)
            ->post(route('inventory.categories.store'), ['name' => 'Another One', 'save_and_add_another' => '1'])
            ->assertRedirect(route('inventory.categories.create'));

        $this->assertSame(1, ProductCategory::query()->where('name', 'Page Made')->count());
        $this->assertSame(1, ProductCategory::query()->where('name', 'Another One')->count());
    }
}
