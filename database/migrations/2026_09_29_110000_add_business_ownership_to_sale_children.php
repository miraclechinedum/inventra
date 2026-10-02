<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand and backfill: every record hanging from a sale gains the Business of the document it
 * hangs from — never a constant.
 *
 *  - Corrections, lines, payments, returns and refunds take their sale's Business.
 *  - Return lines take their return's Business.
 *  - A discount request takes its sale's Business, or its draft's when it was raised before sale.
 *
 * Every other reference each row carries — product, customer, correction, sale line, return, the
 * staff who acted — is then cross-checked against that owner, and a disagreement stops the
 * migration rather than being repaired.
 */
return new class extends Migration
{
    private const TABLES = [
        'sale_corrections', 'sale_items', 'sale_payments', 'sale_discount_requests',
        'sale_returns', 'sale_return_items', 'sale_refunds',
    ];

    public function up(): void
    {
        // Resumable: a column already added by an interrupted run is kept, and only unowned rows are
        // backfilled, so the migration can simply be run again.
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'business_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->restrictOnDelete();
            });
        }

        foreach (['sale_corrections', 'sale_items', 'sale_payments', 'sale_returns', 'sale_refunds'] as $table) {
            DB::statement("UPDATE {$table} c JOIN sales s ON s.id = c.sale_id SET c.business_id = s.business_id WHERE c.business_id IS NULL");
        }

        DB::statement('UPDATE sale_discount_requests r JOIN sales s ON s.id = r.sale_id SET r.business_id = s.business_id WHERE r.business_id IS NULL');
        DB::statement('UPDATE sale_discount_requests r JOIN sale_drafts d ON d.id = r.sale_draft_id SET r.business_id = d.business_id WHERE r.business_id IS NULL');
        DB::statement('UPDATE sale_return_items i JOIN sale_returns r ON r.id = i.sale_return_id SET i.business_id = r.business_id WHERE i.business_id IS NULL');

        foreach (self::TABLES as $table) {
            $this->refuseWhen(DB::table($table)->whereNull('business_id')->exists(), "Backfill left {$table} rows without a business.");
        }

        foreach ([
            ['sale_corrections', 'customer_id_before', 'customers'], ['sale_corrections', 'customer_id_after', 'customers'],
            ['sale_corrections', 'corrected_by', 'users'],
            ['sale_items', 'product_id', 'products'], ['sale_items', 'sale_correction_id', 'sale_corrections'],
            ['sale_items', 'superseded_by_correction_id', 'sale_corrections'],
            ['sale_payments', 'customer_id', 'customers'], ['sale_payments', 'recorded_by', 'users'],
            // A request naming both a sale and a draft must find them in the same Business.
            ['sale_discount_requests', 'sale_id', 'sales'], ['sale_discount_requests', 'sale_draft_id', 'sale_drafts'],
            ['sale_discount_requests', 'requested_by', 'users'], ['sale_discount_requests', 'decided_by', 'users'],
            ['sale_returns', 'customer_id', 'customers'], ['sale_returns', 'returned_by', 'users'],
            ['sale_return_items', 'sale_item_id', 'sale_items'], ['sale_return_items', 'product_id', 'products'],
            ['sale_refunds', 'sale_return_id', 'sale_returns'], ['sale_refunds', 'customer_id', 'customers'],
            ['sale_refunds', 'refunded_by', 'users'],
        ] as [$child, $column, $parent]) {
            $this->refuseWhen($this->mismatches($child, $column, $parent) > 0, "{$child}.{$column} references a {$parent} row of another business.");
        }
    }

    public function down(): void
    {
        $this->refuseWhen(DB::table('businesses')->count() > 1, 'Refusing to drop sale-record ownership while more than one business exists.');

        foreach (array_reverse(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('business_id');
            });
        }
    }

    private function mismatches(string $child, string $column, string $parent): int
    {
        return (int) DB::table($child)
            ->join($parent, "{$parent}.id", '=', "{$child}.{$column}")
            ->whereColumn("{$parent}.business_id", '<>', "{$child}.business_id")
            ->count();
    }

    private function refuseWhen(bool $condition, string $reason): void
    {
        if ($condition) {
            throw new RuntimeException($reason);
        }
    }
};
