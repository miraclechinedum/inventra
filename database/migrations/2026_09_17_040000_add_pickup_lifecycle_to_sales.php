<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The minimum fulfilment lifecycle the Pickup reminder needs, and nothing more.
 *
 * Inventra had no fulfilment concept at all: a Sale was complete at the counter and `payment_status`
 * described money, not goods. The reminder needs one truthful fact — this order is ready to be
 * collected, and here is when that became true — so that is exactly what these two columns record.
 *
 * Deliberately NOT overloading `payment_status`: an unpaid order can be ready for collection and a
 * paid one can still be awaiting assembly. Conflating them would make both meaningless.
 *
 * Deliberately NOT a full order-management module: no picker assignment, no partial fulfilment, no
 * collection confirmation. Those are a different product decision; this is the smallest addition
 * that lets the reminder fire on a real event rather than an invented one.
 *
 * Additive and safe on populated tables: both columns are nullable with no default, so every
 * existing Sale keeps its current meaning — not ready for pickup, which is true of all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->timestamp('pickup_ready_at')->nullable()->after('sale_date');
            $table->foreignId('pickup_ready_by')->nullable()->after('pickup_ready_at')
                ->constrained('users')->nullOnDelete();
        });

        // No CHECK pairing the two columns. MySQL refuses one here: `pickup_ready_by` is a foreign
        // key with ON DELETE SET NULL, and deleting that user would have to break the check, so the
        // engine rejects the constraint outright (error 3823). The foreign key is the more valuable
        // guarantee — it keeps the actor referentially real — so it stays, and MarkSaleReadyForPickup
        // is the single writer that sets both columns together. `pickup_ready_at` alone decides
        // readiness everywhere, so a NULLed actor after a user deletion loses attribution without
        // making the sale's pickup state ambiguous.

        Schema::table('sales', function (Blueprint $table): void {
            $table->index('pickup_ready_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex(['pickup_ready_at']);
            $table->dropConstrainedForeignId('pickup_ready_by');
            $table->dropColumn('pickup_ready_at');
        });
    }
};
