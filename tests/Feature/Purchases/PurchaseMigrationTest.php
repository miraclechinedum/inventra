<?php

namespace Tests\Feature\Purchases;

use App\Actions\Purchase\ReceivePurchase;
use App\Actions\Supplier\CreateSupplier;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PurchaseMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_empty_procurement_tables_can_roll_back_and_reapply(): void
    {
        $migration = $this->migration();

        try {
            $migration->down();
            $this->assertFalse(Schema::hasTable('suppliers'));
            $this->assertFalse(Schema::hasTable('purchases'));
        } finally {
            $migration->up();
        }
    }

    public function test_supplier_master_data_refuses_destructive_rollback_and_is_preserved(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Admin]);
        $supplier = app(CreateSupplier::class)->execute($actor, ['name' => 'Preserved Supplier']);

        try {
            $this->migration()->down();
            $this->fail('Rollback must fail while Supplier data exists.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Supplier or Purchase history exists', $exception->getMessage());
            $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'name' => 'Preserved Supplier']);
            $this->assertTrue(Schema::hasTable('purchases'));
        } finally {
            $this->cleanProcurement();
        }
    }

    public function test_purchase_history_refuses_destructive_rollback_and_is_preserved(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Manager]);
        $supplier = app(CreateSupplier::class)->execute($actor, ['name' => 'Historical Supplier']);
        $product = Product::factory()->create(['current_stock' => '10.000']);
        $token = Str::random(64);
        $request = new PurchaseRequest;
        $request->token_hash = hash('sha256', $token);
        $request->actor_id = $actor->id;
        $request->session_id = 'migration-session';
        $request->expires_at = now()->addMinute();
        $request->save();
        $purchase = app(ReceivePurchase::class)->execute($actor, [
            'request_token' => $token,
            'supplier_id' => $supplier->id,
            'items' => [['product_id' => $product->id, 'quantity' => '2', 'unit_cost' => '3']],
        ], 'migration-session');

        try {
            $this->migration()->down();
            $this->fail('Rollback must fail while Purchase history exists.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('purchases', ['id' => $purchase->id]);
            $this->assertDatabaseHas('purchase_items', ['purchase_id' => $purchase->id]);
            $this->assertDatabaseHas('inventory_movements', ['reference_id' => $purchase->id, 'type' => 'purchase']);
            $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);
        } finally {
            $this->cleanProcurement();
        }
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_09_04_020000_create_suppliers_and_purchases.php');
    }

    private function cleanProcurement(): void
    {
        DB::table('purchase_requests')->delete();
        DB::table('inventory_movements')->where('type', 'purchase')->delete();
        DB::table('purchase_items')->delete();
        DB::table('purchases')->delete();
        DB::table('audit_logs')->whereIn('action', [
            'supplier_created', 'supplier_updated', 'supplier_activated', 'supplier_deactivated', 'purchase_received',
        ])->delete();
        DB::table('suppliers')->delete();
    }
}
