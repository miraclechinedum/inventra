<?php

namespace Tests\Feature\Suppliers;

use App\Actions\Supplier\CreateSupplier;
use App\Actions\Supplier\UpdateSupplier;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_independent_updates_serialize_with_authoritative_audit_history(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The pcntl extension is required for the independent-process concurrency probe.');
        }

        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $supplier = app(CreateSupplier::class)->execute($manager, ['name' => 'Original Supplier', 'city' => 'Lagos']);
        DB::commit();
        $barrier = tempnam(sys_get_temp_dir(), 'inventra-supplier-');
        unlink($barrier);

        try {
            $processes = [];
            foreach ([['Supplier A', 'Abuja'], ['Supplier B', 'Kano']] as [$name, $city]) {
                $pid = pcntl_fork();
                if ($pid === 0) {
                    while (! file_exists($barrier)) {
                        usleep(1_000);
                    }

                    try {
                        DB::purge();
                        app(UpdateSupplier::class)->execute(
                            User::findOrFail($manager->id),
                            Supplier::findOrFail($supplier->id),
                            ['name' => $name, 'city' => $city]
                        );
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
            $events = AuditLog::query()->where('action', 'supplier_updated')->where('auditable_id', $supplier->id)->orderBy('id')->get();
            $this->assertCount(2, $events);
            $this->assertSame($events[0]->new_values['name'], $events[1]->old_values['name']);
            $this->assertSame($events[0]->new_values['city'], $events[1]->old_values['city']);
            $this->assertSame($events[1]->new_values['name'], Supplier::findOrFail($supplier->id)->name);
            $this->assertSame($events[1]->new_values['city'], Supplier::findOrFail($supplier->id)->city);
        } finally {
            if (file_exists($barrier)) {
                unlink($barrier);
            }

            DB::purge();
            Artisan::call('migrate:fresh', ['--force' => true]);
            DB::connection()->beginTransaction();
        }
    }
}
