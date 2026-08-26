<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sales ADD sold_by_name_snapshot VARCHAR(255) NULL AFTER sold_by');
        DB::statement('UPDATE sales INNER JOIN users ON users.id = sales.sold_by SET sales.sold_by_name_snapshot = users.name');
        DB::statement('ALTER TABLE sales MODIFY sold_by_name_snapshot VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_total_matches_payment CHECK (total_amount = amount_paid + balance_due)');
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_total_matches_subtotal_discount CHECK (total_amount = subtotal - discount_amount)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sales DROP CHECK sales_total_matches_payment');
        DB::statement('ALTER TABLE sales DROP CHECK sales_total_matches_subtotal_discount');
        DB::statement('ALTER TABLE sales DROP COLUMN sold_by_name_snapshot');
    }
};
