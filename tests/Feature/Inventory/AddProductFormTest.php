<?php

namespace Tests\Feature\Inventory;

use App\Enums\InventoryMovementType;
use App\Enums\ProductUnit;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\ImageStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The Add Product screen. What matters is that the redesign kept every server-side rule: the same
 * required fields, the same authorization, the same authoritative opening movement, and an optional
 * photograph that either lands with its product or leaves nothing behind.
 */
class AddProductFormTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> paths written during a test, removed afterwards */
    private array $written = [];

    private User $admin;

    private ProductCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->category = ProductCategory::factory()->create(['created_by' => $this->admin, 'is_active' => true]);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        $store = app(ImageStore::class);

        foreach ($this->written as $path) {
            $store->delete($path);
        }

        $this->written = [];
        parent::tearDown();
    }

    // ───────────────────────────────────── the screen itself ─────────────────────────────────────

    public function test_an_administrator_can_open_the_add_product_screen(): void
    {
        $this->get(route('inventory.products.create'))
            ->assertOk()
            ->assertSee('Add product')
            ->assertSee('Product image')
            ->assertSee('e.g. Toyota Camry oil filter')
            ->assertSee('Save Product')
            ->assertSee('Save &amp; add another', false)
            // Every field the backend requires is still present. Unit has no place in this
            // design, so it travels as a hidden default rather than a visible control; Description
            // is not part of this screen at all and is simply omitted from the submission.
            ->assertSee('name="name"', false)
            ->assertSee('name="sku"', false)
            ->assertSee('name="category_id"', false)
            ->assertSee('name="selling_price"', false)
            ->assertSee('name="cost_price"', false)
            ->assertSee('name="initial_stock"', false)
            ->assertSee('name="reorder_level"', false)
            ->assertSee('type="hidden" name="unit" value="piece"', false)
            ->assertSee('name="image"', false);
    }

    public function test_a_sales_representative_cannot_open_or_post_the_form(): void
    {
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);

        $this->actingAs($rep)->get(route('inventory.products.create'))->assertForbidden();
        $this->actingAs($rep)->post(route('inventory.products.store'), $this->payload())->assertForbidden();
        $this->assertDatabaseMissing('products', ['sku' => 'FLT-0921']);
    }

    public function test_the_form_accepts_a_multipart_submission_and_keeps_the_stepper_values(): void
    {
        $this->post(route('inventory.products.store'), $this->payload([
            'initial_stock' => '7',
            'reorder_level' => '3',
        ]))->assertRedirect();

        $product = Product::query()->where('sku', 'FLT-0921')->firstOrFail();
        $this->assertSame('7.000', $product->current_stock);
        $this->assertSame('3.000', $product->reorder_level);
    }

    // ──────────────────────────────────────── creation ───────────────────────────────────────────

    public function test_a_product_is_created_without_an_image(): void
    {
        $this->post(route('inventory.products.store'), $this->payload())->assertRedirect();

        $product = Product::query()->where('sku', 'FLT-0921')->firstOrFail();
        $this->assertNull($product->image_path);
    }

    public function test_a_product_is_created_with_an_image_in_one_submission(): void
    {
        $this->post(route('inventory.products.store'), $this->payload([
            'image' => UploadedFile::fake()->image('filter.png', 600, 600),
        ]))->assertRedirect();

        $product = $this->track(Product::query()->where('sku', 'FLT-0921')->firstOrFail());
        $store = app(ImageStore::class);

        $this->assertNotNull($product->image_path);
        $this->assertTrue($store->exists($product->image_path));
        // The server named the file and chose its extension from the decoded type.
        $this->assertMatchesRegularExpression('#^product-images/[A-Za-z0-9]{40}\.png$#', $product->image_path);
        // Never web-reachable; only the authorized route can read it.
        $this->assertStringContainsString('/storage/app/private/', $store->absolutePath($product->image_path));
        $this->assertDatabaseHas('audit_logs', ['action' => 'product_image_attached', 'auditable_id' => $product->id]);
    }

    public function test_the_created_image_is_served_only_through_the_authorized_route(): void
    {
        $this->post(route('inventory.products.store'), $this->payload([
            'image' => UploadedFile::fake()->image('filter.png'),
        ]))->assertRedirect();

        $product = $this->track(Product::query()->where('sku', 'FLT-0921')->firstOrFail());

        $response = $this->get(route('inventory.products.image', $product))->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        // A guest gets no photograph at all.
        auth()->logout();
        $this->get(route('inventory.products.image', $product))->assertRedirect();
    }

    public function test_initial_stock_creates_the_one_immutable_opening_movement(): void
    {
        $this->post(route('inventory.products.store'), $this->payload(['initial_stock' => '12.500']))->assertRedirect();

        $product = Product::query()->where('sku', 'FLT-0921')->firstOrFail();
        $movement = $product->movements()->sole();

        $this->assertSame(InventoryMovementType::Initial, $movement->type);
        $this->assertSame('12.500', $movement->quantity_change);
        $this->assertSame('0.000', $movement->quantity_before);
        $this->assertSame('12.500', $movement->quantity_after);
        $this->assertSame($this->admin->id, $movement->performed_by);
        $this->assertSame('12.500', $product->current_stock);
    }

    public function test_the_browser_cannot_dictate_stock_or_privileged_columns(): void
    {
        $this->post(route('inventory.products.store'), $this->payload([
            'current_stock' => '999',
            'created_by' => 4242,
            'is_active' => false,
            'image_path' => 'product-images/'.str_repeat('a', 40).'.png',
        ]))->assertSessionHasErrors(['current_stock', 'created_by', 'is_active', 'image_path']);

        $this->assertDatabaseMissing('products', ['sku' => 'FLT-0921']);
    }

    // ────────────────────────────────── image validation ────────────────────────────────────────

    public function test_an_oversized_image_is_rejected_and_no_product_is_created(): void
    {
        $oversized = UploadedFile::fake()->create('huge.png', (int) (ImageStore::MAX_BYTES / 1024) + 64, 'image/png');

        $this->post(route('inventory.products.store'), $this->payload(['image' => $oversized]))
            ->assertSessionHasErrors('image');

        $this->assertDatabaseMissing('products', ['sku' => 'FLT-0921']);
    }

    public function test_a_non_image_and_an_svg_are_both_rejected(): void
    {
        $this->post(route('inventory.products.store'), $this->payload([
            'image' => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'),
        ]))->assertSessionHasErrors('image');

        // An SVG can carry script, so it stays outside the allowlist even though it is an image.
        $this->post(route('inventory.products.store'), $this->payload([
            'image' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'),
        ]))->assertSessionHasErrors('image');

        $this->assertDatabaseMissing('products', ['sku' => 'FLT-0921']);
    }

    public function test_a_rejected_product_leaves_no_stored_file_behind(): void
    {
        $before = $this->storedImageCount();

        // The image is valid, but the SKU collides, so the whole submission fails.
        Product::factory()->create(['sku' => 'FLT-0921', 'category_id' => $this->category->id]);

        $this->post(route('inventory.products.store'), $this->payload([
            'image' => UploadedFile::fake()->image('filter.png'),
        ]))->assertSessionHasErrors('sku');

        $this->assertSame($before, $this->storedImageCount(), 'A failed creation left an orphaned file on disk.');
    }

    // ──────────────────────────────── validation & redisplay ────────────────────────────────────

    public function test_each_missing_or_invalid_field_reports_against_itself(): void
    {
        $this->post(route('inventory.products.store'), [])
            ->assertSessionHasErrors(['name', 'sku', 'category_id', 'selling_price', 'cost_price', 'initial_stock', 'reorder_level', 'unit']);

        $this->post(route('inventory.products.store'), $this->payload([
            'selling_price' => 'free',
            'cost_price' => '-5',
            'initial_stock' => '-1',
            'reorder_level' => 'many',
            'category_id' => 999999,
            'sku' => 'lower case sku!',
        ]))->assertSessionHasErrors(['selling_price', 'cost_price', 'initial_stock', 'reorder_level', 'category_id', 'sku']);
    }

    public function test_an_inactive_category_cannot_be_selected(): void
    {
        $inactive = ProductCategory::factory()->create(['is_active' => false, 'created_by' => $this->admin]);

        $this->post(route('inventory.products.store'), $this->payload(['category_id' => $inactive->id]))
            ->assertSessionHasErrors('category_id');
    }

    public function test_the_form_redisplays_what_was_typed_and_shows_the_error_beside_the_field(): void
    {
        $this->post(route('inventory.products.store'), $this->payload([
            'sku' => 'bad sku!',
            'name' => 'Camry Oil Filter',
        ]))->assertSessionHasErrors('sku');

        $this->from(route('inventory.products.create'))
            ->followingRedirects()
            ->post(route('inventory.products.store'), $this->payload(['sku' => 'bad sku!', 'name' => 'Camry Oil Filter']))
            ->assertOk()
            ->assertSee('Camry Oil Filter')
            ->assertSee('id="sku-error"', false)
            ->assertSee('aria-invalid="true"', false);
    }

    public function test_an_error_belonging_to_no_visible_field_is_still_surfaced(): void
    {
        // `current_stock` is prohibited and has no control on the form, so it has nowhere to render
        // beside a field; it must still reach the user rather than vanish.
        $this->from(route('inventory.products.create'))
            ->followingRedirects()
            ->post(route('inventory.products.store'), $this->payload(['current_stock' => '999']))
            ->assertOk()
            ->assertSee('We could not save this')
            ->assertSee('current stock');
    }

    public function test_a_field_level_error_is_not_also_repeated_in_a_summary(): void
    {
        $this->from(route('inventory.products.create'))
            ->followingRedirects()
            ->post(route('inventory.products.store'), $this->payload(['name' => '']))
            ->assertOk()
            ->assertSee('id="name-error"', false)
            ->assertDontSee('We could not save this');
    }

    public function test_unit_and_description_are_not_on_this_screen_and_unit_still_saves_correctly(): void
    {
        $this->get(route('inventory.products.create'))
            ->assertOk()
            // Neither field is visible, nor is there a disclosure hiding them: Unit travels as a
            // fixed hidden default and Description simply is not part of this screen.
            ->assertDontSee('>Unit<', false)
            ->assertDontSee('>Description<', false)
            ->assertDontSee('More details')
            ->assertDontSee("Recorded as the product's first, immutable stock movement.");

        $this->post(route('inventory.products.store'), $this->payload())->assertRedirect();
        $product = Product::query()->where('sku', 'FLT-0921')->firstOrFail();
        $this->assertSame(ProductUnit::Piece, $product->unit);
        $this->assertNull($product->description);
    }

    public function test_a_tampered_unit_value_still_fails_server_side_validation(): void
    {
        // The visible form can no longer submit an invalid unit, but the field is still a real
        // input on the wire, so a forged request must still be rejected by the request class.
        $this->from(route('inventory.products.create'))
            ->followingRedirects()
            ->post(route('inventory.products.store'), $this->payload(['unit' => 'furlong']))
            ->assertOk()
            ->assertSee('We could not save this')
            ->assertSee('unit');
    }

    // ──────────────────────────────────── the action bar ────────────────────────────────────────

    public function test_save_product_lands_on_the_inventory_list_with_a_confirmation(): void
    {
        $this->post(route('inventory.products.store'), $this->payload())
            ->assertRedirect(route('inventory.index'))
            ->assertSessionHas('status', 'Toyota Camry Oil Filter added to inventory.');
    }

    public function test_save_and_add_another_returns_to_a_fresh_form_and_cannot_duplicate_on_refresh(): void
    {
        $response = $this->post(route('inventory.products.store'), $this->payload(['save_and_add_another' => '1']));

        // A redirect, not a rendered page, so refreshing re-issues the GET rather than the POST.
        $response->assertRedirect(route('inventory.products.create'))->assertSessionHas('status');
        $this->assertSame(1, Product::query()->where('sku', 'FLT-0921')->count());

        // Refreshing the destination creates nothing and offers an empty form.
        $this->get(route('inventory.products.create'))->assertOk()->assertDontSee('value="FLT-0921"', false);
        $this->assertSame(1, Product::query()->where('sku', 'FLT-0921')->count());

        // The next product saves normally.
        $this->post(route('inventory.products.store'), $this->payload(['sku' => 'FLT-0922', 'save_and_add_another' => '1']))
            ->assertRedirect(route('inventory.products.create'));
        $this->assertSame(2, Product::query()->whereIn('sku', ['FLT-0921', 'FLT-0922'])->count());
    }

    // ───────────────────────────────────── category UX ─────────────────────────────────────────

    public function test_the_category_field_offers_active_categories_only(): void
    {
        $active = ProductCategory::factory()->create(['name' => 'Filters Active', 'is_active' => true, 'created_by' => $this->admin]);
        $inactive = ProductCategory::factory()->create(['name' => 'Retired Parts', 'is_active' => false, 'created_by' => $this->admin]);

        $this->get(route('inventory.products.create'))
            ->assertOk()
            ->assertSee('Filters Active')
            ->assertDontSee('Retired Parts');

        $this->post(route('inventory.products.store'), $this->payload(['category_id' => $active->id]))->assertRedirect();
        $this->assertSame($active->id, Product::query()->where('sku', 'FLT-0921')->firstOrFail()->category_id);
        $this->assertNotNull($inactive->id);
    }

    public function test_the_form_links_to_the_categories_page_for_users_who_may_manage_them(): void
    {
        $this->get(route('inventory.products.create'))
            ->assertOk()
            ->assertSee('Manage categories')
            ->assertSee(route('inventory.categories.index'), false);

        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $this->actingAs($manager)->get(route('inventory.products.create'))->assertOk()->assertSee('Manage categories');

        // A Sales Representative cannot reach the screen at all, so there is nothing to hide.
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $this->actingAs($rep)->get(route('inventory.products.create'))->assertForbidden();
    }

    public function test_the_empty_category_state_points_at_the_page_that_creates_one(): void
    {
        ProductCategory::query()->whereKey($this->category->id)->update(['is_active' => false]);

        $this->get(route('inventory.products.create'))
            ->assertOk()
            ->assertSee('No active categories yet')
            ->assertSee(route('inventory.categories.create'), false);
    }

    /** Counts files currently in the product image directory. */
    private function storedImageCount(): int
    {
        return count(app(ImageStore::class)->disk()->files(ImageStore::PRODUCTS));
    }

    private function track(Product $product): Product
    {
        if ($product->image_path !== null) {
            $this->written[] = $product->image_path;
        }

        return $product;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'category_id' => $this->category->id,
            'name' => 'Toyota Camry Oil Filter',
            'sku' => 'FLT-0921',
            'selling_price' => '4500.00',
            'cost_price' => '3000.00',
            'initial_stock' => '0',
            'reorder_level' => '5',
            'unit' => 'piece',
        ], $overrides);
    }
}
