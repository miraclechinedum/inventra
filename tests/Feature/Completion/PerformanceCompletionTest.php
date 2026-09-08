<?php

namespace Tests\Feature\Completion;

use App\Actions\Sale\CreateSale;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PerformanceCompletionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startSession();
        $this->withCookie(config('session.cookie'), $this->app['session']->driver()->getId());
    }

    public function test_void_metric_applies_each_filter_to_void_event_date(): void
    {
        $a = User::factory()->create(['role' => UserRole::Admin]);
        $b = User::factory()->create();
        $x = Customer::factory()->create();
        $y = Customer::factory()->create();
        foreach ([[$a, $x], [$a, $y], [$b, $x], [$b, $y]] as [$staff, $customer]) {
            Sale::factory()->create(['sold_by' => $staff->id, 'customer_id' => $customer->id, 'status' => 'voided', 'created_at' => '2026-08-01 12:00:00', 'voided_at' => '2026-09-05 12:00:00']);
        }
        Sale::factory()->create(['sold_by' => $a->id, 'customer_id' => $x->id, 'status' => 'voided', 'voided_at' => '2026-08-05 12:00:00']);
        $this->actingAs($a);
        foreach ([[[], 4], [['staff' => $a->id], 2], [['customer' => $x->id], 2], [['staff' => $a->id, 'customer' => $x->id], 1]] as [$filters, $expected]) {
            $response = $this->get(route('reports.sales', $filters + ['from' => '2026-09-01', 'to' => '2026-09-30']))->assertOk();
            $metric = collect($response->viewData('metrics'))->firstWhere('label', 'Voided in Period');
            $this->assertSame($expected, $metric['value']);
        }
    }

    public function test_performance_includes_collection_only_and_mixed_activity_without_duplicate_payments(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $collector = User::factory()->create(['role' => UserRole::Manager]);
        $seller = User::factory()->create(['role' => UserRole::SalesRep, 'name' => 'Period Seller']);
        $customer = Customer::factory()->create(['first_name' => 'Period', 'last_name' => 'Customer']);
        $other = Customer::factory()->create();
        $product = Product::factory()->create(['selling_price' => '100.00', 'current_stock' => '20.000']);
        $this->travelTo(now()->setDate(2026, 8, 15)->setTime(12, 0));
        $sale = app(CreateSale::class)->execute($seller, ['customer_id' => $customer->id, 'products' => [['product_id' => $product->id, 'quantity' => '1']], 'payment_method' => 'cash', 'amount_paid' => '0']);
        $this->travelTo(now()->setDate(2026, 9, 5));
        $this->actingAs($collector);
        $token = $this->get(route('sales.show', $sale))->viewData('paymentToken');
        $this->post(route('sales.payments.store', $sale), ['request_token' => $token, 'amount' => '30', 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $range = ['from' => '2026-09-01', 'to' => '2026-09-30'];
        $seller->update(['name' => 'Renamed Seller']);
        $customer->update(['first_name' => 'Renamed']);
        $this->actingAs($admin);
        $customers = $this->get(route('reports.customers', $range))->assertOk()->assertSee('Latest sale in period: None')->assertSee('Renamed')->viewData('rows');
        $row = $customers->sole();
        $this->assertSame($customer->id, $row->id);
        $this->assertSame('30.00', $row->collected);
        $this->assertSame('0.00', $row->sales_value);
        $this->assertNull($row->latest_sale);
        $staff = $this->get(route('reports.staff', $range))->assertOk()->assertSee('Renamed Seller')->viewData('rows')->sole();
        $this->assertSame($seller->id, $staff->id);
        $this->assertSame('30.00', $staff->seller_sale_collections);
        $this->get(route('reports.customers', $range + ['customer' => $other->id]))->assertViewHas('rows', fn ($rows) => $rows->isEmpty());
        $this->get(route('reports.staff', $range + ['staff' => $collector->id]))->assertViewHas('rows', fn ($rows) => $rows->isEmpty());

        app(CreateSale::class)->execute($seller, ['customer_id' => $customer->id, 'products' => [['product_id' => $product->id, 'quantity' => '1']], 'payment_method' => 'cash', 'amount_paid' => '20']);
        $row = $this->get(route('reports.customers', $range))->viewData('rows')->sole();
        $this->assertSame('100.00', $row->sales_value);
        $this->assertSame('50.00', $row->collected);
        $this->assertSame('80.00', $row->outstanding);
        $this->assertSame('50.00', $this->get(route('reports.staff', $range))->viewData('rows')->sole()->seller_sale_collections);
        $this->actingAs($seller)->get(route('reports.customers', $range))->assertForbidden();
        $this->get(route('reports.staff', $range))->assertForbidden();
        $this->travelBack();
    }
}
