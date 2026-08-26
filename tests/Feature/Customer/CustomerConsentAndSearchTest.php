<?php

namespace Tests\Feature\Customer;

use App\Actions\Customer\SetWhatsAppConsent;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerConsentAndSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => UserRole::Manager]);
        $this->actingAs($this->manager);
    }

    public function test_initial_and_later_consent_transitions_use_server_timestamps_and_audit(): void
    {
        Carbon::setTestNow('2026-08-20 12:00:00');
        $this->post(route('customers.store'), $this->payload([
            'whatsapp_opt_in' => '1', 'whatsapp_opt_in_at' => '2000-01-01',
        ]))->assertSessionHasErrors('whatsapp_opt_in_at');
        $this->post(route('customers.store'), $this->payload(['whatsapp_opt_in' => '1']))->assertRedirect();
        $customer = Customer::query()->sole();
        $this->assertTrue($customer->whatsapp_opt_in);
        $this->assertTrue($customer->whatsapp_opt_in_at->equalTo(now()));
        $this->assertNull($customer->whatsapp_opt_out_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer_whatsapp_opted_in', 'auditable_id' => $customer->id]);

        Carbon::setTestNow('2026-08-20 13:00:00');
        $this->post(route('customers.consent', $customer), [
            'opt_in' => '0', 'whatsapp_opt_out_at' => '2000-01-01',
        ])->assertSessionHasErrors('whatsapp_opt_out_at');
        $this->post(route('customers.consent', $customer), ['opt_in' => '0'])->assertRedirect();
        $customer->refresh();
        $this->assertFalse($customer->whatsapp_opt_in);
        $this->assertNull($customer->whatsapp_opt_in_at);
        $this->assertTrue($customer->whatsapp_opt_out_at->equalTo(now()));
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer_whatsapp_opted_out', 'auditable_id' => $customer->id]);
    }

    public function test_consent_rejects_invalid_and_nested_values(): void
    {
        $customer = Customer::factory()->create();
        $this->post(route('customers.consent', $customer), ['opt_in' => 'perhaps'])->assertSessionHasErrors('opt_in');
        $this->post(route('customers.consent', $customer), ['opt_in' => ['1']])->assertSessionHasErrors('opt_in');
        $this->assertFalse($customer->fresh()->whatsapp_opt_in);
    }

    public function test_inactive_customer_can_opt_out_but_cannot_opt_in_after_lock(): void
    {
        $customer = Customer::factory()->create([
            'is_active' => false,
            'whatsapp_opt_in' => true,
            'whatsapp_opt_in_at' => now()->subDay(),
        ]);

        $this->post(route('customers.consent', $customer), ['opt_in' => '0'])->assertRedirect();
        $this->assertFalse($customer->fresh()->whatsapp_opt_in);
        $this->assertNotNull($customer->fresh()->whatsapp_opt_out_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer_whatsapp_opted_out', 'auditable_id' => $customer->id]);

        $this->post(route('customers.consent', $customer), ['opt_in' => '1'])->assertSessionHasErrors('customer');
        $this->assertFalse($customer->fresh()->whatsapp_opt_in);

        $this->expectException(ValidationException::class);
        app(SetWhatsAppConsent::class)->execute($this->manager, $customer, true);
    }

    public function test_repeated_consent_transitions_are_idempotent(): void
    {
        Carbon::setTestNow('2026-08-20 10:00:00');
        $customer = Customer::factory()->create();
        $this->post(route('customers.consent', $customer), ['opt_in' => '1'])->assertRedirect();
        $optedInAt = $customer->fresh()->whatsapp_opt_in_at;

        Carbon::setTestNow('2026-08-20 11:00:00');
        $this->post(route('customers.consent', $customer), ['opt_in' => '1'])->assertRedirect();
        $this->assertTrue($customer->fresh()->whatsapp_opt_in_at->equalTo($optedInAt));
        $this->assertSame(1, $this->customerAuditCount($customer, 'customer_whatsapp_opted_in'));

        $this->post(route('customers.consent', $customer), ['opt_in' => '0'])->assertRedirect();
        $optedOutAt = $customer->fresh()->whatsapp_opt_out_at;
        Carbon::setTestNow('2026-08-20 12:00:00');
        $this->post(route('customers.consent', $customer), ['opt_in' => '0'])->assertRedirect();
        $this->assertTrue($customer->fresh()->whatsapp_opt_out_at->equalTo($optedOutAt));
        $this->assertSame(1, $this->customerAuditCount($customer, 'customer_whatsapp_opted_out'));
    }

    public function test_search_filters_literal_wildcards_and_pagination_are_safe(): void
    {
        $percent = Customer::factory()->create(['first_name' => '% Percent', 'customer_code' => 'CUST-100001', 'phone' => '+2348011111111', 'email' => 'percent@example.com', 'whatsapp_opt_in' => true]);
        $under = Customer::factory()->create(['first_name' => '_ Under', 'customer_code' => 'CUST-100002', 'phone' => '+2348022222222', 'email' => 'under@example.com', 'is_active' => false]);
        $back = Customer::factory()->create(['first_name' => '\ Back', 'customer_code' => 'CUST-100003', 'phone' => '+2348033333333', 'email' => 'back@example.com']);
        Customer::factory()->create(['first_name' => 'Ordinary', 'customer_code' => 'CUST-100004', 'phone' => '+2348044444444', 'email' => 'ordinary@example.com']);
        $fullName = Customer::factory()->create(['first_name' => 'Ada', 'last_name' => 'Okafor', 'customer_code' => 'CUST-100005', 'phone' => '+2348055555555']);

        foreach (['Percent' => $percent, 'CUST-100002' => $under, '08033333333' => $back, '3333' => $back, '23480333' => $back, 'back@example.com' => $back] as $search => $expected) {
            $this->get('/customers?search='.urlencode($search))->assertSee($expected->customer_code);
        }
        $this->get('/customers?search='.urlencode('Ada Okafor'))->assertSee($fullName->customer_code);
        $this->get('/customers?search=%25')->assertSee($percent->customer_code)->assertDontSee('CUST-100004');
        $this->get('/customers?search=_')->assertSee($under->customer_code)->assertDontSee('CUST-100004');
        $this->get('/customers?search=%5C')->assertSee($back->customer_code)->assertDontSee('CUST-100004');
        $this->get('/customers?status=inactive')->assertSee($under->customer_code)->assertDontSee($percent->customer_code);
        $this->get('/customers?whatsapp=opted_in')->assertSee($percent->customer_code)->assertDontSee($back->customer_code);
        $this->get('/customers?search[]=x&status[]=active&whatsapp[]=opted_in')->assertOk();
        $this->get('/customers?search=12')->assertOk()->assertDontSee($back->customer_code);
        Customer::factory()->count(16)->create();
        $this->get('/customers')->assertSee('Next');
    }

    public function test_sales_lookup_defaults_active_and_customer_output_is_escaped_without_secret_leakage(): void
    {
        $sales = User::factory()->create(['role' => UserRole::SalesRep]);
        $active = Customer::factory()->create(['address' => 'Restricted Address', 'notes' => '<script>alert("x")</script>']);
        $inactive = Customer::factory()->create(['is_active' => false]);
        $this->actingAs($sales)->get(route('customers.index'))->assertSee($active->customer_code)->assertDontSee($inactive->customer_code);
        $response = $this->get(route('customers.show', $active))->assertOk();
        $response->assertDontSee('Restricted Address')
            ->assertDontSee('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>', false)
            ->assertDontSee('audit_logs')
            ->assertDontSee($sales->password)
            ->assertDontSee('quick_pin_hash')
            ->assertDontSee('remember_token');

        $this->actingAs($this->manager)->get(route('customers.show', $active))->assertOk()
            ->assertSee('Restricted Address')
            ->assertSee('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>', false);
    }

    public function test_activity_is_scoped_to_the_requested_customer(): void
    {
        $first = Customer::factory()->create();
        $second = Customer::factory()->create();
        $this->post(route('customers.deactivate', $first));
        $this->post(route('customers.deactivate', $second));

        $this->get(route('customers.activity', $first))->assertOk()
            ->assertSee('Customer Deactivated')
            ->assertDontSee($second->customer_code);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ada', 'last_name' => 'Okafor', 'phone' => '08012345678',
            'email' => 'ada@example.com', 'address' => '12 Market Road', 'city' => 'Lagos', 'notes' => null,
        ], $overrides);
    }

    private function customerAuditCount(Customer $customer, string $action): int
    {
        return $customer->newQuery()->getConnection()->table('audit_logs')
            ->where('auditable_type', $customer->getMorphClass())
            ->where('auditable_id', $customer->id)
            ->where('action', $action)
            ->count();
    }
}
