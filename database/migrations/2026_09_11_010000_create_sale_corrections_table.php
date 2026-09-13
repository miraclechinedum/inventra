<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Correction of a recording mistake on a completed Sale.
 *
 * This is NOT a return. Returned goods keep going through the Return/Refund workflow, which moves
 * stock back and creates refundable credit against an unchanged sale. A correction says something
 * different: the sale as recorded never described reality — the wrong product, the wrong quantity,
 * the wrong customer — and the record must be made to match what actually happened.
 *
 * Nothing is rewritten in place. `sale_items` is append-only, so a correction retires the rows it
 * replaces by stamping `superseded_by_correction_id` and appends new rows carrying
 * `sale_correction_id`. The full history of what the sale said at every point therefore survives,
 * while `Sale::items()` reads only the rows that are currently active — which is what makes a
 * reprinted receipt show the corrected sale without any view having to know corrections exist.
 *
 * Stock is reconciled with explicit `correction` inventory movements, never by editing historical
 * movement rows, and `sale_payments` is never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->string('reason', 500);

            $table->foreignId('corrected_by')->constrained('users')->restrictOnDelete();
            $table->string('corrected_by_name_snapshot', 120);
            $table->timestamp('corrected_at');

            // Before/after evidence, so the effect of the correction stays legible even after later
            // payments, returns or discounts move the Sale on.
            $table->decimal('subtotal_before', 15, 2);
            $table->decimal('subtotal_after', 15, 2);
            $table->decimal('total_before', 15, 2);
            $table->decimal('total_after', 15, 2);
            $table->decimal('balance_before', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->decimal('refundable_credit_before', 15, 2);
            $table->decimal('refundable_credit_after', 15, 2);
            $table->string('payment_status_before', 32);
            $table->string('payment_status_after', 32);
            $table->unsignedInteger('item_count_before');
            $table->unsignedInteger('item_count_after');
            $table->foreignId('customer_id_before')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('customer_id_after')->nullable()->constrained('customers')->restrictOnDelete();

            $table->timestamps();
            $table->index(['sale_id', 'corrected_at']);
            $table->index('corrected_at');
        });

        // Money never goes negative, and a correction must leave at least one item on the Sale:
        // removing every line would be a void, which is a different workflow with its own rules.
        DB::statement('ALTER TABLE sale_corrections ADD CONSTRAINT sale_corrections_amounts_non_negative CHECK (subtotal_before >= 0 AND subtotal_after >= 0 AND total_before >= 0 AND total_after >= 0 AND balance_before >= 0 AND balance_after >= 0 AND refundable_credit_before >= 0 AND refundable_credit_after >= 0)');
        DB::statement('ALTER TABLE sale_corrections ADD CONSTRAINT sale_corrections_items_remain CHECK (item_count_before >= 1 AND item_count_after >= 1)');

        Schema::table('sale_items', function (Blueprint $table) {
            // Which correction introduced this line. NULL means it came from the original sale.
            $table->foreignId('sale_correction_id')->nullable()->after('sale_id')
                ->constrained('sale_corrections')->restrictOnDelete();
            // Which correction retired this line. NULL means the line is currently active.
            $table->foreignId('superseded_by_correction_id')->nullable()->after('sale_correction_id')
                ->constrained('sale_corrections')->restrictOnDelete();
            $table->index(['sale_id', 'superseded_by_correction_id'], 'sale_items_active_index');
        });

        // A line cannot be retired by the very correction that created it.
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_correction_distinct CHECK (sale_correction_id IS NULL OR superseded_by_correction_id IS NULL OR sale_correction_id <> superseded_by_correction_id)');
    }

    public function down(): void
    {
        // DROP CONSTRAINT, not DROP CHECK: MariaDB rejects the MySQL-only spelling.
        DB::statement('ALTER TABLE sale_items DROP CONSTRAINT sale_items_correction_distinct');

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropIndex('sale_items_active_index');
            $table->dropConstrainedForeignId('superseded_by_correction_id');
            $table->dropConstrainedForeignId('sale_correction_id');
        });

        foreach (['sale_corrections_items_remain', 'sale_corrections_amounts_non_negative'] as $constraint) {
            DB::statement('ALTER TABLE sale_corrections DROP CONSTRAINT '.$constraint);
        }

        Schema::dropIfExists('sale_corrections');
    }
};
