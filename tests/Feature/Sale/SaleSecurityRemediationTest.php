<?php

namespace Tests\Feature\Sale;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\SaleNumber;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SaleSecurityRemediationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql_enforces_payment_and_discount_reconciliation(): void
    {
        $sale = Sale::factory()->create();

        DB::table('sales')->where('id', $sale->id)->update([
            'amount_paid' => '25.00',
            'balance_due' => '75.00',
            'payment_status' => PaymentStatus::Partial->value,
        ]);
        $this->assertSame('100.00', Sale::query()->findOrFail($sale->id)->total_amount);

        $this->assertCheckRejects(fn () => DB::table('sales')->where('id', $sale->id)->update(['balance_due' => '74.99']));
        $this->assertCheckRejects(fn () => DB::table('sales')->where('id', $sale->id)->update(['discount_amount' => '0.01']));

        $sale->refresh();
        $this->assertSame('100.00', bcadd($sale->amount_paid, $sale->balance_due, 2));
        $this->assertSame('100.00', bcsub($sale->subtotal, $sale->discount_amount, 2));
    }

    public function test_factory_snapshots_match_related_customer_and_seller(): void
    {
        $customer = Customer::factory()->create();
        $seller = User::factory()->create();
        $sale = Sale::factory()->create(['customer_id' => $customer, 'sold_by' => $seller]);

        $this->assertSame($customer->customer_code, $sale->customer_code_snapshot);
        $this->assertSame($customer->full_name, $sale->customer_name_snapshot);
        $this->assertSame($customer->phone, $sale->customer_phone_snapshot);
        $this->assertSame($seller->name, $sale->sold_by_name_snapshot);
    }

    public function test_sale_numbers_do_not_truncate_ids_beyond_six_digits(): void
    {
        $this->assertSame('SALE-1000000', SaleNumber::fromId(1000000));
        $this->assertSame('SALE-999999999999', SaleNumber::fromId(999999999999));
    }

    public function test_sales_search_escapes_wildcards_and_ignores_array_filters(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $percentCustomer = Customer::factory()->create(['first_name' => '% Percent', 'last_name' => 'Buyer']);
        $underscoreCustomer = Customer::factory()->create(['first_name' => '_ Under', 'last_name' => 'Buyer']);
        $backslashCustomer = Customer::factory()->create(['first_name' => '\\ Backslash', 'last_name' => 'Buyer']);
        $percentSale = Sale::factory()->create(['customer_id' => $percentCustomer]);
        $underscoreSale = Sale::factory()->create(['customer_id' => $underscoreCustomer]);
        $backslashSale = Sale::factory()->create(['customer_id' => $backslashCustomer]);
        $ordinarySale = Sale::factory()->create();

        $this->actingAs($admin)->get(route('sales.index', ['search' => '%']))
            ->assertOk()
            ->assertSee($percentSale->sale_number)
            ->assertDontSee($underscoreSale->sale_number)
            ->assertDontSee($ordinarySale->sale_number);

        $this->get(route('sales.index', ['search' => '_']))
            ->assertSee($underscoreSale->sale_number)
            ->assertDontSee($ordinarySale->sale_number);
        $this->get(route('sales.index', ['search' => '\\']))
            ->assertSee($backslashSale->sale_number)
            ->assertDontSee($ordinarySale->sale_number);

        $this->get(route('sales.index', [
            'search' => ['bad'],
            'status' => ['completed'],
            'payment_method' => ['cash'],
            'payment_status' => ['paid'],
            'sold_by' => ['1'],
            'from' => ['2026-01-01'],
            'to' => ['2026-12-31'],
        ]))->assertOk()->assertSee($ordinarySale->sale_number);
    }

    public function test_sale_audit_sanitization_rejects_secret_and_non_scalar_injection(): void
    {
        $actor = User::factory()->create();
        $sale = Sale::factory()->create();

        app(AuditLogger::class)->record('sale_created', $sale, $actor,
            newValues: ['password' => 'secret', 'total_amount' => new \stdClass],
            metadata: ['token' => 'secret', 'item_count' => new \stdClass]);

        $audit = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertNull($audit->new_values);
        $this->assertNull($audit->metadata);
        $this->assertStringNotContainsString('secret', json_encode($audit->getAttributes(), JSON_THROW_ON_ERROR));
    }

    private function assertCheckRejects(callable $write): void
    {
        try {
            $write();
            $this->fail('MySQL should reject an inconsistent monetary write.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
