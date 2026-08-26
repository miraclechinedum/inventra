<?php

namespace Tests\Feature\Sale;

use App\Actions\Sale\CreateSale;
use App\Actions\Sale\VoidSale;
use App\Enums\InventoryMovementType;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SaleVoidAndSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_void_restores_stock_with_ledger_and_cannot_run_twice(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        [$admin, $sale, $product] = $this->completedSale();
        $this->actingAs($admin)->post(route('sales.void', $sale), ['reason' => 'Customer cancelled'])->assertRedirect();
        $sale->refresh();
        $this->assertSame(SaleStatus::Voided, $sale->status);
        $this->assertSame($admin->id, $sale->voided_by);
        $this->assertSame('10.000', $product->fresh()->current_stock);
        $this->assertSame(1, $product->movements()->where('type', InventoryMovementType::Sale)->count());
        $void = $product->movements()->where('type', InventoryMovementType::SaleVoid)->sole();
        $this->assertSame('2.000', $void->quantity_change);
        $this->assertSame($sale->id, $void->reference_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sale_voided', 'auditable_id' => $sale->id]);
        $saleLock = collect($queries)->first(fn (string $sql): bool => Str::contains(Str::lower($sql), 'from `sales`')
            && Str::contains(Str::lower($sql), 'for update'));
        $this->assertNotNull($saleLock);
        $this->post(route('sales.void', $sale), ['reason' => 'Again'])->assertSessionHasErrors('sale');
        $this->assertSame('10.000', $product->fresh()->current_stock);
        $this->assertSame(1, $product->movements()->where('type', InventoryMovementType::SaleVoid)->count());
    }

    public function test_void_audit_failure_rolls_back_status_stock_and_movement(): void
    {
        [$admin, $sale, $product] = $this->completedSale();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));

        try {
            (new VoidSale($audit))->execute($admin, $sale, 'Rollback');
            $this->fail('Void should roll back.');
        } catch (RuntimeException) {
            $this->assertSame(SaleStatus::Completed, $sale->fresh()->status);
            $this->assertSame('8.000', $product->fresh()->current_stock);
            $this->assertDatabaseMissing('inventory_movements', ['type' => 'sale_void', 'reference_id' => $sale->id]);
        }
    }

    public function test_void_restores_stock_to_an_archived_product(): void
    {
        [$admin, $sale, $product] = $this->completedSale();
        $product->delete();

        app(VoidSale::class)->execute($admin, $sale, 'Archived after sale');

        $this->assertSame('10.000', Product::withTrashed()->findOrFail($product->id)->current_stock);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'type' => InventoryMovementType::SaleVoid->value,
            'reference_id' => $sale->id,
        ]);
    }

    public function test_receipt_uses_snapshots_after_customer_and_product_changes(): void
    {
        [, $sale, $product, $customer] = $this->completedSale();
        $originalName = $sale->customer_name_snapshot;
        $originalPhone = $sale->customer_phone_snapshot;
        $itemName = $sale->items()->sole()->product_name_snapshot;
        $itemPrice = $sale->items()->sole()->unit_price;
        $customer->first_name = 'Changed';
        $customer->phone = '+2348099999999';
        $customer->save();
        $product->name = 'Changed Product';
        $product->selling_price = '1.00';
        $product->save();

        $response = $this->actingAs($sale->seller)->get(route('sales.receipt', $sale))->assertOk();
        $response->assertSee($originalName)->assertSee($originalPhone)->assertSee($itemName)->assertSee($itemPrice);
        $response->assertDontSee('Changed Product');
        $this->assertSame($originalName, $sale->fresh()->customer_name_snapshot);
    }

    public function test_receipt_uses_immutable_seller_name_snapshot(): void
    {
        $seller = User::factory()->create(['role' => UserRole::SalesRep, 'name' => 'Sales Rep A']);
        $viewer = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Reviewing Administrator']);
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['current_stock' => '10', 'selling_price' => '100']);
        $sale = app(CreateSale::class)->execute($seller, [
            'customer_id' => $customer->id,
            'products' => [['product_id' => $product->id, 'quantity' => '1']],
            'payment_method' => 'cash',
            'amount_paid' => '100',
            'notes' => null,
        ]);
        $seller->name = 'Sales Rep B';
        $seller->save();

        $this->actingAs($viewer)
            ->get(route('sales.index'))
            ->assertOk()
            ->assertSee('px-5 py-4">Sales Rep A</td>', false)
            ->assertDontSee('px-5 py-4">Sales Rep B</td>', false);

        foreach ([route('sales.show', $sale), route('sales.receipt', $sale)] as $route) {
            $this->get($route)->assertOk()->assertSee('Sales Rep A')->assertDontSee('Sales Rep B');
        }

        $this->assertSame('Sales Rep A', $sale->fresh()->sold_by_name_snapshot);
    }

    public function test_completed_sale_and_items_reject_mutation_and_deletion(): void
    {
        [, $sale] = $this->completedSale();
        $item = $sale->items()->sole();

        foreach ([
            fn () => tap($sale, fn ($record) => $record->notes = 'Changed')->save(),
            fn () => $sale->delete(),
            fn () => tap($item, fn (SaleItem $record) => $record->quantity = '9')->save(),
            fn () => $item->delete(),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Immutable sale history mutation should fail.');
            } catch (LogicException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    private function completedSale(): array
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['current_stock' => '10', 'selling_price' => '100']);
        $sale = app(CreateSale::class)->execute($admin, [
            'customer_id' => $customer->id,
            'products' => [['product_id' => $product->id, 'quantity' => '2']],
            'payment_method' => 'cash',
            'amount_paid' => '200',
            'notes' => null,
        ]);

        return [$admin, $sale, $product, $customer];
    }
}
