<?php

namespace Tests\Feature\Customer;

use App\Actions\Customer\UpdateCustomer;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\CustomerCode;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($this->admin);
    }

    public function test_creation_generates_race_safe_code_canonical_identity_and_audit(): void
    {
        $this->post(route('customers.store'), $this->payload([
            'phone' => '+234 801 234 5678', 'email' => ' ADA@EXAMPLE.COM ',
        ]))->assertRedirect();

        $customer = Customer::query()->sole();
        $this->assertSame(sprintf('CUST-%06d', $customer->id), $customer->customer_code);
        $this->assertSame('+2348012345678', $customer->phone);
        $this->assertSame('ada@example.com', $customer->email);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer_created', 'actor_id' => $this->admin->id, 'auditable_id' => $customer->id]);

        $this->post(route('customers.store'), $this->payload(['phone' => '08022222222', 'email' => null]))->assertRedirect();
        $second = Customer::query()->latest('id')->firstOrFail();
        $this->assertSame(sprintf('CUST-%06d', $second->id), $second->customer_code);
        $this->assertNull($second->email);
    }

    public function test_duplicate_malformed_nested_and_privileged_fields_fail_cleanly(): void
    {
        Customer::factory()->create(['phone' => '+2348012345678']);
        $this->post(route('customers.store'), $this->payload())->assertSessionHasErrors('phone');
        $this->post(route('customers.store'), $this->payload(['phone' => 'not-phone']))->assertSessionHasErrors('phone');
        $this->post(route('customers.store'), $this->payload([
            'first_name' => ['Ada'], 'phone' => ['08012345678'], 'email' => ['bad'],
        ]))->assertSessionHasErrors(['first_name', 'phone', 'email']);
        $this->post(route('customers.store'), $this->payload([
            'phone' => '08033333333', 'customer_code' => 'HACK', 'created_by' => 999, 'updated_by' => 999,
            'is_active' => false, 'whatsapp_opt_in_at' => now(), 'whatsapp_opt_out_at' => now(), 'deleted_at' => now(),
        ]))->assertSessionHasErrors(['customer_code', 'created_by', 'updated_by', 'is_active', 'whatsapp_opt_in_at', 'whatsapp_opt_out_at', 'deleted_at']);
    }

    public function test_profile_update_normalizes_phone_and_rejects_identity_status_and_consent_tampering(): void
    {
        $customer = Customer::factory()->create(['phone' => '+2348022222222']);
        $other = Customer::factory()->create(['phone' => '+2348033333333']);
        $this->put(route('customers.update', $customer), $this->payload([
            'phone' => '08044444444', 'customer_code' => 'CHANGED', 'is_active' => false,
            'whatsapp_opt_in' => true, 'whatsapp_opt_in_at' => now(), 'updated_by' => 999,
        ]))->assertSessionHasErrors(['customer_code', 'is_active', 'whatsapp_opt_in', 'whatsapp_opt_in_at', 'updated_by']);
        $this->assertSame($customer->customer_code, $customer->fresh()->customer_code);

        $this->put(route('customers.update', $customer), $this->payload(['phone' => '08033333333']))->assertSessionHasErrors('phone');
        $this->put(route('customers.update', $customer), $this->payload(['phone' => '08044444444', 'first_name' => 'Amara']))->assertRedirect();
        $this->assertSame('+2348044444444', $customer->fresh()->phone);
        $this->assertSame('Amara', $customer->fresh()->first_name);
        $this->assertSame('+2348033333333', $other->fresh()->phone);
    }

    public function test_database_constraints_enforce_phone_and_customer_code_uniqueness(): void
    {
        $customer = Customer::factory()->create();

        foreach (['phone' => $customer->phone, 'customer_code' => $customer->customer_code] as $column => $value) {
            try {
                DB::table('customers')->insert([
                    'customer_code' => $column === 'customer_code' ? $value : 'DIRECT-'.$column,
                    'first_name' => 'Direct',
                    'phone' => $column === 'phone' ? $value : '+2348099999999',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->fail("Duplicate {$column} should fail.");
            } catch (QueryException) {
                $this->assertDatabaseCount('customers', 1);
            }
        }
    }

    public function test_deactivation_preserves_customer_and_audits_lifecycle(): void
    {
        $customer = Customer::factory()->create();
        $this->post(route('customers.deactivate', $customer))->assertRedirect();
        $this->assertFalse($customer->fresh()->is_active);
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer_deactivated', 'auditable_id' => $customer->id]);
        $this->post(route('customers.activate', $customer))->assertRedirect();
        $this->assertTrue($customer->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer_activated', 'auditable_id' => $customer->id]);
    }

    public function test_phone_change_resets_opted_in_and_opted_out_consent_atomically(): void
    {
        foreach ([
            ['whatsapp_opt_in' => true, 'whatsapp_opt_in_at' => now()->subDay(), 'whatsapp_opt_out_at' => null],
            ['whatsapp_opt_in' => false, 'whatsapp_opt_in_at' => null, 'whatsapp_opt_out_at' => now()->subDay()],
        ] as $index => $consent) {
            $customer = Customer::factory()->create(array_merge($consent, ['phone' => '+23480111111'.($index + 1)]));
            $phone = '0802222222'.($index + 1);
            $this->put(route('customers.update', $customer), $this->payload(['phone' => $phone]))->assertRedirect();
            $customer->refresh();
            $this->assertSame('+234802222222'.($index + 1), $customer->phone);
            $this->assertFalse($customer->whatsapp_opt_in);
            $this->assertNull($customer->whatsapp_opt_in_at);
            $this->assertNull($customer->whatsapp_opt_out_at);
            $reset = AuditLog::query()
                ->where('action', 'customer_whatsapp_consent_reset')
                ->where('auditable_id', $customer->id)
                ->sole();
            $this->assertSame(['reason' => 'phone_changed'], $reset->metadata);
        }
    }

    public function test_phone_change_rolls_back_when_consent_reset_audit_fails(): void
    {
        $customer = Customer::factory()->create([
            'phone' => '+2348011111111',
            'whatsapp_opt_in' => true,
            'whatsapp_opt_in_at' => now()->subDay(),
        ]);
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once();
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));

        try {
            (new UpdateCustomer($audit))->execute($this->admin, $customer, $this->payload(['phone' => '+2348022222222']));
            $this->fail('The update should roll back when consent-reset auditing fails.');
        } catch (RuntimeException) {
            $customer->refresh();
            $this->assertSame('+2348011111111', $customer->phone);
            $this->assertTrue($customer->whatsapp_opt_in);
            $this->assertNotNull($customer->whatsapp_opt_in_at);
        }
    }

    public function test_customer_code_supports_ids_beyond_six_digits_and_customer_audit_rejects_secrets_and_objects(): void
    {
        $this->assertSame('CUST-1234567', CustomerCode::fromId(1234567));
        $customer = Customer::factory()->create();

        app(AuditLogger::class)->record('customer_updated', $customer, $this->admin,
            newValues: ['password' => 'secret', 'phone' => new \stdClass, 'city' => 'Lagos'],
            metadata: ['token' => 'secret', 'reason' => new \stdClass]);

        $audit = $customer->newQuery()->getConnection()->table('audit_logs')->latest('id')->first();
        $this->assertSame(['city' => 'Lagos'], json_decode($audit->new_values, true));
        $this->assertNull($audit->metadata);
        $this->assertStringNotContainsString('secret', json_encode($audit));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ada', 'last_name' => 'Okafor', 'phone' => '08012345678',
            'email' => 'ada@example.com', 'address' => '12 Market Road', 'city' => 'Lagos', 'notes' => 'Trusted customer',
        ], $overrides);
    }
}
