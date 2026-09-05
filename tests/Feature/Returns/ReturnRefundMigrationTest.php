<?php

namespace Tests\Feature\Returns;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Sale;
use App\Models\SaleRefund;
use App\Models\SaleReturn;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ReturnRefundMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_empty_return_chain_rolls_back_and_reapplies(): void
    {
        [$base, $financials, $protection] = $this->migrations();
        try {
            $protection->down();
            $financials->down();
            $base->down();
            $this->assertFalse(Schema::hasTable('sale_returns'));
            $this->assertFalse(Schema::hasColumn('sales', 'returned_amount'));
        } finally {
            $base->up();
            $financials->up();
            $protection->up();
        }
        $this->assertTrue(Schema::hasColumn('sale_returns', 'sale_return_request_id'));
        $this->assertTrue(Schema::hasColumn('sales', 'refundable_credit'));
    }

    public function test_return_history_refuses_every_destructive_down_path(): void
    {
        $return = $this->seedReturn();
        try {
            foreach (array_reverse($this->migrations()) as $migration) {
                try {
                    $migration->down();
                    $this->fail('Migration rollback accepted Return history.');
                } catch (RuntimeException) {
                    $this->assertDatabaseHas('sale_returns', ['id' => $return->id]);
                    $this->assertTrue(Schema::hasTable('sale_return_items'));
                }
            }
        } finally {
            $this->cleanHistory();
        }
    }

    public function test_refund_history_refuses_every_destructive_down_path(): void
    {
        $refund = $this->seedRefund();
        try {
            foreach (array_reverse($this->migrations()) as $migration) {
                try {
                    $migration->down();
                    $this->fail('Migration rollback accepted Refund history.');
                } catch (RuntimeException) {
                    $this->assertDatabaseHas('sale_refunds', ['id' => $refund->id]);
                    $this->assertTrue(Schema::hasTable('sale_refund_requests'));
                }
            }
        } finally {
            $this->cleanHistory();
        }
    }

    private function migrations(): array
    {
        return [
            require database_path('migrations/2026_09_04_050000_create_sale_returns_and_refunds.php'),
            require database_path('migrations/2026_09_04_060000_harden_sale_return_financial_aggregates.php'),
            require database_path('migrations/2026_09_04_070000_protect_return_refund_request_evidence.php'),
        ];
    }

    private function seedReturn(): SaleReturn
    {
        $actor = User::factory()->create(['role' => UserRole::Admin]);
        $sale = Sale::factory()->create(['payment_status' => PaymentStatus::Unpaid]);
        $return = new SaleReturn;
        foreach (['return_number' => 'RET-MIGRATION', 'sale_id' => $sale->id, 'customer_id' => $sale->customer_id, 'sale_number_snapshot' => $sale->sale_number, 'customer_code_snapshot' => $sale->customer_code_snapshot, 'customer_name_snapshot' => $sale->customer_name_snapshot, 'returned_by' => $actor->id, 'returned_by_name_snapshot' => $actor->name, 'merchandise_value' => '1.00', 'receivable_reduction' => '1.00', 'refundable_credit_created' => '0.00', 'reason' => 'Migration evidence', 'returned_at' => now()] as $key => $value) {
            $return->$key = $value;
        }
        $return->save();

        return $return;
    }

    private function seedRefund(): SaleRefund
    {
        $actor = User::factory()->create(['role' => UserRole::Admin]);
        $sale = Sale::factory()->create(['subtotal' => '1.00', 'total_amount' => '1.00', 'amount_paid' => '1.00', 'refunded_amount' => '0.00', 'refundable_credit' => '0.00', 'balance_due' => '0.00', 'payment_status' => PaymentStatus::Paid]);
        $refund = new SaleRefund;
        foreach (['refund_number' => 'REF-MIGRATION', 'sale_id' => $sale->id, 'customer_id' => $sale->customer_id, 'sale_number_snapshot' => $sale->sale_number, 'customer_code_snapshot' => $sale->customer_code_snapshot, 'customer_name_snapshot' => $sale->customer_name_snapshot, 'amount' => '1.00', 'payment_method' => 'cash', 'reason' => 'Migration evidence', 'refunded_by' => $actor->id, 'refunded_by_name_snapshot' => $actor->name, 'refunded_at' => now()] as $key => $value) {
            $refund->$key = $value;
        }
        $refund->save();

        return $refund;
    }

    private function cleanHistory(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('sale_return_items')->delete();
        DB::table('sale_returns')->delete();
        DB::table('sale_refunds')->delete();
        DB::table('sale_return_requests')->delete();
        DB::table('sale_refund_requests')->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
