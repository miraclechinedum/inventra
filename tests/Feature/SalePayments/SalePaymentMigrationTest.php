<?php

namespace Tests\Feature\SalePayments;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalePaymentMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_truthfully_backfills_paid_and_partial_sales_once_without_changing_aggregates(): void
    {
        $migration = require database_path('migrations/2026_09_03_010000_create_sale_payments_table.php');
        $constraints = require database_path('migrations/2026_09_03_011000_add_sale_payment_integrity_constraints.php');
        $migration->down();
        $seller = User::factory()->create(['role' => UserRole::SalesRep]);
        $customer = Customer::factory()->create();
        $paid = $this->historicalSale($seller, $customer, '100.00', '0.00', PaymentStatus::Paid, PaymentMethod::Pos);
        $partial = $this->historicalSale($seller, $customer, '40.00', '60.00', PaymentStatus::Partial, PaymentMethod::Transfer);
        $unpaid = $this->historicalSale($seller, $customer, '0.00', '100.00', PaymentStatus::Unpaid, PaymentMethod::Cash);
        $before = DB::table('sales')->orderBy('id')->get(['id', 'amount_paid', 'balance_due', 'payment_status'])->toArray();

        $migration->up();
        $constraints->up();

        $this->assertDatabaseCount('sale_payments', 2);
        foreach ([[$paid, '100.00', 'pos'], [$partial, '40.00', 'transfer']] as [$sale, $amount, $method]) {
            $this->assertDatabaseHas('sale_payments', [
                'sale_id' => $sale->id, 'customer_id' => $customer->id, 'amount' => $amount,
                'payment_method' => $method, 'payment_type' => 'initial', 'initial_sale_guard' => $sale->id,
                'recorded_by' => $seller->id, 'recorded_by_name_snapshot' => $seller->name,
            ]);
        }
        $this->assertDatabaseMissing('sale_payments', ['sale_id' => $unpaid->id]);
        $this->assertEquals($before, DB::table('sales')->orderBy('id')->get(['id', 'amount_paid', 'balance_due', 'payment_status'])->toArray());

        try {
            DB::table('sale_payments')->insert(array_merge(
                (array) DB::table('sale_payments')->where('sale_id', $paid->id)->first(),
                ['id' => null, 'payment_number' => 'PAY-DUPLICATE']
            ));
            $this->fail('The initial-payment guard must reject duplicates.');
        } catch (QueryException) {
            $this->assertDatabaseCount('sale_payments', 2);
        }

        try {
            $migration->down();
            $this->fail('Payment history must prevent destructive rollback.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('sale_payments', 2);
        }
    }

    private function historicalSale(User $seller, Customer $customer, string $paid, string $balance, PaymentStatus $status, PaymentMethod $method): Sale
    {
        return Sale::factory()->create([
            'customer_id' => $customer->id, 'sold_by' => $seller->id, 'payment_method' => $method,
            'payment_status' => $status, 'amount_paid' => $paid, 'balance_due' => $balance,
        ]);
    }
}
