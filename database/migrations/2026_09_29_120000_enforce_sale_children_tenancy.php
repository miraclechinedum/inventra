<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract: every record hanging from a sale is owned by the sale's Business, and every product,
 * customer, correction, return and sale line it names must belong to that same Business.
 *
 * For every table: `business_id` becomes NOT NULL; each human-facing number listed becomes unique
 * within a Business instead of across the platform; and each listed reference gains a composite
 * foreign key onto the parent's `(business_id, id)`, so the database itself refuses a row that
 * points at another tenant's record. The existing single-column keys stay, keeping their delete
 * behaviour. Nothing is checked twice by accident: the explicit verification runs first so a
 * failure names the broken relationship, and MySQL then validates every key as it is added.
 */
return new class extends Migration
{
    /** @var array<string, array{keyed: bool, unique: list<string>, foreign: list<array{0: string, 1: string}>}> */
    private const TABLES = [
        'sale_corrections' => ['keyed' => true, 'unique' => [], 'foreign' => [['sale_id', 'sales'], ['customer_id_before', 'customers'], ['customer_id_after', 'customers'], ['corrected_by', 'users']]],
        'sale_items' => ['keyed' => true, 'unique' => [], 'foreign' => [['sale_id', 'sales'], ['product_id', 'products'], ['sale_correction_id', 'sale_corrections'], ['superseded_by_correction_id', 'sale_corrections']]],
        'sale_payments' => ['keyed' => false, 'unique' => ['payment_number'], 'foreign' => [['sale_id', 'sales'], ['customer_id', 'customers'], ['recorded_by', 'users']]],
        'sale_discount_requests' => ['keyed' => false, 'unique' => [], 'foreign' => [['sale_id', 'sales'], ['sale_draft_id', 'sale_drafts'], ['requested_by', 'users'], ['decided_by', 'users']]],
        'sale_returns' => ['keyed' => true, 'unique' => ['return_number'], 'foreign' => [['sale_id', 'sales'], ['customer_id', 'customers'], ['returned_by', 'users']]],
        'sale_return_items' => ['keyed' => false, 'unique' => [], 'foreign' => [['sale_return_id', 'sale_returns'], ['sale_item_id', 'sale_items'], ['product_id', 'products']]],
        'sale_refunds' => ['keyed' => false, 'unique' => ['refund_number'], 'foreign' => [['sale_id', 'sales'], ['sale_return_id', 'sale_returns'], ['customer_id', 'customers'], ['refunded_by', 'users']]],
    ];

    /** @var array<string, string> inventory_movements.reference_type => document table */
    private const MOVEMENT_REFERENCES = ['App\\Models\\Sale' => 'sales', 'App\\Models\\SaleReturn' => 'sale_returns'];

    public function up(): void
    {
        foreach (self::TABLES as $table => $spec) {
            $this->refuseWhen(DB::table($table)->whereNull('business_id')->exists(), "{$table} has rows without a business.");

            foreach ($spec['foreign'] as [$column, $parent]) {
                $this->refuseWhen($this->mismatches($table, $column, $parent) > 0, "{$table}.{$column} references a {$parent} row of another business.");
            }

            foreach ($spec['unique'] as $column) {
                $this->refuseWhen($this->duplicates($table, ['business_id', $column]), "A business has duplicate {$table}.{$column} values.");
            }
        }

        foreach (self::MOVEMENT_REFERENCES as $type => $table) {
            $this->refuseWhen($this->movementMismatches($type, $table) > 0, "Stock movements reference {$table} rows of another business.");
        }

        foreach (self::TABLES as $table => $spec) {
            // MySQL can refuse to tighten a column its own foreign key uses (error 1832), so the
            // key is lifted for the ALTER and restored immediately after.
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['business_id']);
            });
            DB::statement("ALTER TABLE {$table} MODIFY business_id BIGINT UNSIGNED NOT NULL");
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            });

            Schema::table($table, function (Blueprint $blueprint) use ($table, $spec): void {
                if ($spec['keyed']) {
                    $blueprint->unique(['business_id', 'id'], "{$table}_business_id_id_unique");
                }

                foreach ($spec['unique'] as $column) {
                    $blueprint->unique(['business_id', $column], "{$table}_business_id_{$column}_unique");
                    $blueprint->dropUnique([$column]);
                }
            });
        }

        // Keys only once every parent's (business_id, id) exists.
        foreach (self::TABLES as $table => $spec) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $spec): void {
                foreach ($spec['foreign'] as [$column, $parent]) {
                    $blueprint->foreign(['business_id', $column], $this->foreignName($table, $column))
                        ->references(['business_id', 'id'])->on($parent)->restrictOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        // Global uniqueness can only return if no two businesses share a number; otherwise
        // restoring it would mean renaming or deleting another tenant's records, which this never does.
        foreach (self::TABLES as $table => $spec) {
            foreach ($spec['unique'] as $column) {
                $this->refuseWhen($this->duplicates($table, [$column]), "Two businesses share a {$table}.{$column} value; global uniqueness cannot be restored.");
            }
        }

        foreach (array_reverse(self::TABLES, true) as $table => $spec) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $spec): void {
                foreach ($spec['foreign'] as [$column]) {
                    $blueprint->dropForeign($this->foreignName($table, $column));
                }
            });

        }

        foreach (array_reverse(self::TABLES, true) as $table => $spec) {
            // MySQL keeps the index it created for each dropped key, and it may be the one serving
            // business_id's own key. Left behind it would collide with the key's name when the
            // migration is applied again, so it goes once that key has been lifted.
            $leftovers = array_values(array_filter(
                array_map(fn (array $foreign): string => $this->foreignName($table, $foreign[0]), $spec['foreign']),
                fn (string $index): bool => $this->indexExists($table, $index),
            ));

            // The business_id key is rebuilt around the indexes that may be serving it.
            Schema::table($table, function (Blueprint $blueprint) use ($table, $spec, $leftovers): void {
                $blueprint->dropForeign(['business_id']);

                foreach ($leftovers as $index) {
                    $blueprint->dropIndex($index);
                }

                foreach ($spec['unique'] as $column) {
                    $blueprint->unique($column);
                    $blueprint->dropUnique("{$table}_business_id_{$column}_unique");
                }

                if ($spec['keyed']) {
                    $blueprint->dropUnique("{$table}_business_id_id_unique");
                }
            });

            DB::statement("ALTER TABLE {$table} MODIFY business_id BIGINT UNSIGNED NULL");

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            });
        }
    }

    private function foreignName(string $table, string $column): string
    {
        return substr($table.'_'.$column, 0, 50).'_tenant_fk';
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index]
        ) !== null;
    }

    private function mismatches(string $child, string $column, string $parent): int
    {
        return (int) DB::table($child)
            ->join($parent, "{$parent}.id", '=', "{$child}.{$column}")
            ->whereColumn("{$parent}.business_id", '<>', "{$child}.business_id")
            ->count();
    }

    /** A stock movement recorded against one of these documents must share its Business. */
    private function movementMismatches(string $type, string $table): int
    {
        return (int) DB::table('inventory_movements')
            ->join($table, "{$table}.id", '=', 'inventory_movements.reference_id')
            ->where('inventory_movements.reference_type', $type)
            ->whereColumn("{$table}.business_id", '<>', 'inventory_movements.business_id')
            ->count();
    }

    /** @param  list<string>  $columns */
    private function duplicates(string $table, array $columns): bool
    {
        return DB::table($table)->select($columns)->groupBy($columns)->havingRaw('COUNT(*) > 1')->exists();
    }

    private function refuseWhen(bool $condition, string $reason): void
    {
        if ($condition) {
            throw new RuntimeException($reason);
        }
    }
};
