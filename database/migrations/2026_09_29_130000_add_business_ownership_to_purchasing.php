<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand and backfill: a purchase belongs to its supplier's Business and a purchase line to its
 * purchase's. The receiving user and each line's product are cross-checked against that owner, and a
 * disagreement stops the migration rather than being repaired.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Resumable: a column already added by an interrupted run is kept, and only unowned rows are
        // backfilled, so the migration can simply be run again.
        foreach (['purchases', 'purchase_items'] as $table) {
            if (Schema::hasColumn($table, 'business_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->restrictOnDelete();
            });
        }

        DB::statement('UPDATE purchases p JOIN suppliers s ON s.id = p.supplier_id SET p.business_id = s.business_id WHERE p.business_id IS NULL');
        DB::statement('UPDATE purchase_items i JOIN purchases p ON p.id = i.purchase_id SET i.business_id = p.business_id WHERE i.business_id IS NULL');

        foreach (['purchases', 'purchase_items'] as $table) {
            $this->refuseWhen(DB::table($table)->whereNull('business_id')->exists(), "Backfill left {$table} rows without a business.");
        }

        $this->refuseWhen($this->mismatches('purchases', 'received_by', 'users') > 0, 'A purchase was received by a user of another business.');
        $this->refuseWhen($this->mismatches('purchase_items', 'product_id', 'products') > 0, 'A purchase line names a product of another business.');
    }

    public function down(): void
    {
        $this->refuseWhen(DB::table('businesses')->count() > 1, 'Refusing to drop purchase ownership while more than one business exists.');

        foreach (['purchase_items', 'purchases'] as $table) {
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
