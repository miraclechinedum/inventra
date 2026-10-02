<?php

namespace Tests\Feature\Staff;

use App\Actions\Sale\CreateSale;
use App\Actions\Sale\VoidSale;
use App\Contracts\WhatsAppConnectionProvider;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Reports\EmployeeActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeWhatsAppProvider;
use Tests\TestCase;

/**
 * Admin view of what an employee actually did.
 *
 * The figures are derived from the business tables rather than stored, so the tests record real
 * sales through the real actions and then check the report agrees with them. A report that merely
 * rendered without error would prove nothing; these assert the arithmetic and the date window.
 */
class EmployeeActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Keeps sale completion from queueing WhatsApp work that is irrelevant here. With no
        // connected business number the automations never produce a message at all.
        $provider = new FakeWhatsAppProvider;
        $provider->configured = false;
        $this->app->instance(WhatsAppConnectionProvider::class, $provider);
    }

    private function recordSale(User $seller, string $unitPrice, string $paid): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['current_stock' => '50.000', 'selling_price' => $unitPrice]);

        app(CreateSale::class)->execute($seller, [
            'customer_id' => $customer->id,
            'products' => [['product_id' => $product->id, 'quantity' => '1']],
            'payment_method' => 'cash',
            'amount_paid' => $paid,
            'notes' => null,
        ]);
    }

    private function metric(array $activity, string $label): array
    {
        foreach ($activity['metrics'] as $metric) {
            if ($metric['label'] === $label) {
                return $metric;
            }
        }

        $this->fail("no metric labelled {$label}");
    }

    public function test_the_report_counts_and_values_the_sales_an_employee_recorded(): void
    {
        $seller = User::factory()->create(['role' => UserRole::SalesRep]);
        $this->recordSale($seller, '2500.00', '2500.00');
        $this->recordSale($seller, '4000.00', '1000.00');

        $activity = app(EmployeeActivity::class)->forUser($seller);

        $sales = $this->metric($activity, 'Sales recorded');
        $this->assertSame('2', $sales['count']);
        $this->assertSame('6500.00', $sales['value'], 'the value must be the sum of the sale totals');

        $payments = $this->metric($activity, 'Payments taken');
        $this->assertSame('2', $payments['count']);
        $this->assertSame('3500.00', $payments['value'], 'only what was actually collected counts');
    }

    public function test_another_employees_work_is_not_attributed(): void
    {
        $seller = User::factory()->create(['role' => UserRole::SalesRep]);
        $colleague = User::factory()->create(['role' => UserRole::SalesRep]);
        $this->recordSale($seller, '5000.00', '5000.00');
        $this->recordSale($colleague, '9000.00', '9000.00');

        $mine = app(EmployeeActivity::class)->forUser($seller);
        $theirs = app(EmployeeActivity::class)->forUser($colleague);

        $this->assertSame('5000.00', $this->metric($mine, 'Sales recorded')['value']);
        $this->assertSame('9000.00', $this->metric($theirs, 'Sales recorded')['value']);
    }

    public function test_a_voided_sale_is_counted_separately_and_not_as_revenue(): void
    {
        $seller = User::factory()->create(['role' => UserRole::SalesRep]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->recordSale($seller, '7000.00', '7000.00');
        $sale = Sale::query()->sole();
        app(VoidSale::class)->execute($admin, $sale, 'Recorded against the wrong customer.');

        $activity = app(EmployeeActivity::class)->forUser($seller);

        $this->assertSame('0', $this->metric($activity, 'Sales recorded')['count'],
            'a voided sale is not a completed sale');
        $this->assertSame('0.00', $this->metric($activity, 'Sales recorded')['value']);
        $this->assertSame('1', $this->metric($activity, 'Sales voided')['count']);
    }

    public function test_the_date_window_excludes_work_outside_it(): void
    {
        $seller = User::factory()->create(['role' => UserRole::SalesRep]);

        $this->travelTo(now()->subDays(90));
        $this->recordSale($seller, '1000.00', '1000.00');
        $this->travelBack();
        $this->recordSale($seller, '2000.00', '2000.00');

        // Default window is the last 30 days, so the old sale falls outside it.
        $recent = app(EmployeeActivity::class)->forUser($seller);
        $this->assertSame('1', $this->metric($recent, 'Sales recorded')['count']);
        $this->assertSame('2000.00', $this->metric($recent, 'Sales recorded')['value']);

        // Widening the window brings it back.
        $wide = app(EmployeeActivity::class)->forUser($seller, now()->subDays(200)->toDateString(), now()->toDateString());
        $this->assertSame('2', $this->metric($wide, 'Sales recorded')['count']);
        $this->assertSame('3000.00', $this->metric($wide, 'Sales recorded')['value']);
    }

    public function test_a_reversed_date_range_is_tolerated(): void
    {
        $seller = User::factory()->create(['role' => UserRole::SalesRep]);
        $this->recordSale($seller, '1500.00', '1500.00');

        // from after to: the report swaps them rather than returning nothing.
        $activity = app(EmployeeActivity::class)->forUser($seller, now()->toDateString(), now()->subDays(7)->toDateString());

        $this->assertSame('1', $this->metric($activity, 'Sales recorded')['count']);
        $this->assertTrue($activity['from']->lessThanOrEqualTo($activity['to']));
    }

    public function test_a_malformed_date_falls_back_to_the_default_window(): void
    {
        $seller = User::factory()->create(['role' => UserRole::SalesRep]);
        $this->recordSale($seller, '1500.00', '1500.00');

        $activity = app(EmployeeActivity::class)->forUser($seller, 'not-a-date', '2026-13-45');

        $this->assertSame('1', $this->metric($activity, 'Sales recorded')['count']);
        $this->assertSame(now()->subDays(29)->startOfDay()->toDateString(), $activity['from']->toDateString());
    }

    public function test_an_employee_with_no_work_reports_zeroes_rather_than_failing(): void
    {
        $newcomer = User::factory()->create(['role' => UserRole::SalesRep]);

        $activity = app(EmployeeActivity::class)->forUser($newcomer);

        foreach ($activity['metrics'] as $metric) {
            $this->assertSame('0', $metric['count'], $metric['label'].' must be zero');
        }
        $this->assertCount(0, $activity['recent']);
    }

    public function test_recent_audited_actions_are_listed(): void
    {
        $seller = User::factory()->create(['role' => UserRole::SalesRep]);
        $this->recordSale($seller, '1200.00', '1200.00');

        $activity = app(EmployeeActivity::class)->forUser($seller);

        $actions = collect($activity['recent'])->pluck('action')->all();
        $this->assertContains('sale_created', $actions);
    }

    // ──────────────────────────────────── page & authorization ──────────────────────────────────

    public function test_an_admin_sees_the_activity_page_with_the_derived_figures(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $seller = User::factory()->create(['role' => UserRole::SalesRep, 'name' => 'Chidi Okonkwo']);
        $this->recordSale($seller, '3500.00', '3500.00');

        $html = $this->actingAs($admin)->get(route('staff.activity', $seller))->assertOk()->getContent();

        $this->assertStringContainsString('Work activity', $html);
        $this->assertStringContainsString('Sales recorded', $html);
        $this->assertStringContainsString('3,500.00', $html);
        $this->assertStringContainsString('Payments taken', $html);
        $this->assertStringContainsString('Most recent audited actions', $html);
        // The pre-existing security feed must still be there.
        $this->assertStringContainsString('Security activity', $html);
    }

    public function test_the_page_honours_the_date_filter(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $seller = User::factory()->create(['role' => UserRole::SalesRep]);

        $this->travelTo(now()->subDays(120));
        $this->recordSale($seller, '8800.00', '8800.00');
        $this->travelBack();

        $narrow = $this->actingAs($admin)->get(route('staff.activity', $seller))->assertOk()->getContent();
        $this->assertStringNotContainsString('8,800.00', $narrow);

        $wide = $this->actingAs($admin)->get(route('staff.activity', $seller, false).'?from='.now()->subDays(200)->toDateString().'&to='.now()->toDateString())
            ->assertOk()->getContent();
        $this->assertStringContainsString('8,800.00', $wide);
    }

    public function test_only_an_admin_may_view_employee_activity(): void
    {
        $seller = User::factory()->create(['role' => UserRole::SalesRep]);
        $manager = User::factory()->create(['role' => UserRole::Manager]);

        // The guest case comes first: actingAs persists across requests within a test, so asserting
        // it afterwards would be testing an authenticated request by mistake.
        $this->get(route('staff.activity', $seller))->assertRedirect(route('login'));
        $this->actingAs($manager)->get(route('staff.activity', $seller))->assertForbidden();
        $this->actingAs($seller)->get(route('staff.activity', $seller))->assertForbidden();
    }
}
