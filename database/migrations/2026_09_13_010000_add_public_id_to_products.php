<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Gives every Product a non-sequential public identifier for its URLs.
 *
 * `products.id` stays exactly as it is. It remains the primary key and the target of every foreign
 * key — sale_items, purchase_items, inventory_movements, sale_return_items — so no history, join or
 * report is touched. `public_id` is an additional column used only to address a Product from the
 * browser, so a URL stops advertising how many products exist or letting one be guessed from
 * another.
 *
 * The three steps are deliberately separate so this is safe on a table that already has rows:
 * the column arrives nullable, every existing row is backfilled with its own ULID, and only then
 * is it made NOT NULL and unique. Reversing the order would fail the moment a single Product
 * existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Nullable to begin with: existing rows have no value yet, and a NOT NULL column with
            // no default cannot be added to a populated table.
            $table->char('public_id', 26)->nullable()->after('id');
        });

        $this->backfill();

        Schema::table('products', function (Blueprint $table) {
            $table->char('public_id', 26)->nullable(false)->change();
            $table->unique('public_id');
        });
    }

    /**
     * Assigns a ULID to every Product that does not have one, including soft-deleted rows — an
     * archived or deleted Product is still reachable through history screens and must address
     * itself the same way.
     *
     * Done in chunks by primary key, and each row is given its own ULID rather than one generated
     * expression: MariaDB has no ULID function, and a per-row UPDATE keeps the values genuinely
     * distinct without relying on any engine-specific behaviour.
     */
    private function backfill(): void
    {
        DB::table('products')
            ->whereNull('public_id')
            ->orderBy('id')
            ->chunkById(500, function ($products): void {
                foreach ($products as $product) {
                    DB::table('products')
                        ->where('id', $product->id)
                        ->whereNull('public_id')
                        ->update(['public_id' => (string) Str::ulid()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
