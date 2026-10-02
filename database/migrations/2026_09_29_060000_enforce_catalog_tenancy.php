<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract: catalog ownership is enforced by the database.
 *
 *  - `business_id` is NOT NULL on categories, products and movements.
 *  - Category names and SKUs are unique within a Business, not across the platform.
 *  - A product's category and a movement's product must belong to the same Business: composite
 *    foreign keys onto `(business_id, id)` reject a cross-tenant reference even when application
 *    validation is bypassed. The existing single-column keys stay, keeping their delete behaviour.
 *
 * Nothing references a movement, so movements need no `(business_id, id)` key of their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['product_categories', 'products', 'inventory_movements'] as $table) {
            $this->refuseWhen(DB::table($table)->whereNull('business_id')->exists(), "{$table} has rows without a business.");
        }

        $this->refuseWhen($this->mismatches('products', 'category_id', 'product_categories') > 0, 'Products reference categories of another business.');
        $this->refuseWhen($this->mismatches('inventory_movements', 'product_id', 'products') > 0, 'Movements reference products of another business.');
        $this->refuseWhen($this->duplicates('product_categories', ['business_id', 'name']), 'A business has duplicate category names.');
        $this->refuseWhen($this->duplicates('products', ['business_id', 'sku']), 'A business has duplicate SKUs.');

        foreach (['product_categories', 'products', 'inventory_movements'] as $table) {
            DB::statement("ALTER TABLE {$table} MODIFY business_id BIGINT UNSIGNED NOT NULL");
        }

        Schema::table('product_categories', function (Blueprint $table): void {
            $table->unique(['business_id', 'id'], 'product_categories_business_id_id_unique');
            $table->unique(['business_id', 'name'], 'product_categories_business_id_name_unique');
            $table->dropUnique(['name']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->unique(['business_id', 'id'], 'products_business_id_id_unique');
            $table->unique(['business_id', 'sku'], 'products_business_id_sku_unique');
            $table->dropUnique(['sku']);
            $table->foreign(['business_id', 'category_id'], 'products_business_category_foreign')
                ->references(['business_id', 'id'])->on('product_categories')->restrictOnDelete();
        });

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->foreign(['business_id', 'product_id'], 'inventory_movements_business_product_foreign')
                ->references(['business_id', 'id'])->on('products')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Global uniqueness can only come back if no two businesses share a value; restoring it
        // would otherwise mean deleting or renaming another tenant's data, which this never does.
        $this->refuseWhen($this->duplicates('product_categories', ['name']), 'Two businesses share a category name; global uniqueness cannot be restored.');
        $this->refuseWhen($this->duplicates('products', ['sku']), 'Two businesses share a SKU; global uniqueness cannot be restored.');

        // MySQL keeps the index it created for a dropped key, and it may be the one serving the
        // business_id key; left behind it would collide with the key's name on a re-apply. So each
        // is dropped only after business_id's key has been lifted, and that key is rebuilt below.
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropForeign('inventory_movements_business_product_foreign');
            $table->dropForeign(['business_id']);
        });
        $this->dropIndexIfExists('inventory_movements', 'inventory_movements_business_product_foreign');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign('products_business_category_foreign');
            $table->dropForeign(['business_id']);
        });
        $this->dropIndexIfExists('products', 'products_business_category_foreign');

        Schema::table('products', function (Blueprint $table): void {
            $table->unique('sku');
            $table->dropUnique('products_business_id_sku_unique');
            $table->dropUnique('products_business_id_id_unique');
        });

        Schema::table('product_categories', function (Blueprint $table): void {
            $table->dropForeign(['business_id']);
            $table->unique('name');
            $table->dropUnique('product_categories_business_id_name_unique');
            $table->dropUnique('product_categories_business_id_id_unique');
        });

        foreach (['product_categories', 'products', 'inventory_movements'] as $table) {
            DB::statement("ALTER TABLE {$table} MODIFY business_id BIGINT UNSIGNED NULL");
        }

        foreach (['product_categories', 'products', 'inventory_movements'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            });
        }
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        $exists = DB::selectOne(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index]
        ) !== null;

        if ($exists) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($index));
        }
    }

    private function mismatches(string $child, string $column, string $parent): int
    {
        return (int) DB::table($child)
            ->join($parent, "{$parent}.id", '=', "{$child}.{$column}")
            ->whereColumn("{$parent}.business_id", '<>', "{$child}.business_id")
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
