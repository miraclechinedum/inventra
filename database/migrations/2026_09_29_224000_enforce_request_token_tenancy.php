<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract: a request token, its operator, its sale and its result belong to one Business.
 *
 * Composite keys onto (business_id, id) keep each existing delete behaviour. The purchase and sale
 * payment results are SET NULL references, which a composite key cannot express without nulling the
 * Business too; those two stay single-column and are verified here and by the issuing actions.
 * The token hash stays globally unique.
 */
return new class extends Migration
{
    /** @var array<string, list<array{0: string, 1: list<string>, 2: string, 3: string}>> */
    private const TENANT_KEYS = [
        'expense_requests' => [
            ['expense_requests_actor_tenant_fk', ['business_id', 'actor_id'], 'users', 'cascade'],
            ['expense_requests_expense_tenant_fk', ['business_id', 'expense_id'], 'expenses', 'restrict'],
        ],
        'purchase_requests' => [
            ['purchase_requests_actor_tenant_fk', ['business_id', 'actor_id'], 'users', 'cascade'],
        ],
        'sale_payment_requests' => [
            ['sale_payment_requests_actor_tenant_fk', ['business_id', 'actor_id'], 'users', 'cascade'],
            ['sale_payment_requests_sale_tenant_fk', ['business_id', 'sale_id'], 'sales', 'cascade'],
        ],
        'sale_refund_requests' => [
            ['sale_refund_requests_actor_tenant_fk', ['business_id', 'actor_id'], 'users', 'restrict'],
            ['sale_refund_requests_sale_tenant_fk', ['business_id', 'sale_id'], 'sales', 'restrict'],
            ['sale_refund_requests_refund_tenant_fk', ['business_id', 'sale_refund_id'], 'sale_refunds', 'restrict'],
        ],
        'sale_return_requests' => [
            ['sale_return_requests_actor_tenant_fk', ['business_id', 'actor_id'], 'users', 'restrict'],
            ['sale_return_requests_sale_tenant_fk', ['business_id', 'sale_id'], 'sales', 'restrict'],
            ['sale_return_requests_return_tenant_fk', ['business_id', 'sale_return_id'], 'sale_returns', 'restrict'],
        ],
    ];

    /** Parents that did not yet carry a (business_id, id) key for a child to reference. */
    private const PARENT_KEYS = ['expenses' => 'expenses_business_id_id_unique', 'sale_refunds' => 'sale_refunds_business_id_id_unique'];

    /** @var array<string, array<string, string>> */
    private const SET_NULL_RESULTS = ['purchase_requests' => ['purchase_id' => 'purchases'], 'sale_payment_requests' => ['sale_payment_id' => 'sale_payments']];

    public function up(): void
    {
        foreach (array_keys(self::TENANT_KEYS) as $table) {
            if (DB::table($table)->whereNull('business_id')->exists()) {
                throw new RuntimeException("{$table} has rows without a business.");
            }
        }

        foreach (self::SET_NULL_RESULTS as $table => $results) {
            foreach ($results as $column => $owner) {
                if (DB::table("{$table} as t")->join("{$owner} as o", 'o.id', '=', "t.{$column}")->whereColumn('o.business_id', '<>', 't.business_id')->exists()) {
                    throw new RuntimeException("A {$table} row resolves to a {$column} of another business.");
                }
            }
        }

        foreach (self::PARENT_KEYS as $table => $name) {
            if (! $this->indexExists($table, $name)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique(['business_id', 'id'], $name));
            }
        }

        foreach (self::TENANT_KEYS as $table => $keys) {
            // MySQL can refuse to tighten a column its own foreign key uses (error 1832).
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign(['business_id']));
            DB::statement("ALTER TABLE {$table} MODIFY business_id BIGINT UNSIGNED NOT NULL");
            Schema::table($table, function (Blueprint $blueprint) use ($keys): void {
                $blueprint->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();

                foreach ($keys as [$name, $columns, $parent, $onDelete]) {
                    $blueprint->foreign($columns, $name)->references(['business_id', 'id'])->on($parent)->onDelete($onDelete);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TENANT_KEYS as $table => $keys) {
            foreach ($keys as [$name]) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($name));
            }

            // The business key may have adopted a composite key's index when it was re-added, so it
            // goes before those indexes do (MySQL error 1553 otherwise).
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign(['business_id']));

            // MySQL keeps the index it created for each dropped key; left behind, it would collide
            // with the key's name on a re-apply.
            foreach ($keys as [$name]) {
                if ($this->indexExists($table, $name)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
                }
            }

            DB::statement("ALTER TABLE {$table} MODIFY business_id BIGINT UNSIGNED NULL");
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete());
        }

        foreach (self::PARENT_KEYS as $table => $name) {
            if ($this->indexExists($table, $name)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($name));
            }
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index]
        ) !== null;
    }
};
