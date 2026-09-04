<?php

namespace Tests\Feature\Purchases;

use App\Actions\Purchase\ReceivePurchase;
use App\Actions\Supplier\CreateSupplier;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_independent_processes_serialize_overlapping_product_receipts_without_lost_updates(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The pcntl extension is required for the independent-process concurrency probe.');
        }

        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $supplierA = app(CreateSupplier::class)->execute($manager, ['name' => 'Concurrent Supplier A']);
        $supplierB = app(CreateSupplier::class)->execute($manager, ['name' => 'Concurrent Supplier B']);
        $first = Product::factory()->create(['current_stock' => '100.000']);
        $second = Product::factory()->create(['current_stock' => '50.000']);
        $firstToken = $this->token($manager);
        $secondToken = $this->token($manager);
        DB::commit();

        $barrier = tempnam(sys_get_temp_dir(), 'inventra-purchase-');
        unlink($barrier);
        $children = [
            [$supplierA->id, $firstToken, [
                ['product_id' => $first->id, 'quantity' => '20', 'unit_cost' => '2'],
                ['product_id' => $second->id, 'quantity' => '10', 'unit_cost' => '3'],
            ]],
            [$supplierB->id, $secondToken, [
                ['product_id' => $second->id, 'quantity' => '5', 'unit_cost' => '4'],
                ['product_id' => $first->id, 'quantity' => '30', 'unit_cost' => '5'],
            ]],
        ];

        try {
            $processes = [];
            foreach ($children as [$supplierId, $token, $items]) {
                $pid = pcntl_fork();
                if ($pid === 0) {
                    while (! file_exists($barrier)) {
                        usleep(1_000);
                    }

                    try {
                        DB::purge();
                        $actor = User::findOrFail($manager->id);
                        app(ReceivePurchase::class)->execute($actor, [
                            'request_token' => $token,
                            'supplier_id' => $supplierId,
                            'items' => $items,
                        ], 'concurrency-session');
                        exit(0);
                    } catch (\Throwable) {
                        exit(1);
                    }
                }

                $this->assertGreaterThan(0, $pid);
                $processes[] = $pid;
            }

            touch($barrier);
            foreach ($processes as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            DB::purge();
            $this->assertSame('150.000', Product::findOrFail($first->id)->current_stock);
            $this->assertSame('65.000', Product::findOrFail($second->id)->current_stock);
            $this->assertDatabaseCount('purchases', 2);
            $this->assertSame(4, DB::table('inventory_movements')->where('type', InventoryMovementType::Purchase->value)->count());
        } finally {
            if (file_exists($barrier)) {
                unlink($barrier);
            }

            DB::purge();
            Artisan::call('migrate:fresh', ['--force' => true]);
            DB::connection()->beginTransaction();
        }
    }

    private function token(User $actor): string
    {
        $token = Str::random(64);
        $request = new PurchaseRequest;
        $request->token_hash = hash('sha256', $token);
        $request->actor_id = $actor->id;
        $request->session_id = 'concurrency-session';
        $request->expires_at = now()->addMinutes(30);
        $request->save();

        return $token;
    }
}
