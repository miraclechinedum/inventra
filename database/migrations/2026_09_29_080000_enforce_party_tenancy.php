<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract: customers, suppliers and expense categories are owned by a Business.
 *
 * Customer phone and code, supplier code and expense-category code become unique within a Business:
 * two businesses may each have a customer on the same number. `whatsapp_phone` stays non-unique — it
 * is a destination, not an identity. `(business_id, id)` is added for the composite foreign keys the
 * sales, purchase and expense documents will take when they become tenant-owned.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> table => columns that become unique per business */
    private const UNIQUES = [
        'customers' => ['phone', 'customer_code'],
        'suppliers' => ['supplier_code'],
        'expense_categories' => ['category_code'],
    ];

    public function up(): void
    {
        foreach (self::UNIQUES as $table => $columns) {
            if (DB::table($table)->whereNull('business_id')->exists()) {
                throw new RuntimeException("{$table} has rows without a business.");
            }

            foreach ($columns as $column) {
                if ($this->duplicates($table, ['business_id', $column])) {
                    throw new RuntimeException("A business has duplicate {$table}.{$column} values.");
                }
            }
        }

        foreach (self::UNIQUES as $table => $columns) {
            DB::statement("ALTER TABLE {$table} MODIFY business_id BIGINT UNSIGNED NOT NULL");

            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns): void {
                $blueprint->unique(['business_id', 'id'], "{$table}_business_id_id_unique");

                foreach ($columns as $column) {
                    $blueprint->unique(['business_id', $column], "{$table}_business_id_{$column}_unique");
                    $blueprint->dropUnique([$column]);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::UNIQUES as $table => $columns) {
            foreach ($columns as $column) {
                if ($this->duplicates($table, [$column])) {
                    throw new RuntimeException("Two businesses share a {$table}.{$column} value; global uniqueness cannot be restored.");
                }
            }
        }

        foreach (self::UNIQUES as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns): void {
                $blueprint->dropForeign(['business_id']);

                foreach ($columns as $column) {
                    $blueprint->unique($column);
                    $blueprint->dropUnique("{$table}_business_id_{$column}_unique");
                }

                $blueprint->dropUnique("{$table}_business_id_id_unique");
            });

            DB::statement("ALTER TABLE {$table} MODIFY business_id BIGINT UNSIGNED NULL");

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            });
        }
    }

    /** @param  list<string>  $columns */
    private function duplicates(string $table, array $columns): bool
    {
        return DB::table($table)->select($columns)->groupBy($columns)->havingRaw('COUNT(*) > 1')->exists();
    }
};
