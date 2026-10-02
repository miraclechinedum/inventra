<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Gives every Sale a non-sequential public identifier for its URLs.
 *
 * `sales.id` is untouched. It remains the primary key and the target of every foreign key —
 * sale_items, sale_payments, sale_returns, sale_refunds, sale_discount_requests, sale_corrections,
 * whatsapp_deliveries, sale_drafts.consumed_by_sale_id — so no ledger row, join or report changes
 * in any way. `public_id` is an additional column used only to address a Sale from the browser, so
 * a URL stops advertising how many sales the business has made or letting one be guessed from
 * another.
 *
 * The `sale_number` a human reads (SALE-000002) is also unchanged and stays the visible identifier
 * on screens and receipts. This column is for routing only; the two serve different purposes and
 * neither replaces the other.
 *
 * The three steps are deliberately separate so this is safe on a table that already has rows: the
 * column arrives nullable, every existing row is backfilled with its own ULID, and only then is it
 * made NOT NULL and unique. Reversing the order would fail the moment a single Sale existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Nullable to begin with: existing rows have no value yet, and a NOT NULL column with
            // no default cannot be added to a populated table.
            $table->char('public_id', 26)->nullable()->after('id');
        });

        $this->backfill();

        Schema::table('sales', function (Blueprint $table) {
            $table->char('public_id', 26)->nullable(false)->change();
            $table->unique('public_id');
        });
    }

    /**
     * Assigns a ULID to every Sale that does not have one, voided sales included — a voided Sale is
     * still reachable through history and must address itself the same way.
     *
     * Done in chunks by primary key, and each row is given its own ULID rather than one generated
     * expression: MariaDB has no ULID function, and a per-row UPDATE keeps the values genuinely
     * distinct without relying on any engine-specific behaviour.
     */
    private function backfill(): void
    {
        DB::table('sales')
            ->whereNull('public_id')
            ->orderBy('id')
            ->chunkById(500, function ($sales): void {
                foreach ($sales as $sale) {
                    DB::table('sales')
                        ->where('id', $sale->id)
                        ->whereNull('public_id')
                        ->update(['public_id' => (string) Str::ulid()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
