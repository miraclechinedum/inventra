<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class DashboardMoneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_money_rounds_decimal_strings_half_up_without_floats(): void
    {
        foreach ([
            '0' => '0.00',
            '0.1' => '0.10',
            '1.005' => '1.01',
            '123.454' => '123.45',
            '123.455' => '123.46',
            '123.456' => '123.46',
            '999999999999999999999999.995' => '1000000000000000000000000.00',
        ] as $amount => $expected) {
            $this->assertSame($expected, Money::round($amount));
        }

        $this->expectException(InvalidArgumentException::class);
        Money::round('1.2e3');
    }

    public function test_admin_dashboard_loads_with_canonical_zero_aggregates(): void
    {
        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Total Inventory Value')
            ->assertSee('₦0.00')
            ->assertSee('Sales Today');
    }

    public function test_inventory_value_rounds_the_final_high_precision_aggregate(): void
    {
        Product::factory()->create([
            'cost_price' => '100.25',
            'current_stock' => '1.125',
        ]);
        Product::factory()->create([
            'cost_price' => '0.01',
            'current_stock' => '0.500',
        ]);

        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('₦112.79');
    }

    public function test_dashboard_loads_with_sales_data_and_canonical_sales_total(): void
    {
        $admin = $this->admin();
        Sale::factory()->create([
            'sold_by' => $admin,
            'subtotal' => '123.45',
            'total_amount' => '123.45',
            'amount_paid' => '123.45',
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('₦123.45')
            ->assertSee('Recent sales');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }
}
