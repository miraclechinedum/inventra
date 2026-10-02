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
            ->get(route('operations'))
            ->assertOk()
            ->assertSee('Gross Sales')
            ->assertSee('Customer Collections')
            ->assertSee('Current Outstanding Receivables')
            ->assertSee('₦0.00');
    }

    public function test_dashboard_renders_canonical_sale_money_without_float_drift(): void
    {
        $admin = $this->admin();
        Sale::factory()->create([
            'sold_by' => $admin,
            'subtotal' => '123.45',
            'total_amount' => '123.45',
            'amount_paid' => '123.45',
            'balance_due' => '0.00',
        ]);

        $this->actingAs($admin)
            ->get(route('operations'))
            ->assertOk()
            ->assertSee('₦123.45')
            ->assertSee('Recent Sales');
    }

    /**
     * The operational breakdown calculates no accounting figures.
     *
     * This previously also forbade "Total Inventory Value" anywhere, on the grounds that inventory
     * valuation was outside the dashboard's scope. That rule was deliberately retired when the
     * headline dashboard was rebuilt: stock-on-hand at cost is now an approved KPI there, defined
     * and covered by DashboardOverviewTest.
     *
     * The part that still holds — and is what this test is really for — is that NO derived
     * accounting figure is invented: no profit, no COGS, no margin, no tax, on either page. That is
     * asserted below, and /operations keeps its explicit disclaimer.
     */
    public function test_the_operational_breakdown_calculates_no_accounting_figures(): void
    {
        Product::factory()->create(['cost_price' => '100.25', 'current_stock' => '1.125']);
        Product::factory()->create(['cost_price' => '0.01', 'current_stock' => '0.500']);

        $html = $this->actingAs($this->admin())->get(route('operations'))->assertOk()->getContent();

        // None of these may appear as a metric card label.
        preg_match_all('/<small class="text-slate-500">([^<]+)<\/small>/', $html, $labels);
        foreach ($labels[1] as $label) {
            foreach (['Profit', 'Net Income', 'COGS', 'Margin', 'Valuation', 'Tax'] as $accounting) {
                $this->assertStringNotContainsStringIgnoringCase($accounting, $label);
            }
        }
        $this->assertStringContainsString('No profit, COGS, margin, inventory valuation, or tax is calculated', $html);
    }

    /** The headline dashboard likewise derives no profit, COGS, margin or tax figure. */
    public function test_the_headline_dashboard_invents_no_accounting_figure(): void
    {
        Product::factory()->create(['cost_price' => '100.25', 'current_stock' => '1.125']);

        $html = $this->actingAs($this->admin())->get(route('dashboard'))->assertOk()->getContent();

        foreach (['Profit', 'Net Income', 'COGS', 'Margin', 'Gross Margin', 'Tax'] as $accounting) {
            $this->assertStringNotContainsStringIgnoringCase($accounting, $html,
                "{$accounting} is not a figure Inventra calculates");
        }
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }
}
