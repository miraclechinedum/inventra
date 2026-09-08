<?php

namespace Tests\Feature\Ui;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PrototypeUiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_navigation_keeps_role_destinations_and_mobile_controls_available(): void
    {
        foreach ([UserRole::Admin, UserRole::Manager, UserRole::SalesRep] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $response = $this->actingAs($user)->get(route('dashboard'))->assertOk()
                ->assertSee('id="navigation-toggle"', false)
                ->assertSee('aria-controls="app-navigation"', false)
                ->assertSee('aria-label="Sign out"', false)
                ->assertSee('Skip to content');
            foreach (['staff.index', 'audit.index', 'settings.business.edit'] as $route) {
                if ($role === UserRole::Admin) {
                    $response->assertSee('href="'.route($route).'"', false);
                } else {
                    $response->assertDontSee('href="'.route($route).'"', false);
                }
            }
            foreach (['returns.index', 'refunds.index', 'reports.index', 'purchases.index'] as $route) {
                if ($role === UserRole::SalesRep) {
                    $response->assertDontSee('href="'.route($route).'"', false);
                } else {
                    $response->assertSee('href="'.route($route).'"', false);
                }
            }
        }
    }

    public function test_sale_entry_keeps_eight_server_submitted_lines_and_search_contract(): void
    {
        $user = User::factory()->create(['role' => UserRole::SalesRep]);
        $response = $this->actingAs($user)->get(route('sales.create'))->assertOk()
            ->assertSee('data-sale-product-search', false)->assertSee('data-sale-entry', false)
            ->assertSee('name="customer_id"', false)->assertSee('name="amount_paid"', false)
            ->assertSee('name="payment_method"', false)->assertSee('name="notes"', false);
        $this->assertSame(8, substr_count($response->getContent(), 'aria-label="Quantity for product '));
        $response->assertDontSee('name="total_amount"', false)->assertDontSee('name="unit_price"', false);
    }
}
