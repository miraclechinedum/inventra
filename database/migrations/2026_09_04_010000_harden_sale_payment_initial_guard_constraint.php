<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sale_payments DROP CHECK sale_payments_initial_guard_valid');
        DB::statement("ALTER TABLE sale_payments ADD CONSTRAINT sale_payments_initial_guard_valid CHECK ((payment_type = 'initial' AND initial_sale_guard IS NOT NULL AND initial_sale_guard = sale_id) OR (payment_type = 'settlement' AND initial_sale_guard IS NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sale_payments DROP CHECK sale_payments_initial_guard_valid');
        DB::statement("ALTER TABLE sale_payments ADD CONSTRAINT sale_payments_initial_guard_valid CHECK ((payment_type = 'initial' AND initial_sale_guard = sale_id) OR (payment_type = 'settlement' AND initial_sale_guard IS NULL))");
    }
};
