<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per low-stock *episode* — the span from the moment a product crosses down to at/below its
 * reorder level until it rises back above it.
 *
 * This is what makes the alert fire on the crossing rather than on the condition. Stock at 2 with a
 * reorder level of 8 is low every time anything touches that product; without an episode the
 * manager would be messaged on every sale, adjustment and return while it stayed low. With one, the
 * downward crossing opens an episode, the alert is keyed to that episode, and no further alert can
 * be produced until stock rises above the level and closes it — re-arming for a future crossing.
 *
 * `product_id` + `closed_at IS NULL` is the open episode. A partial UNIQUE index would express that
 * directly, but MySQL has none, so the invariant is held instead by `open_key`: it carries the
 * product id while the episode is open and is NULL once closed, and it is UNIQUE. Two concurrent
 * crossings therefore contend on the database, and exactly one opens the episode — the same
 * approach `operational_alerts.active_key` already uses here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_low_stock_episodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // Non-null only while the episode is open; UNIQUE, so one open episode per product.
            $table->unsignedBigInteger('open_key')->nullable()->unique();

            // What was true at the crossing, snapshotted so the message reports the moment that
            // triggered it rather than whatever stock happens to be when it is finally sent.
            $table->decimal('stock_at_crossing', 15, 3);
            $table->decimal('reorder_level_at_crossing', 15, 3);

            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_low_stock_episodes');
    }
};
