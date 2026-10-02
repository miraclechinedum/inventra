<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand and backfill: sales and sale drafts gain a Business, derived from what already owns them.
 *
 *  - A registered-customer sale belongs to its customer's Business; a walk-in sale, which has no
 *    customer, to its seller's.
 *  - A draft belongs to the Business of the user who created it.
 *
 * Every other reference is then cross-checked against that owner — seller, voider, draft customer,
 * consuming sale — and any disagreement stops the migration. Historical data is never repaired by
 * choosing one side.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Resumable: a column already added by an interrupted run is kept, and only unowned rows are
        // backfilled, so the migration can simply be run again.
        foreach (['sales', 'sale_drafts'] as $table) {
            if (Schema::hasColumn($table, 'business_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->restrictOnDelete();
            });
        }

        DB::statement('UPDATE sales s JOIN customers c ON c.id = s.customer_id SET s.business_id = c.business_id WHERE s.business_id IS NULL');
        DB::statement('UPDATE sales s JOIN users u ON u.id = s.sold_by SET s.business_id = u.business_id WHERE s.business_id IS NULL AND s.customer_id IS NULL');
        DB::statement('UPDATE sale_drafts d JOIN users u ON u.id = d.created_by SET d.business_id = u.business_id WHERE d.business_id IS NULL');

        foreach (['sales', 'sale_drafts'] as $table) {
            $this->refuseWhen(DB::table($table)->whereNull('business_id')->exists(), "Backfill left {$table} rows without a business.");
        }

        foreach ([
            ['sales', 'sold_by', 'users', 'A sale was sold by a user of another business.'],
            ['sales', 'voided_by', 'users', 'A sale was voided by a user of another business.'],
            ['sale_drafts', 'customer_id', 'customers', 'A draft names a customer of another business.'],
            ['sale_drafts', 'consumed_by_sale_id', 'sales', 'A draft was consumed by a sale of another business.'],
        ] as [$child, $column, $parent, $reason]) {
            $this->refuseWhen($this->mismatches($child, $column, $parent) > 0, $reason);
        }
    }

    public function down(): void
    {
        $this->refuseWhen(DB::table('businesses')->count() > 1, 'Refusing to drop sale ownership while more than one business exists.');

        foreach (['sale_drafts', 'sales'] as $table) {
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
