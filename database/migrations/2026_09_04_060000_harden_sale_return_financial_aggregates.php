<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->decimal('returned_amount', 15, 2)->default(0)->after('total_amount');
            $table->decimal('refunded_amount', 15, 2)->default(0)->after('amount_paid');
            $table->decimal('refundable_credit', 15, 2)->default(0)->after('balance_due');
        });
        DB::statement('ALTER TABLE sales DROP CHECK sales_total_matches_payment');
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_return_financials_reconcile CHECK (returned_amount >= 0 AND returned_amount <= total_amount AND refunded_amount >= 0 AND refunded_amount <= amount_paid AND refundable_credit >= 0 AND total_amount - returned_amount + refundable_credit = amount_paid - refunded_amount + balance_due)');
    }

    public function down(): void
    {
        if (DB::table('sale_returns')->exists() || DB::table('sale_refunds')->exists()) {
            throw new RuntimeException('Cannot remove Return financial aggregates while Return or Refund history exists.');
        }
        DB::statement('ALTER TABLE sales DROP CHECK sales_return_financials_reconcile');
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn(['returned_amount', 'refunded_amount', 'refundable_credit']));
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_total_matches_payment CHECK (total_amount = amount_paid + balance_due)');
    }
};
