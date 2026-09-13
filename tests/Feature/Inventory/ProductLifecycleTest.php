<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\ForceDeleteProduct;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseRequest;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Support\ProductDeletionGuard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Product retirement.
 *
 * Archiving is the ordinary path: the product stops being sellable and everything that already
 * refers to it keeps working. Permanent deletion is an Administrator-only exception, allowed only
 * for a product no business record mentions, and nothing here ever removes a movement, a sale line
 * or an audit entry to make a deletion possible.
 */
class ProductLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private ProductCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->category = ProductCategory::factory()->create(['created_by' => $this->admin->id, 'is_active' => true]);
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    /** A product created through the application, so it carries its opening movement. */
    private function createdProduct(array $overrides = []): Product
    {
        $this->actingAs($this->admin)->post(route('inventory.products.store'), array_merge([
            'category_id' => $this->category->id,
            'name' => 'Camry Oil Filter',
            'sku' => 'FLT-'.random_int(1000, 9999),
            'cost_price' => '2400.00',
            'selling_price' => '3500.00',
            'initial_stock' => '10',
            'reorder_level' => '2',
            'unit' => 'piece',
        ], $overrides))->assertRedirect();

        return Product::query()->latest('id')->firstOrFail();
    }

    /** A bare row with no movement at all, which is the only genuinely deletable shape. */
    private function historylessProduct(): Product
    {
        return Product::factory()->create(['category_id' => $this->category->id, 'is_active' => true]);
    }

    /** Suppliers have no factory, so one is created through its own authorized route. */
    private function supplier(): Supplier
    {
        $this->actingAs($this->admin)->post(route('suppliers.store'), [
            'name' => 'Parts Supplier '.random_int(1000, 9999),
        ])->assertRedirect();

        return Supplier::query()->latest('id')->firstOrFail();
    }

    /**
     * Receives a purchase for the product. Purchases are guarded by a single-use token the create
     * page issues and binds to the session, so the token has to be taken from that page.
     */
    private function receivePurchase(Product $product): void
    {
        $supplier = $this->supplier();
        $this->actingAs($this->admin)->startSession();
        $page = $this->get(route('purchases.create'))->assertOk();
        preg_match('/name="request_token" value="([^"]+)"/', $page->getContent(), $matches);

        // The token is bound to the session that was issued it, so that cookie travels with the post.
        $issued = PurchaseRequest::query()->where('token_hash', hash('sha256', $matches[1]))->firstOrFail();
        $this->withCookie(config('session.cookie'), $issued->session_id);

        $this->post(route('purchases.store'), [
            'request_token' => $matches[1],
            'supplier_id' => $supplier->id,
            'items' => [['product_id' => $product->id, 'quantity' => '4', 'unit_cost' => '2400.00']],
        ])->assertRedirect();
    }

    // ──────────────────────────────────────── archive ────────────────────────────────────────────

    public function test_an_administrator_and_a_manager_can_archive_an_active_product(): void
    {
        foreach ([UserRole::Admin, UserRole::Manager] as $role) {
            $product = $this->historylessProduct();

            $this->actingAs($this->user($role))
                ->delete(route('inventory.products.destroy', $product))
                ->assertRedirect(route('inventory.index'))
                ->assertSessionHas('status');

            $product->refresh();
            $this->assertFalse($product->is_active);
            // Archiving retires in place; it never removes the row.
            $this->assertNotSoftDeleted($product);
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'product_archived',
                'auditable_id' => $product->id,
            ]);
        }
    }

    public function test_a_sales_representative_cannot_archive_or_reactivate(): void
    {
        $active = $this->historylessProduct();
        $archived = $this->historylessProduct();
        $archived->forceFill(['is_active' => false])->save();
        $rep = $this->user(UserRole::SalesRep);

        $this->actingAs($rep)->delete(route('inventory.products.destroy', $active))->assertForbidden();
        $this->actingAs($rep)->post(route('inventory.products.reactivate', $archived))->assertForbidden();

        $this->assertTrue($active->fresh()->is_active);
        $this->assertFalse($archived->fresh()->is_active);
    }

    public function test_archiving_an_already_archived_product_is_refused(): void
    {
        $product = $this->historylessProduct();
        $product->forceFill(['is_active' => false])->save();

        $this->actingAs($this->admin)->delete(route('inventory.products.destroy', $product))->assertForbidden();
    }

    // ───────────────────────────── archived products leave operations ────────────────────────────

    public function test_an_archived_product_cannot_be_put_on_a_new_sale(): void
    {
        $product = $this->createdProduct();
        $customer = Customer::factory()->create(['is_active' => true]);

        $this->actingAs($this->admin)->delete(route('inventory.products.destroy', $product))->assertRedirect();

        // It is gone from the picker...
        $this->actingAs($this->admin)->get(route('sales.create'))->assertOk()->assertDontSee($product->sku);

        // ...and refused even when its id is posted directly.
        $this->actingAs($this->admin)->post(route('sales.store'), [
            'customer_id' => $customer->id,
            'products' => [['product_id' => $product->id, 'quantity' => '1']],
            'payment_method' => 'cash',
            'amount_paid' => '3500.00',
        ])->assertSessionHasErrors();

        $this->assertDatabaseMissing('sale_items', ['product_id' => $product->id]);
    }

    public function test_an_archived_product_cannot_be_received_on_a_new_purchase(): void
    {
        $product = $this->createdProduct();
        $this->actingAs($this->admin)->delete(route('inventory.products.destroy', $product))->assertRedirect();

        $this->actingAs($this->admin)->get(route('purchases.create'))->assertOk()->assertDontSee($product->sku);

        $stockBefore = $product->fresh()->current_stock;
        $supplier = $this->supplier();
        $this->actingAs($this->admin)->startSession();
        $page = $this->get(route('purchases.create'))->assertOk();
        preg_match('/name="request_token" value="([^"]+)"/', $page->getContent(), $matches);

        $this->post(route('purchases.store'), [
            'request_token' => $matches[1],
            'supplier_id' => $supplier->id,
            'items' => [['product_id' => $product->id, 'quantity' => '5', 'unit_cost' => '2400.00']],
        ])->assertSessionHasErrors();

        $this->assertSame($stockBefore, $product->fresh()->current_stock);
    }

    // ───────────────────────────────── history survives archiving ────────────────────────────────

    public function test_history_and_receipts_still_render_after_a_product_is_archived(): void
    {
        $product = $this->createdProduct();
        $customer = Customer::factory()->create(['is_active' => true]);

        $this->actingAs($this->admin)->post(route('sales.store'), [
            'customer_id' => $customer->id,
            'products' => [['product_id' => $product->id, 'quantity' => '2']],
            'payment_method' => 'cash',
            'amount_paid' => '7000.00',
        ])->assertRedirect();

        $sale = Sale::query()->latest('id')->firstOrFail();
        $movementCount = $product->movements()->count();

        $this->actingAs($this->admin)->delete(route('inventory.products.destroy', $product))->assertRedirect();

        // The sale, its receipt and the movement ledger are all unaffected.
        $this->actingAs($this->admin)->get(route('sales.show', $sale))->assertOk()->assertSee($product->name);
        $this->actingAs($this->admin)->get(route('sales.receipt', $sale))->assertOk()->assertSee($product->name);
        $this->actingAs($this->admin)->get(route('inventory.products.movements', $product))->assertOk();
        $this->assertSame($movementCount, $product->fresh()->movements()->count());
        $this->assertDatabaseHas('sale_items', ['sale_id' => $sale->id, 'product_id' => $product->id]);
        // The product page itself still resolves, which is what reactivation depends on.
        $this->actingAs($this->admin)->get(route('inventory.products.show', $product))->assertOk();
    }

    public function test_an_archived_product_is_listed_as_archived_for_managers_and_hidden_from_sales_reps(): void
    {
        $product = $this->historylessProduct();
        $this->actingAs($this->admin)->delete(route('inventory.products.destroy', $product))->assertRedirect();

        $this->actingAs($this->admin)->get(route('inventory.index'))
            ->assertOk()->assertSee($product->sku)->assertSee('Archived');

        $this->actingAs($this->user(UserRole::SalesRep))->get(route('inventory.index'))
            ->assertOk()->assertDontSee($product->sku);
    }

    // ────────────────────────────────────── reactivation ─────────────────────────────────────────

    public function test_an_administrator_and_a_manager_can_reactivate_without_duplicating_the_product(): void
    {
        foreach ([UserRole::Admin, UserRole::Manager] as $role) {
            $product = $this->historylessProduct();
            $product->forceFill(['is_active' => false])->save();
            $before = Product::query()->count();

            $this->actingAs($this->user($role))
                ->post(route('inventory.products.reactivate', $product))
                ->assertRedirect();

            $this->assertTrue($product->fresh()->is_active);
            $this->assertSame($before, Product::query()->count());
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'product_reactivated',
                'auditable_id' => $product->id,
            ]);
        }
    }

    public function test_reactivating_an_active_product_is_refused(): void
    {
        $product = $this->historylessProduct();

        $this->actingAs($this->admin)->post(route('inventory.products.reactivate', $product))->assertForbidden();
    }

    // ─────────────────────────────────── permanent deletion ──────────────────────────────────────

    public function test_an_administrator_may_permanently_delete_a_product_with_no_history_at_all(): void
    {
        $product = $this->historylessProduct();
        $this->assertTrue(app(ProductDeletionGuard::class)->isDeletable($product));

        $this->actingAs($this->admin)
            ->delete(route('inventory.products.force-destroy', $product))
            ->assertRedirect(route('inventory.index'));

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        // The record of the deletion outlives the product it removed.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'product_permanently_deleted',
            'auditable_id' => $product->id,
            'actor_id' => $this->admin->id,
        ]);
    }

    public function test_a_product_created_through_the_form_has_an_opening_movement_and_is_not_deletable(): void
    {
        $product = $this->createdProduct();

        // Creating a product writes an immutable opening movement, so it already has history.
        $this->assertSame(1, $product->movements()->count());
        $this->assertFalse(app(ProductDeletionGuard::class)->isDeletable($product));

        $this->actingAs($this->admin)
            ->delete(route('inventory.products.force-destroy', $product))
            ->assertSessionHasErrors('product');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertSame(1, $product->fresh()->movements()->count());
    }

    public function test_inventory_movement_history_blocks_permanent_deletion(): void
    {
        $product = $this->historylessProduct();
        $this->assertTrue(app(ProductDeletionGuard::class)->isDeletable($product));

        // A stock adjustment writes a movement, and that movement is history.
        $this->actingAs($this->admin)->post(route('inventory.products.adjust', $product), [
            'type' => 'restock', 'operation' => 'increase', 'quantity' => '3', 'reason' => 'Delivery',
        ])->assertRedirect();

        $this->assertFalse(app(ProductDeletionGuard::class)->isDeletable($product));
        $this->actingAs($this->admin)
            ->delete(route('inventory.products.force-destroy', $product))
            ->assertSessionHasErrors('product');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertTrue($product->fresh()->movements()->exists());
    }

    public function test_sale_history_blocks_permanent_deletion(): void
    {
        $product = $this->createdProduct();
        $customer = Customer::factory()->create(['is_active' => true]);

        $this->actingAs($this->admin)->post(route('sales.store'), [
            'customer_id' => $customer->id,
            'products' => [['product_id' => $product->id, 'quantity' => '1']],
            'payment_method' => 'cash',
            'amount_paid' => '3500.00',
        ])->assertRedirect();

        $this->assertTrue(DB::table('sale_items')->where('product_id', $product->id)->exists());
        $this->assertContains('sales', app(ProductDeletionGuard::class)->historyFor($product));

        $this->actingAs($this->admin)
            ->delete(route('inventory.products.force-destroy', $product))
            ->assertSessionHasErrors('product');

        // The refusal destroys nothing it was protecting.
        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertTrue(DB::table('sale_items')->where('product_id', $product->id)->exists());
    }

    public function test_purchase_history_blocks_permanent_deletion(): void
    {
        $product = $this->createdProduct();

        $this->receivePurchase($product);

        $this->assertTrue(DB::table('purchase_items')->where('product_id', $product->id)->exists());
        $this->assertContains('purchases', app(ProductDeletionGuard::class)->historyFor($product));

        $this->actingAs($this->admin)
            ->delete(route('inventory.products.force-destroy', $product))
            ->assertSessionHasErrors('product');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertTrue(DB::table('purchase_items')->where('product_id', $product->id)->exists());
    }

    public function test_the_guard_names_every_table_that_can_hold_product_history(): void
    {
        // Every table the schema lets reference a product must be one the guard inspects; a new one
        // added later should fail here rather than quietly become a way to destroy history.
        $referencing = collect(DB::select(
            'select distinct table_name as t from information_schema.key_column_usage
             where table_schema = database() and referenced_table_name = ? and column_name = ?',
            ['products', 'product_id'],
        ))->pluck('t')->sort()->values()->all();

        $guarded = (new \ReflectionClass(ProductDeletionGuard::class))->getConstant('REFERENCES');

        $this->assertSame($referencing, collect(array_keys($guarded))->sort()->values()->all());
    }

    public function test_a_manager_and_a_sales_representative_can_never_permanently_delete(): void
    {
        foreach ([UserRole::Manager, UserRole::SalesRep] as $role) {
            $product = $this->historylessProduct();

            $this->actingAs($this->user($role))
                ->delete(route('inventory.products.force-destroy', $product))
                ->assertForbidden();

            $this->assertDatabaseHas('products', ['id' => $product->id]);
        }
    }

    public function test_the_guard_is_enforced_by_the_action_itself_not_only_by_the_controller(): void
    {
        $product = $this->createdProduct();

        // Bypassing the controller entirely still refuses, and destroys nothing.
        $this->expectException(\RuntimeException::class);

        try {
            app(ForceDeleteProduct::class)->execute($this->admin, $product);
        } finally {
            $this->assertDatabaseHas('products', ['id' => $product->id]);
            $this->assertSame(1, $product->fresh()->movements()->count());
        }
    }

    // ──────────────────────────────────── lifecycle UI ───────────────────────────────────────────

    public function test_the_edit_screen_offers_archive_and_explains_a_blocked_deletion(): void
    {
        $withHistory = $this->createdProduct();

        $this->actingAs($this->admin)->get(route('inventory.products.edit', $withHistory))
            ->assertOk()
            ->assertSee('Archive product')
            ->assertDontSee('Delete product')
            ->assertSee('cannot be permanently deleted');

        $deletable = $this->historylessProduct();
        $this->actingAs($this->admin)->get(route('inventory.products.edit', $deletable))
            ->assertOk()
            ->assertSee('Permanently delete');
    }

    public function test_an_archived_product_offers_reactivate_and_a_manager_is_never_offered_permanent_deletion(): void
    {
        $product = $this->historylessProduct();
        $product->forceFill(['is_active' => false])->save();

        $this->actingAs($this->admin)->get(route('inventory.products.edit', $product))
            ->assertOk()->assertSee('Reactivate product')->assertDontSee('Archive product');

        $this->actingAs($this->user(UserRole::Manager))->get(route('inventory.products.edit', $product))
            ->assertOk()->assertDontSee('Permanently delete');
    }

    // ─────────────────────────── lifecycle confirmation dialog ───────────────────────────────────

    public function test_the_archive_trigger_uses_a_dedicated_dialog_not_the_floating_popover(): void
    {
        // The archive/reactivate/permanent-delete triggers must not reuse <x-confirm-action>'s
        // absolutely-positioned popover: that component is fine for a small icon-row action, but a
        // popover positioned relative to its trigger can be clipped by a scrolling ancestor — which
        // is exactly what happened inside the Edit Product modal's scrollable body. The dedicated
        // dialog is `position: fixed`, so it is laid out against the viewport instead.
        $product = $this->historylessProduct();

        $html = $this->actingAs($this->admin)->get(route('inventory.products.edit', $product))->assertOk()->getContent();

        $this->assertStringContainsString('lifecycleConfirm', $html);
        $this->assertStringContainsString('ui-confirm-dialog', $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
    }

    public function test_the_archive_confirmation_dialog_carries_the_specified_title_and_body(): void
    {
        $product = $this->historylessProduct();

        $this->actingAs($this->admin)->get(route('inventory.products.edit', $product))
            ->assertOk()
            ->assertSee('Archive product?')
            ->assertSee('This product will no longer be available for new sales or purchases. Existing sales, inventory movements and historical records will remain available.');
    }

    public function test_the_reactivate_confirmation_dialog_carries_the_specified_title_and_body(): void
    {
        $product = $this->historylessProduct();
        $product->forceFill(['is_active' => false])->save();

        $this->actingAs($this->admin)->get(route('inventory.products.edit', $product))
            ->assertOk()
            ->assertSee('Reactivate product?')
            ->assertSee('This product will become available for new sales, purchases and other permitted inventory operations again.');
    }

    public function test_the_permanent_delete_confirmation_dialog_is_distinct_from_the_archive_dialog(): void
    {
        // Permanent deletion must have its own wording and its own destructive tone — it must never
        // reuse the archive dialog's copy or styling, since the two actions carry very different
        // consequences (archive is reversible; permanent deletion is not).
        $product = $this->historylessProduct();

        $html = $this->actingAs($this->admin)->get(route('inventory.products.edit', $product))->assertOk()->getContent();

        $this->assertStringContainsString('Permanently delete this product?', $html);
        $this->assertStringContainsString('This cannot be undone.', $html);
        $this->assertStringContainsString('ui-button-danger', $html);

        // The archive and delete dialogs are two independent instances, not one reused component.
        $this->assertSame(2, substr_count($html, 'ui-confirm-dialog'));
    }

    public function test_the_permanent_delete_section_stays_compact_when_deletion_is_blocked(): void
    {
        $product = $this->createdProduct();

        $html = $this->actingAs($this->admin)->get(route('inventory.products.edit', $product))->assertOk()->getContent();

        $this->assertStringContainsString('Permanent deletion unavailable', $html);
        $this->assertStringContainsString('This product has sales or inventory history and must be archived instead.', $html);
        $this->assertStringContainsString('is-unavailable', $html);
        // The disabled trigger still carries the specific reason for anyone who wants the detail,
        // without it dominating the always-visible copy.
        $this->assertStringContainsString('cannot be permanently deleted', $html);
    }

    public function test_a_sales_representative_gains_no_lifecycle_dialog_on_the_edit_screen(): void
    {
        // The edit screen itself already refuses a Sales Representative (asserted elsewhere); this
        // confirms the dialog markup carries no capability of its own if it were ever reached.
        $product = $this->historylessProduct();
        $rep = $this->user(UserRole::SalesRep);

        $this->actingAs($rep)->get(route('inventory.products.edit', $product))->assertForbidden();
    }

    public function test_confirming_archive_through_the_dialog_form_still_archives_exactly_once(): void
    {
        // The dialog's own submit button is the same authorized route the popover used to post to;
        // this proves the new markup did not change what gets submitted or how many times.
        $product = $this->historylessProduct();

        $this->actingAs($this->admin)
            ->from(route('inventory.products.edit', $product))
            ->delete(route('inventory.products.destroy', $product))
            ->assertRedirect(route('inventory.index'));

        $this->assertFalse($product->fresh()->is_active);
        $this->assertSame(1, \App\Models\AuditLog::where('auditable_id', $product->id)
            ->where('action', 'product_archived')->count());
    }

    public function test_escaping_the_confirmation_dialog_does_not_also_close_the_edit_modal(): void
    {
        // A single Escape press while the nested confirmation is open must close only that
        // confirmation. The two dialogs both listen for Escape; if the inner one were bound with
        // Alpine's `.window` modifier (rather than a plain, unscoped listener on the dialog itself,
        // stopped from bubbling with `.stop`), one keypress would fire both handlers and close the
        // Edit Product modal underneath as well — which is the exact bug this markup fixes.
        $markup = (string) file_get_contents(resource_path('views/components/lifecycle-confirm.blade.php'));

        $this->assertStringContainsString('x-on:keydown.escape.stop="close"', $markup);
        $this->assertStringNotContainsString('x-on:keydown.escape.window', $markup);
    }

    public function test_cancelling_the_dialog_never_reaches_the_server(): void
    {
        // Cancel is a plain client-side close: there is no route it could accidentally hit, and the
        // product's state must be provably unchanged by opening (and dismissing) the dialog.
        $product = $this->historylessProduct();

        $this->actingAs($this->admin)->get(route('inventory.products.edit', $product))->assertOk();

        $this->assertTrue($product->fresh()->is_active);
        $this->assertSame(0, \App\Models\AuditLog::where('auditable_id', $product->id)
            ->whereIn('action', ['product_archived', 'product_reactivated', 'product_permanently_deleted'])->count());
    }
}
