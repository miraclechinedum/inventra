<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand and backfill: the catalog gains a Business.
 *
 * Categories and products are roots, so an existing row can only belong to the installation's one
 * Business — and the backfill refuses to guess when there is not exactly one. Inventory movements
 * are not assigned a constant: each takes its Product's Business, the authoritative owner of the
 * stock it records. That is a one-time schema backfill of an append-only ledger; application code
 * still never updates a movement.
 */
return new class extends Migration
{
    private const TABLES = ['product_categories', 'products', 'inventory_movements'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->restrictOnDelete();
            });
        }

        $this->assignRoots(['product_categories', 'products']);

        DB::statement(
            'UPDATE inventory_movements m JOIN products p ON p.id = m.product_id
             SET m.business_id = p.business_id WHERE m.business_id IS NULL'
        );

        foreach (self::TABLES as $table) {
            if (DB::table($table)->whereNull('business_id')->exists()) {
                throw new RuntimeException("Backfill left {$table} rows without a business.");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('businesses')->count() > 1) {
            throw new RuntimeException('Refusing to drop catalog ownership while more than one business exists.');
        }

        foreach (array_reverse(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('business_id');
            });
        }
    }

    /** @param  list<string>  $tables */
    private function assignRoots(array $tables): void
    {
        $unowned = collect($tables)->contains(fn (string $table): bool => DB::table($table)->whereNull('business_id')->exists());

        if (! $unowned) {
            return;
        }

        $businessIds = DB::table('businesses')->pluck('id');

        if ($businessIds->count() !== 1) {
            throw new RuntimeException(
                'Existing catalog rows can only be assigned when exactly one business exists; found '.$businessIds->count().'.'
            );
        }

        foreach ($tables as $table) {
            DB::table($table)->whereNull('business_id')->update(['business_id' => $businessIds->first()]);
        }
    }
};
