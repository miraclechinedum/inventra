<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\AdjustStock;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => UserRole::Manager]);
        $this->actingAs($this->manager);
    }

    public function test_supported_adjustments_compute_authoritative_before_change_and_after_values(): void
    {
        $product = Product::factory()->create(['current_stock' => '10', 'reorder_level' => '3']);

        foreach ([
            ['restock', 'increase', '5', '15.000'],
            ['damage', 'decrease', '2', '13.000'],
            ['loss', 'decrease', '1', '12.000'],
            ['adjustment', 'increase', '3', '15.000'],
            ['correction', 'set', '8', '8.000'],
        ] as [$type, $operation, $quantity, $expected]) {
            $this->post(route('inventory.products.adjust', $product), [
                'type' => $type, 'operation' => $operation, 'quantity' => $quantity, 'reason' => 'Verified adjustment',
                'quantity_before' => '999', 'quantity_after' => '999', 'performed_by' => 999,
            ])->assertSessionHasErrors(['quantity_before', 'quantity_after', 'performed_by']);

            $this->post(route('inventory.products.adjust', $product), compact('type', 'operation', 'quantity'))->assertRedirect();
            $this->assertSame($expected, $product->fresh()->current_stock);
            $movement = $product->movements()->latest('id')->firstOrFail();
            $this->assertSame($expected, $movement->quantity_after);
            $this->assertSame($this->manager->id, $movement->performed_by);
        }

        $this->assertSame($product->fresh()->current_stock, $product->movements()->latest('id')->value('quantity_after'));
        $this->assertSame($product->fresh()->current_stock, bcadd('10', $product->movements()->sum('quantity_change'), 3));
    }

    public function test_negative_stock_and_invalid_semantics_change_nothing(): void
    {
        $product = Product::factory()->create(['current_stock' => '3']);

        $this->post(route('inventory.products.adjust', $product), ['type' => 'damage', 'operation' => 'decrease', 'quantity' => '4'])
            ->assertSessionHasErrors('quantity');
        $this->post(route('inventory.products.adjust', $product), ['type' => 'restock', 'operation' => 'decrease', 'quantity' => '1'])
            ->assertSessionHasErrors('operation');
        $this->post(route('inventory.products.adjust', $product), ['type' => ['damage'], 'operation' => ['decrease'], 'quantity' => ['1']])
            ->assertSessionHasErrors(['type', 'operation', 'quantity']);

        $this->assertSame('3.000', $product->fresh()->current_stock);
        $this->assertCount(0, $product->movements);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'stock_adjusted', 'auditable_id' => $product->id]);
    }

    public function test_successful_restock_clears_derived_low_stock_state(): void
    {
        $product = Product::factory()->create(['current_stock' => '2', 'reorder_level' => '3']);
        $this->assertTrue($product->isLowStock());

        $this->post(route('inventory.products.adjust', $product), [
            'type' => 'restock',
            'operation' => 'increase',
            'quantity' => '2',
        ])->assertRedirect();

        $this->assertFalse($product->fresh()->isLowStock());
        $this->assertSame('4.000', $product->fresh()->current_stock);
    }

    public function test_stock_adjustment_uses_for_update_and_writes_business_audit(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $product = Product::factory()->create(['current_stock' => '10']);

        $this->post(route('inventory.products.adjust', $product), ['type' => 'restock', 'operation' => 'increase', 'quantity' => '2']);

        $this->assertTrue(collect($queries)->contains(fn (string $sql): bool => Str::contains(Str::lower($sql), 'for update')));
        $this->assertDatabaseHas('audit_logs', ['action' => 'stock_adjusted', 'actor_id' => $this->manager->id, 'auditable_id' => $product->id]);
    }

    public function test_two_sequential_competing_decrements_cannot_create_a_lost_update_or_negative_stock(): void
    {
        $product = Product::factory()->create(['current_stock' => '10']);
        $payload = ['type' => 'adjustment', 'operation' => 'decrease', 'quantity' => '7'];

        $this->post(route('inventory.products.adjust', $product), $payload)->assertRedirect();
        $this->post(route('inventory.products.adjust', $product), $payload)->assertSessionHasErrors('quantity');

        $this->assertSame('3.000', $product->fresh()->current_stock);
        $this->assertCount(1, $product->movements);
        $this->assertSame('3.000', $product->movements()->first()->quantity_after);
    }

    public function test_adjustment_rolls_back_stock_and_movement_when_audit_fails(): void
    {
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));
        $product = Product::factory()->create(['current_stock' => '10']);
        $action = new AdjustStock($audit);

        try {
            $action->execute($this->manager, $product, ['type' => 'restock', 'operation' => 'increase', 'quantity' => '2']);
            $this->fail('Adjustment should roll back when audit fails.');
        } catch (RuntimeException) {
            $this->assertSame('10.000', $product->fresh()->current_stock);
            $this->assertCount(0, $product->movements);
        }
    }
}
