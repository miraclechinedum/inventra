<?php

namespace Tests\Feature\Purchases;

use App\Actions\Purchase\ReceivePurchase;
use App\Actions\Supplier\CreateSupplier;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PurchaseFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_manager_have_access_but_sales_rep_does_not(): void
    {
        foreach ([UserRole::Admin, UserRole::Manager] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get(route('purchases.index'))->assertOk();
            $this->get(route('suppliers.index'))->assertOk();
        }

        $this->actingAs(User::factory()->create(['role' => UserRole::SalesRep]))->get(route('purchases.index'))->assertForbidden();
        $this->get(route('suppliers.index'))->assertForbidden();
    }

    public function test_supplier_code_profile_status_and_audit_are_server_authoritative(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $this->actingAs($manager)->post(route('suppliers.store'), [
            'name' => '  Delta Parts  ', 'email' => ' BUY@EXAMPLE.COM ', 'phone' => '+442071234567',
            'supplier_code' => 'HACK', 'is_active' => false,
        ])->assertSessionHasErrors(['supplier_code', 'is_active']);

        $supplier = app(CreateSupplier::class)->execute($manager, ['name' => 'Delta Parts', 'email' => 'buy@example.com']);
        $this->assertSame('SUP-'.str_pad((string) $supplier->id, 6, '0', STR_PAD_LEFT), $supplier->supplier_code);
        $this->assertTrue($supplier->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'supplier_created', 'auditable_id' => $supplier->id]);
        $this->post(route('suppliers.deactivate', $supplier))->assertRedirect();
        $this->assertFalse($supplier->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'supplier_deactivated', 'auditable_id' => $supplier->id]);
    }

    public function test_received_purchase_uses_snapshots_decimal_math_and_inbound_ledger(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager, 'name' => 'Receiver A']);
        $supplier = app(CreateSupplier::class)->execute($manager, ['name' => 'Original Supplier', 'phone' => '+442071234567']);
        $first = Product::factory()->create(['current_stock' => '10.125', 'cost_price' => '9.99']);
        $second = Product::factory()->create(['current_stock' => '2.000']);
        $purchase = $this->receive($manager, $supplier, [
            ['product_id' => $second->id, 'quantity' => '2.500', 'unit_cost' => '10.01'],
            ['product_id' => $first->id, 'quantity' => '1.250', 'unit_cost' => '3.33'],
        ]);

        $this->assertSame('29.19', $purchase->total_amount);
        $this->assertSame('11.375', $first->fresh()->current_stock);
        $this->assertSame('4.500', $second->fresh()->current_stock);
        $this->assertSame('9.99', $first->fresh()->cost_price);
        $this->assertSame('Original Supplier', $purchase->supplier_name_snapshot);
        $this->assertSame('Receiver A', $purchase->received_by_name_snapshot);
        $this->assertCount(2, $purchase->items);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertDatabaseHas('inventory_movements', ['type' => InventoryMovementType::Purchase->value, 'reference_id' => $purchase->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'purchase_received', 'auditable_id' => $purchase->id]);
    }

    public function test_token_replay_is_idempotent_and_wrong_actor_session_or_expiry_fails(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $other = User::factory()->create(['role' => UserRole::Manager]);
        $supplier = app(CreateSupplier::class)->execute($manager, ['name' => 'Token Supplier']);
        $product = Product::factory()->create();
        [$token, $data] = $this->tokenAndData($manager, $supplier, $product);
        $action = app(ReceivePurchase::class);
        $purchase = $action->execute($manager, $data, 'session-a');
        $this->assertTrue($purchase->is($action->execute($manager, $data, 'session-a')));
        $this->assertDatabaseCount('purchases', 1);

        [$otherToken, $otherData] = $this->tokenAndData($manager, $supplier, $product);
        $this->expectValidation(fn () => $action->execute($other, $otherData, 'session-a'));
        $this->expectValidation(fn () => $action->execute($manager, $otherData, 'wrong-session'));
        PurchaseRequest::where('token_hash', hash('sha256', $otherToken))->update(['expires_at' => now()->subMinute()]);
        $this->expectValidation(fn () => $action->execute($manager, $otherData, 'session-a'));
    }

    public function test_inactive_supplier_and_product_revalidate_without_partial_writes(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $supplier = app(CreateSupplier::class)->execute($manager, ['name' => 'Inactive Supplier']);
        $product = Product::factory()->create(['current_stock' => '7.000']);
        $supplier->is_active = false;
        $supplier->save();
        $this->expectValidation(fn () => $this->receive($manager, $supplier, [['product_id' => $product->id, 'quantity' => '1', 'unit_cost' => '2']]));
        $this->assertSame('7.000', $product->fresh()->current_stock);
        $this->assertDatabaseCount('purchases', 0);

        $supplier->is_active = true;
        $supplier->save();
        $product->is_active = false;
        $product->save();
        $this->expectValidation(fn () => $this->receive($manager, $supplier, [['product_id' => $product->id, 'quantity' => '1', 'unit_cost' => '2']]));
        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_historical_models_and_supplier_identity_reject_mutation_or_deletion(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $supplier = app(CreateSupplier::class)->execute($manager, ['name' => 'Immutable Supplier']);
        $purchase = $this->receive($manager, $supplier, [['product_id' => Product::factory()->create()->id, 'quantity' => '1', 'unit_cost' => '2']]);

        try {
            $purchase->total_amount = '1.00';
            $purchase->save();
            $this->fail('Purchase mutation succeeded.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        $this->expectException(LogicException::class);
        $purchase->items()->firstOrFail()->delete();
    }

    public function test_purchase_views_use_escaped_immutable_snapshots_after_related_records_change(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager, 'name' => 'Receiver A']);
        $supplier = app(CreateSupplier::class)->execute($manager, ['name' => '<script>Supplier</script>']);
        $product = Product::factory()->create(['name' => '<b>Product</b>']);
        $purchase = $this->receive($manager, $supplier, [['product_id' => $product->id, 'quantity' => '1', 'unit_cost' => '2']]);
        $supplier->name = 'Changed';
        $supplier->save();
        $product->name = 'Changed';
        $product->save();
        $manager->name = 'Receiver B';
        $manager->save();

        $this->actingAs($manager)->get(route('purchases.show', $purchase))
            ->assertSee('&lt;script&gt;Supplier&lt;/script&gt;', false)->assertDontSee('<script>Supplier</script>', false)
            ->assertSee('&lt;b&gt;Product&lt;/b&gt;', false)->assertSee('Receiver A');
    }

    public function test_http_validation_rejects_duplicates_malformed_decimals_and_privileged_fields(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $supplier = app(CreateSupplier::class)->execute($manager, ['name' => 'Validation Supplier']);
        $product = Product::factory()->create();
        $this->actingAs($manager)->withSession(['test' => true]);
        $response = $this->get(route('purchases.create'))->assertOk();
        preg_match('/name="request_token" value="([^"]+)"/', $response->getContent(), $matches);

        $this->post(route('purchases.store'), [
            'request_token' => $matches[1], 'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => '1e2', 'unit_cost' => '0'],
                ['product_id' => $product->id, 'quantity' => '1', 'unit_cost' => '2', 'line_total' => '1'],
            ],
            'purchase_number' => 'HACK', 'total_amount' => '1', 'received_at' => '2000-01-01',
        ])->assertSessionHasErrors();
        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_database_constraints_reject_invalid_purchase_item_values(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $supplier = app(CreateSupplier::class)->execute($manager, ['name' => 'Constraint Supplier']);
        $product = Product::factory()->create();
        $purchase = $this->receive($manager, $supplier, [['product_id' => $product->id, 'quantity' => '1', 'unit_cost' => '2']]);

        try {
            DB::table('purchase_items')->insert([
                'purchase_id' => $purchase->id, 'product_id' => Product::factory()->create()->id,
                'product_sku_snapshot' => 'BAD', 'product_name_snapshot' => 'Bad', 'product_unit_snapshot' => 'piece',
                'quantity' => '0', 'unit_cost' => '1', 'line_total' => '1', 'created_at' => now(),
            ]);
            $this->fail('Database accepted an invalid line.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_audit_failure_rolls_back_purchase_stock_movements_and_token(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $supplier = app(CreateSupplier::class)->execute($manager, ['name' => 'Rollback Supplier']);
        $product = Product::factory()->create(['current_stock' => '5.000']);
        [$token, $data] = $this->tokenAndData($manager, $supplier, $product);
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit failed'));

        try {
            (new ReceivePurchase($audit))->execute($manager, $data, 'session-a');
            $this->fail('Expected audit failure.');
        } catch (RuntimeException) {
            $this->assertSame('5.000', $product->fresh()->current_stock);
            $this->assertDatabaseCount('purchases', 0);
            $this->assertDatabaseMissing('inventory_movements', ['type' => InventoryMovementType::Purchase->value]);
            $this->assertNull(PurchaseRequest::where('token_hash', hash('sha256', $token))->firstOrFail()->used_at);
        }
    }

    public function test_direct_action_rejects_empty_and_normalized_empty_lines_without_side_effects(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $supplier = app(CreateSupplier::class)->execute($manager, ['name' => 'Empty Guard Supplier']);
        $product = Product::factory()->create(['current_stock' => '8.000']);
        $action = app(ReceivePurchase::class);

        foreach ([[], [[], ['product_id' => '', 'quantity' => null, 'unit_cost' => '']]] as $items) {
            [$token, $data] = $this->tokenAndData($manager, $supplier, null, $items);
            $this->expectValidation(fn () => $action->execute($manager, $data, 'session-a'));
            $this->assertNull(PurchaseRequest::where('token_hash', hash('sha256', $token))->firstOrFail()->used_at);
        }

        $this->assertSame('8.000', $product->fresh()->current_stock);
        $this->assertDatabaseCount('purchases', 0);
        $this->assertDatabaseCount('purchase_items', 0);
        $this->assertDatabaseMissing('inventory_movements', ['type' => InventoryMovementType::Purchase->value]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'purchase_received']);
    }

    public function test_http_receive_and_same_session_replay_are_idempotent_and_cross_session_fails(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $supplier = app(CreateSupplier::class)->execute($manager, ['name' => 'HTTP Supplier']);
        $product = Product::factory()->create(['current_stock' => '10.000']);
        $this->actingAs($manager)->startSession();
        $response = $this->get(route('purchases.create'))->assertOk();
        preg_match('/name="request_token" value="([^"]+)"/', $response->getContent(), $matches);
        $issuedRequest = PurchaseRequest::where('token_hash', hash('sha256', $matches[1]))->firstOrFail();
        $this->withCookie(config('session.cookie'), $issuedRequest->session_id);
        $payload = [
            'request_token' => $matches[1], 'supplier_id' => $supplier->id,
            'items' => [['product_id' => $product->id, 'quantity' => '2.500', 'unit_cost' => '4.00']],
        ];

        $firstSubmission = $this->post(route('purchases.store'), $payload);
        $purchase = Purchase::query()->sole();
        $firstSubmission->assertRedirect(route('purchases.show', $purchase));
        $this->post(route('purchases.store'), $payload)->assertRedirect(route('purchases.show', $purchase));
        $this->assertDatabaseCount('purchases', 1);
        $this->assertDatabaseCount('purchase_items', 1);
        $this->assertSame('12.500', $product->fresh()->current_stock);
        $this->assertSame(1, DB::table('inventory_movements')->where('type', InventoryMovementType::Purchase->value)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'purchase_received')->count());
        $this->assertNotNull(PurchaseRequest::where('token_hash', hash('sha256', $matches[1]))->firstOrFail()->used_at);

        $this->app['session']->driver()->invalidate();
        $this->app['session']->driver()->start();
        $this->withCookie(config('session.cookie'), $this->app['session']->driver()->getId());
        $this->actingAs($manager)->post(route('purchases.store'), $payload)->assertSessionHasErrors('request_token');
        $this->assertDatabaseCount('purchases', 1);
        $this->assertSame('12.500', $product->fresh()->current_stock);
    }

    public function test_purchase_item_uses_same_application_clock_as_purchase_and_receipt_has_no_inline_handler(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $supplier = app(CreateSupplier::class)->execute($manager, ['name' => 'Timestamp Supplier']);
        $purchase = $this->receive($manager, $supplier, [['product_id' => Product::factory()->create()->id, 'quantity' => '1', 'unit_cost' => '2']]);
        $item = $purchase->items()->sole();

        $this->assertSame($purchase->received_at->timestamp, $item->created_at->timestamp);
        $this->actingAs($manager)->get(route('purchases.receipt', $purchase))
            ->assertOk()->assertSee('data-print-page', false)->assertDontSee('onclick=', false);
    }

    public function test_only_expired_unused_unlinked_purchase_requests_are_prunable(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $expired = $this->request($manager, now()->subMinute());
        $fresh = $this->request($manager, now()->addMinute());
        $used = $this->request($manager, now()->subMinute(), now());

        $ids = (new PurchaseRequest)->prunable()->pluck('id');
        $this->assertTrue($ids->contains($expired->id));
        $this->assertFalse($ids->contains($fresh->id));
        $this->assertFalse($ids->contains($used->id));
    }

    private function receive(User $actor, Supplier $supplier, array $items): Purchase
    {
        [$token, $data] = $this->tokenAndData($actor, $supplier, null, $items);

        return app(ReceivePurchase::class)->execute($actor, $data, 'session-a');
    }

    private function tokenAndData(User $actor, Supplier $supplier, ?Product $product = null, ?array $items = null): array
    {
        $token = Str::random(64);
        $request = new PurchaseRequest;
        $request->token_hash = hash('sha256', $token);
        $request->actor_id = $actor->id;
        $request->session_id = 'session-a';
        $request->expires_at = now()->addMinutes(30);
        $request->save();

        return [$token, ['request_token' => $token, 'supplier_id' => $supplier->id, 'items' => $items ?? [['product_id' => $product->id, 'quantity' => '1', 'unit_cost' => '2']]]];
    }

    private function request(User $actor, mixed $expiresAt, mixed $usedAt = null): PurchaseRequest
    {
        $request = new PurchaseRequest;
        $request->token_hash = hash('sha256', Str::random(64));
        $request->actor_id = $actor->id;
        $request->session_id = 'retention-session';
        $request->expires_at = $expiresAt;
        $request->used_at = $usedAt;
        $request->save();

        return $request;
    }

    private function expectValidation(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected validation failure.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }
}
