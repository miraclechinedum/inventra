<?php

namespace Tests\Feature\Inventory;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use App\Support\ImageStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Product photographs. The properties that matter are that the file never becomes web-reachable,
 * that the server decides its name and type rather than the uploader, that only authorized users
 * can read or change it, and that replacing or removing one never leaves a product pointing at a
 * file that is not there.
 */
class ProductImageTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> paths written during a test, cleaned up afterwards */
    private array $written = [];

    protected function tearDown(): void
    {
        $store = app(ImageStore::class);

        foreach ($this->written as $path) {
            $store->delete($path);
        }

        $this->written = [];
        parent::tearDown();
    }

    private function manager(): User
    {
        return User::factory()->create(['role' => UserRole::Manager]);
    }

    private function png(string $name = 'photo.png', int $width = 400, int $height = 300): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    private function track(Product $product): Product
    {
        $product = $product->fresh();

        if ($product->image_path !== null) {
            $this->written[] = $product->image_path;
        }

        return $product;
    }

    // ───────────────────────────────────── upload & storage ─────────────────────────────────────

    public function test_a_manager_can_upload_a_photo_and_it_is_stored_privately(): void
    {
        $manager = $this->manager();
        $product = Product::factory()->create();

        $this->actingAs($manager)
            ->post(route('inventory.products.image.store', $product), ['image' => $this->png()])
            ->assertRedirect();

        $product = $this->track($product);
        $store = app(ImageStore::class);

        $this->assertNotNull($product->image_path);
        $this->assertTrue($store->exists($product->image_path));
        $this->assertStringStartsWith(ImageStore::PRODUCTS.'/', $product->image_path);

        // Stored under storage/app/private, which no web server maps to a URL.
        $this->assertStringContainsString('/storage/app/private/', $store->absolutePath($product->image_path));
    }

    public function test_the_server_names_the_file_and_ignores_the_uploaded_name(): void
    {
        $manager = $this->manager();
        $product = Product::factory()->create();

        $this->actingAs($manager)->post(route('inventory.products.image.store', $product), [
            'image' => $this->png('../../evil name; DROP TABLE products.png'),
        ])->assertRedirect();

        $product = $this->track($product);

        $this->assertMatchesRegularExpression(
            '#^'.ImageStore::PRODUCTS.'/[A-Za-z0-9]{40}\.png$#D',
            $product->image_path,
            'the stored path must be entirely server-generated',
        );
        $this->assertStringNotContainsString('evil', $product->image_path);
        $this->assertStringNotContainsString('..', $product->image_path);
    }

    public function test_a_disguised_non_image_is_rejected(): void
    {
        $manager = $this->manager();
        $product = Product::factory()->create();

        // A PHP payload wearing a .png name. Validation decodes the file, so the name is no help.
        $file = UploadedFile::fake()->createWithContent('payload.png', '<?php echo "pwned"; ?>');

        $this->actingAs($manager)
            ->post(route('inventory.products.image.store', $product), ['image' => $file])
            ->assertSessionHasErrors('image');

        $this->assertNull($product->fresh()->image_path);
    }

    public function test_an_svg_is_rejected_because_it_can_carry_script(): void
    {
        $manager = $this->manager();
        $product = Product::factory()->create();
        $svg = UploadedFile::fake()->createWithContent('logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->actingAs($manager)
            ->post(route('inventory.products.image.store', $product), ['image' => $svg])
            ->assertSessionHasErrors('image');

        $this->assertNull($product->fresh()->image_path);
    }

    public function test_an_oversized_image_is_rejected(): void
    {
        $manager = $this->manager();
        $product = Product::factory()->create();
        $tooBig = UploadedFile::fake()->image('huge.jpg', 500, 500)->size(3072);

        $this->actingAs($manager)
            ->post(route('inventory.products.image.store', $product), ['image' => $tooBig])
            ->assertSessionHasErrors('image');

        $this->assertNull($product->fresh()->image_path);
    }

    public function test_excessive_dimensions_are_rejected(): void
    {
        $manager = $this->manager();
        $product = Product::factory()->create();

        $this->actingAs($manager)->post(route('inventory.products.image.store', $product), [
            'image' => $this->png('wide.png', ImageStore::MAX_DIMENSION + 1, 10),
        ])->assertSessionHasErrors('image');

        $this->assertNull($product->fresh()->image_path);
    }

    // ─────────────────────────────────── replacement & removal ──────────────────────────────────

    public function test_replacing_a_photo_deletes_only_the_superseded_file(): void
    {
        $manager = $this->manager();
        $product = Product::factory()->create();
        $store = app(ImageStore::class);

        $this->actingAs($manager)->post(route('inventory.products.image.store', $product), ['image' => $this->png('first.png')]);
        $first = $product->fresh()->image_path;

        $this->actingAs($manager)->post(route('inventory.products.image.store', $product), ['image' => $this->png('second.png')]);
        $product = $this->track($product);
        $second = $product->image_path;

        $this->assertNotSame($first, $second);
        $this->assertFalse($store->exists($first), 'the replaced file must be cleaned up');
        $this->assertTrue($store->exists($second), 'the product must point at a file that exists');
    }

    public function test_removing_a_photo_clears_the_reference_and_the_file(): void
    {
        $manager = $this->manager();
        $product = Product::factory()->create();
        $store = app(ImageStore::class);

        $this->actingAs($manager)->post(route('inventory.products.image.store', $product), ['image' => $this->png()]);
        $path = $product->fresh()->image_path;

        $this->actingAs($manager)->delete(route('inventory.products.image.destroy', $product))->assertRedirect();

        $this->assertNull($product->fresh()->image_path);
        $this->assertFalse($store->exists($path));
    }

    public function test_removing_when_there_is_no_photo_is_harmless(): void
    {
        $manager = $this->manager();
        $product = Product::factory()->create();

        $this->actingAs($manager)->delete(route('inventory.products.image.destroy', $product))->assertRedirect();

        $this->assertNull($product->fresh()->image_path);
    }

    // ───────────────────────────────────────── serving ──────────────────────────────────────────

    public function test_the_photo_is_served_only_to_users_allowed_to_view_the_product(): void
    {
        $manager = $this->manager();
        $product = Product::factory()->create(['is_active' => true]);

        $this->actingAs($manager)->post(route('inventory.products.image.store', $product), ['image' => $this->png()]);
        $this->track($product);

        $url = route('inventory.products.image', $product);

        // A guest gets nothing.
        $this->post(route('logout'));
        $this->get($url)->assertRedirect(route('login'));

        // A Sales Rep may view an active product, so may see its photo.
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $response = $this->actingAs($rep)->get($url)->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl,
            'a photo only some users may see must never be publicly cacheable');
        $this->assertStringNotContainsString('public', $cacheControl);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_a_sales_rep_cannot_see_the_photo_of_an_inactive_product(): void
    {
        $manager = $this->manager();
        $product = Product::factory()->create(['is_active' => true]);
        $this->actingAs($manager)->post(route('inventory.products.image.store', $product), ['image' => $this->png()]);
        $this->track($product);

        $product->forceFill(['is_active' => false])->save();
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);

        $this->actingAs($rep)->get(route('inventory.products.image', $product))->assertForbidden();
    }

    public function test_a_product_without_a_photo_returns_not_found(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->manager())->get(route('inventory.products.image', $product))->assertNotFound();
    }

    public function test_a_missing_file_returns_not_found_rather_than_erroring(): void
    {
        $manager = $this->manager();
        $product = Product::factory()->create();
        $this->actingAs($manager)->post(route('inventory.products.image.store', $product), ['image' => $this->png()]);

        // The file disappears underneath the record, as a botched restore might do.
        app(ImageStore::class)->delete($product->fresh()->image_path);

        $this->actingAs($manager)->get(route('inventory.products.image', $product))->assertNotFound();
    }

    // ──────────────────────────────────── authorization ─────────────────────────────────────────

    public function test_a_sales_rep_cannot_upload_or_remove_a_photo(): void
    {
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $product = Product::factory()->create();

        $this->actingAs($rep)
            ->post(route('inventory.products.image.store', $product), ['image' => $this->png()])
            ->assertForbidden();
        $this->actingAs($rep)
            ->delete(route('inventory.products.image.destroy', $product))
            ->assertForbidden();

        $this->assertNull($product->fresh()->image_path);
    }

    // ──────────────────────────────────────── audit ─────────────────────────────────────────────

    public function test_every_photo_change_is_audited(): void
    {
        $manager = $this->manager();
        $product = Product::factory()->create();

        $this->actingAs($manager)->post(route('inventory.products.image.store', $product), ['image' => $this->png('a.png')]);
        $this->actingAs($manager)->post(route('inventory.products.image.store', $product), ['image' => $this->png('b.png')]);
        $this->track($product);
        $this->actingAs($manager)->delete(route('inventory.products.image.destroy', $product));

        foreach (['product_image_attached', 'product_image_replaced', 'product_image_removed'] as $action) {
            $log = AuditLog::query()->where('action', $action)->sole();
            $this->assertSame($manager->id, $log->actor_id, "{$action} must name the staff member");
        }

        // The trail must say which file, or it cannot answer "what changed?".
        $attached = AuditLog::query()->where('action', 'product_image_attached')->sole();
        $this->assertNull($attached->old_values['image_path']);
        $this->assertStringStartsWith(ImageStore::PRODUCTS.'/', $attached->new_values['image_path']);

        $replaced = AuditLog::query()->where('action', 'product_image_replaced')->sole();
        $this->assertNotSame($replaced->old_values['image_path'], $replaced->new_values['image_path']);

        $removed = AuditLog::query()->where('action', 'product_image_removed')->sole();
        $this->assertNotNull($removed->old_values['image_path']);
        $this->assertNull($removed->new_values['image_path']);
    }

    // ─────────────────────────────────── fallback placeholder ───────────────────────────────────

    public function test_pages_render_a_placeholder_when_there_is_no_photo(): void
    {
        $manager = $this->manager();
        $product = Product::factory()->create(['name' => 'Blue Ceramic Mug']);

        $show = $this->actingAs($manager)->get(route('inventory.products.show', $product))->assertOk()->getContent();
        $this->assertStringContainsString('No image for Blue Ceramic Mug', $show);
        // The upload form legitimately POSTs to the same URI, so assert no <img> renders it.
        $this->assertStringNotContainsString('<img src="'.route('inventory.products.image', $product), $show);

        $index = $this->actingAs($manager)->get(route('inventory.index'))->assertOk()->getContent();
        $this->assertStringContainsString('No image for Blue Ceramic Mug', $index);
    }

    public function test_pages_link_to_the_photo_once_one_exists(): void
    {
        $manager = $this->manager();
        $product = Product::factory()->create(['name' => 'Blue Ceramic Mug']);
        $this->actingAs($manager)->post(route('inventory.products.image.store', $product), ['image' => $this->png()]);
        $this->track($product);

        $show = $this->actingAs($manager)->get(route('inventory.products.show', $product))->assertOk()->getContent();
        $this->assertStringContainsString(route('inventory.products.image', $product), $show);
        $this->assertStringContainsString('Replace photo', $show);
        $this->assertStringContainsString('Remove photo', $show);
    }
}
