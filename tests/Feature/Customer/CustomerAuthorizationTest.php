<?php

namespace Tests\Feature\Customer;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_and_all_roles_can_view_and_create_customers(): void
    {
        $this->get('/customers')->assertRedirect(route('login'));

        foreach (UserRole::cases() as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('customers.index'))->assertOk();
            $this->get(route('customers.create'))->assertOk();
        }
    }

    public function test_admin_and_manager_manage_status_and_activity(): void
    {
        foreach ([UserRole::Admin, UserRole::Manager] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $customer = Customer::factory()->create();
            $this->actingAs($user)->post(route('customers.deactivate', $customer))->assertRedirect();
            $this->get(route('customers.activity', $customer))->assertOk();
            $this->post(route('customers.activate', $customer))->assertRedirect();
        }
    }

    public function test_sales_rep_can_update_active_customer_but_cannot_manage_status_or_audit(): void
    {
        $sales = User::factory()->create(['role' => UserRole::SalesRep]);
        $customer = Customer::factory()->create();
        $originalPhone = $customer->phone;
        $this->actingAs($sales)->put(route('customers.update', $customer), ['email' => 'new@example.com', 'city' => 'Abuja'])->assertRedirect();
        $this->assertSame('new@example.com', $customer->fresh()->email);
        $this->put(route('customers.update', $customer), $this->payload())->assertSessionHasErrors(['first_name', 'last_name', 'phone']);
        $this->assertSame($originalPhone, $customer->fresh()->phone);
        $this->post(route('customers.store'), array_merge($this->payload(['phone' => '08099999999']), [
            'address' => 'Restricted', 'notes' => 'Restricted',
        ]))->assertSessionHasErrors(['address', 'notes']);
        $this->post(route('customers.deactivate', $customer))->assertForbidden();
        $this->post(route('customers.activate', $customer))->assertForbidden();
        $this->get(route('customers.activity', $customer))->assertForbidden();
    }

    public function test_sales_rep_cannot_view_or_update_inactive_customer(): void
    {
        $sales = User::factory()->create(['role' => UserRole::SalesRep]);
        $customer = Customer::factory()->create(['is_active' => false]);
        $this->actingAs($sales)->get(route('customers.show', $customer))->assertForbidden();
        $this->get(route('customers.edit', $customer))->assertForbidden();
        $this->put(route('customers.update', $customer), $this->payload())->assertForbidden();
    }

    public function test_admin_and_manager_can_edit_inactive_customer(): void
    {
        foreach ([UserRole::Admin, UserRole::Manager] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $customer = Customer::factory()->create(['is_active' => false]);
            $this->actingAs($user)->put(route('customers.update', $customer), $this->payload([
                'phone' => '080'.str_pad((string) $customer->id, 8, '0', STR_PAD_LEFT),
            ]))->assertRedirect();
            $this->assertSame('Ada', $customer->fresh()->first_name);
        }
    }

    public function test_customer_policy_uses_explicit_role_allowlists(): void
    {
        $source = file_get_contents(app_path('Policies/CustomerPolicy.php'));
        $this->assertStringNotContainsString('UserRole::cases()', $source);
        $this->assertStringContainsString('UserRole::Admin', $source);
        $this->assertStringContainsString('UserRole::Manager', $source);
        $this->assertStringContainsString('UserRole::SalesRep', $source);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['first_name' => 'Ada', 'last_name' => 'Okafor', 'phone' => '08012345678', 'email' => 'ada@example.com'], $overrides);
    }
}
