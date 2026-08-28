<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\UserRole;
use App\Enums\WhatsAppDeliveryStatus;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\Models\WhatsAppDelivery;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class WhatsAppMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_manual_resolution_migration_rolls_back_only_without_resolution_history(): void
    {
        $delivery = $this->delivery();
        $before = DB::table('whatsapp_deliveries')->where('id', $delivery->id)->first();
        $migration = $this->migration();

        try {
            $migration->down();

            $this->assertFalse(Schema::hasColumn('whatsapp_deliveries', 'resolved_at'));
            $this->assertFalse(Schema::hasColumn('whatsapp_deliveries', 'resolved_by'));
            $this->assertFalse(Schema::hasColumn('whatsapp_deliveries', 'resolution_note'));
            $preserved = DB::table('whatsapp_deliveries')->where('id', $delivery->id)->first();
            $this->assertSame($before->request_id, $preserved->request_id);
            $this->assertSame($before->destination_phone, $preserved->destination_phone);
            $this->assertSame($before->status, $preserved->status);
        } finally {
            $migration->up();
        }
    }

    public function test_manual_resolution_migration_refuses_each_form_of_resolution_history(): void
    {
        $delivery = $this->delivery();
        $migration = $this->migration();
        $evidenceCases = [
            ['status' => WhatsAppDeliveryStatus::Unresolved->value],
            ['resolved_at' => now()],
            ['resolved_by' => $delivery->created_by],
            ['resolution_note' => 'Manual investigation could not determine the outcome.'],
        ];

        foreach ($evidenceCases as $evidence) {
            DB::table('whatsapp_deliveries')->where('id', $delivery->id)->update($evidence);
            $before = DB::table('whatsapp_deliveries')->where('id', $delivery->id)->first();

            try {
                $migration->down();
                $this->fail('Rollback must fail while manual resolution evidence exists.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('resolution history exists', $exception->getMessage());
            }

            $after = DB::table('whatsapp_deliveries')->where('id', $delivery->id)->first();
            $this->assertEquals($before, $after);
            $this->assertTrue(Schema::hasColumns('whatsapp_deliveries', [
                'resolved_at', 'resolved_by', 'resolution_note',
            ]));
            DB::table('whatsapp_deliveries')->where('id', $delivery->id)->update([
                'status' => WhatsAppDeliveryStatus::Pending->value,
                'resolved_at' => null,
                'resolved_by' => null,
                'resolution_note' => null,
            ]);
        }
    }

    private function delivery(): WhatsAppDelivery
    {
        $creator = User::factory()->create(['role' => UserRole::Admin]);
        $customer = Customer::factory()->create();
        $sale = Sale::factory()->create(['customer_id' => $customer, 'sold_by' => $creator]);
        $delivery = new WhatsAppDelivery;
        $delivery->sale_id = $sale->id;
        $delivery->customer_id = $customer->id;
        $delivery->request_id = (string) Str::uuid();
        $delivery->destination_phone = $customer->phone;
        $delivery->consent_checked_at = now();
        $delivery->consent_opt_in_at_snapshot = now();
        $delivery->requested_at = now();
        $delivery->status = WhatsAppDeliveryStatus::Pending;
        $delivery->attempt = 1;
        $delivery->created_by = $creator->id;
        $delivery->save();

        return $delivery;
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_28_010000_add_manual_resolution_to_whatsapp_deliveries.php');
    }
}
