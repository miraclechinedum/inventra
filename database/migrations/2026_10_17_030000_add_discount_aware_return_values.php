<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Returns valued net of the Sale's discount.
 *
 * `return_line_value` stays what the return is worth to the customer. Two snapshots now explain it:
 * `gross_line_value`, the returned goods at the price they were sold for, and `discount_share`, the
 * part of the Sale's discount those goods carried. The CHECK ties the three together.
 *
 * Rows recorded before this keep both snapshots null; nothing historical is rewritten.
 *
 * A return of fully discounted goods is worth nothing to the customer, and is permitted as a
 * goods-only return: the stock may come back, but no balance moves and no credit is created, so
 * nothing can ever be refunded against it. The value CHECKs therefore allow zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_return_items', function (Blueprint $table): void {
            $table->decimal('gross_line_value', 15, 2)->nullable()->after('return_line_value');
            $table->decimal('discount_share', 15, 2)->nullable()->after('gross_line_value');
        });

        $this->dropCheckIfExists('sale_return_items', 'sale_return_items_values_valid');
        DB::statement('ALTER TABLE sale_return_items ADD CONSTRAINT sale_return_items_values_valid CHECK (quantity_returned > 0 AND original_unit_price >= 0 AND return_line_value >= 0)');
        DB::statement('ALTER TABLE sale_return_items ADD CONSTRAINT sale_return_items_value_explained CHECK ((gross_line_value IS NULL AND discount_share IS NULL) OR (gross_line_value IS NOT NULL AND discount_share IS NOT NULL AND gross_line_value >= 0 AND gross_line_value - discount_share = return_line_value))');

        $this->dropCheckIfExists('sale_returns', 'sale_returns_values_valid');
        DB::statement('ALTER TABLE sale_returns ADD CONSTRAINT sale_returns_values_valid CHECK (merchandise_value >= 0 AND receivable_reduction >= 0 AND refundable_credit_created >= 0 AND merchandise_value = receivable_reduction + refundable_credit_created)');
    }

    public function down(): void
    {
        if (DB::table('sale_return_items')->whereNotNull('gross_line_value')->exists()
            || DB::table('sale_returns')->where('merchandise_value', 0)->exists()) {
            throw new RuntimeException('Cannot remove discount-aware return values while returns have been recorded with them.');
        }

        $this->dropCheckIfExists('sale_returns', 'sale_returns_values_valid');
        DB::statement('ALTER TABLE sale_returns ADD CONSTRAINT sale_returns_values_valid CHECK (merchandise_value > 0 AND receivable_reduction >= 0 AND refundable_credit_created >= 0 AND merchandise_value = receivable_reduction + refundable_credit_created)');

        $this->dropCheckIfExists('sale_return_items', 'sale_return_items_value_explained');
        $this->dropCheckIfExists('sale_return_items', 'sale_return_items_values_valid');
        DB::statement('ALTER TABLE sale_return_items ADD CONSTRAINT sale_return_items_values_valid CHECK (quantity_returned > 0 AND original_unit_price >= 0 AND return_line_value > 0)');

        Schema::table('sale_return_items', function (Blueprint $table): void {
            $table->dropColumn(['gross_line_value', 'discount_share']);
        });
    }

    // DROP CONSTRAINT, not DROP CHECK: MariaDB rejects the MySQL-only spelling.
    private function dropCheckIfExists(string $table, string $constraint): void
    {
        $exists = DB::selectOne(
            'SELECT 1 AS found FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = database() AND CONSTRAINT_NAME = ?',
            [$constraint],
        );

        if ($exists !== null) {
            DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT '.$constraint);
        }
    }
};
