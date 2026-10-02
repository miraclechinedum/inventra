<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand and backfill: single-use request tokens gain the Business of the operator they were issued
 * to. They were already isolated through that operator and their session; this makes the Business
 * explicit so a token lookup can name it, and lets the contract prove every token's sale and result
 * belong to the same Business as its operator.
 */
return new class extends Migration
{
    /** @var array<string, array<string, string>> table => [referencing column => owning table] */
    private const TOKENS = [
        'expense_requests' => ['expense_id' => 'expenses'],
        'purchase_requests' => ['purchase_id' => 'purchases'],
        'sale_payment_requests' => ['sale_id' => 'sales', 'sale_payment_id' => 'sale_payments'],
        'sale_refund_requests' => ['sale_id' => 'sales', 'sale_refund_id' => 'sale_refunds'],
        'sale_return_requests' => ['sale_id' => 'sales', 'sale_return_id' => 'sale_returns'],
    ];

    public function up(): void
    {
        foreach (self::TOKENS as $table => $references) {
            if (! Schema::hasColumn($table, 'business_id')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->restrictOnDelete());
            }

            DB::statement("UPDATE {$table} t JOIN users u ON u.id = t.actor_id SET t.business_id = u.business_id WHERE t.business_id IS NULL");

            foreach ($references as $column => $owner) {
                $mismatched = DB::table("{$table} as t")->join("{$owner} as o", 'o.id', '=', "t.{$column}")
                    ->whereColumn('o.business_id', '<>', 't.business_id')->count();

                if ($mismatched > 0) {
                    throw new RuntimeException("{$mismatched} {$table} rows were issued to an operator of a different business from their {$column}.");
                }
            }

            $unowned = DB::table($table)->whereNull('business_id')->count();

            if ($unowned > 0) {
                throw new RuntimeException("{$unowned} {$table} rows have no operator to establish their business.");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('businesses')->count() > 1) {
            throw new RuntimeException('Refusing to drop request token ownership while more than one business exists.');
        }

        foreach (array_keys(self::TOKENS) as $table) {
            if (Schema::hasColumn($table, 'business_id')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropConstrainedForeignId('business_id'));
            }
        }
    }
};
