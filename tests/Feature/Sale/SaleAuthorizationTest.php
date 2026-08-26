<?php

namespace Tests\Feature\Sale;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_and_approved_roles_access_sales(): void
    {
        $this->get('/sales')->assertRedirect(route('login'));

        foreach ([UserRole::Admin, UserRole::Manager, UserRole::SalesRep] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('sales.index'))->assertOk();
            $this->get(route('sales.create'))->assertOk();
        }
    }

    public function test_sales_rep_sees_only_own_sales_and_cannot_void_or_view_audit(): void
    {
        $sales = User::factory()->create(['role' => UserRole::SalesRep]);
        $other = User::factory()->create(['role' => UserRole::SalesRep]);
        $own = Sale::factory()->create(['sold_by' => $sales]);
        $foreign = Sale::factory()->create(['sold_by' => $other]);
        $this->actingAs($sales)->get(route('sales.index'))->assertSee($own->sale_number)->assertDontSee($foreign->sale_number);
        $this->get(route('sales.show', $own))->assertOk()->assertDontSee('Cost price');
        $this->get(route('sales.show', $foreign))->assertForbidden();
        $this->get(route('sales.activity', $own))->assertForbidden();
        $this->post(route('sales.void', $own), ['reason' => 'Denied'])->assertForbidden();
    }

    public function test_manager_views_all_and_audit_but_only_admin_can_void(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $sale = Sale::factory()->create();
        $this->actingAs($manager)->get(route('sales.show', $sale))->assertOk();
        $this->get(route('sales.activity', $sale))->assertOk();
        $this->post(route('sales.void', $sale), ['reason' => 'Denied'])->assertForbidden();
        $this->actingAs($admin)->post(route('sales.void', $sale), ['reason' => 'No items but valid void'])->assertRedirect();
    }

    public function test_product_search_returns_only_active_non_archived_products_without_cost_price(): void
    {
        $salesRep = User::factory()->create(['role' => UserRole::SalesRep]);
        $visible = Product::factory()->create(['name' => 'Searchable Soap', 'cost_price' => '765.43']);
        Product::factory()->create(['name' => 'Searchable Inactive', 'is_active' => false]);
        $archived = Product::factory()->create(['name' => 'Searchable Archived']);
        $archived->delete();

        $response = $this->actingAs($salesRep)->get(route('sales.create', ['product_search' => 'Searchable']));

        $response->assertOk()
            ->assertSee($visible->name)
            ->assertDontSee('Searchable Inactive')
            ->assertDontSee('Searchable Archived')
            ->assertDontSee('765.43');
    }
}
