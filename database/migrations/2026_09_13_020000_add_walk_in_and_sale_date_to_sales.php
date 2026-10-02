<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two additions to `sales`: walk-in customers, and an explicit sale date.
 *
 * WALK-IN
 * `customer_id` becomes nullable so a counter sale need not invent a Customer row to satisfy a
 * foreign key. The identity snapshots stay NOT NULL, so every Sale — registered or walk-in — still
 * carries a legible customer name and code on its own row; a walk-in simply snapshots the walk-in
 * label instead of a person. `is_walk_in` states the intent outright rather than leaving later code
 * to infer it from a null, and a CHECK keeps the two in step so a Sale can never claim to be both.
 *
 * `customer_phone_snapshot` becomes nullable too: a walk-in has no number, and that absence is
 * exactly what makes the Sale ineligible for a WhatsApp receipt.
 *
 * SALE DATE
 * `created_at` is when the row was written; `sale_date` is when the trade happened, which a user
 * may legitimately backdate. Existing rows are backfilled from `created_at` so no report changes
 * meaning.
 *
 * The backfill converts to the business timezone first. `created_at` is stored in UTC, but a "sale
 * date" is the trading day the shop experienced: in Africa/Lagos (UTC+1) a sale rung up at 00:30
 * local is stored as 23:30 UTC the previous day, so a naive DATE(created_at) would file it under
 * yesterday. The offset is read from config rather than hardcoded, because BUSINESS_TIMEZONE is an
 * environment setting, and it is resolved to a numeric offset so the conversion does not depend on
 * MariaDB's named-timezone tables being loaded — they frequently are not on shared hosting.
 *
 * A future-date CHECK is deliberately NOT added. Backdating is legitimate, and a storage-level
 * ceiling would make an operator's clock skew or a historical import fail at the database with no
 * useful message. `before_or_equal:today` in StoreSaleRequest remains the authoritative rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->boolean('is_walk_in')->default(false)->after('customer_id');
            $table->date('sale_date')->nullable()->after('status');
            $table->index(['sale_date', 'id']);
        });

        // Nullable in a separate statement: changing a constrained column and adding new ones in one
        // Blueprint is harder to reason about, and this keeps each intent its own step.
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->change();
            $table->string('customer_phone_snapshot', 14)->nullable()->change();
        });

        // Every existing Sale traded on the business day it was recorded, read in the business
        // timezone rather than UTC. A fixed numeric offset keeps this independent of MariaDB's
        // timezone tables; for a zone with DST this takes the offset in force at migration time,
        // which is noted in the class comment as a known limitation of a one-off historical fill.
        DB::statement(
            'UPDATE sales SET sale_date = DATE(CONVERT_TZ(created_at, ?, ?)) WHERE sale_date IS NULL',
            ['+00:00', $this->businessUtcOffset()],
        );

        Schema::table('sales', function (Blueprint $table) {
            $table->date('sale_date')->nullable(false)->change();
        });

        // A walk-in has no customer; a registered sale must have one. Nothing in between.
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_walk_in_identity CHECK ((is_walk_in = 1 AND customer_id IS NULL) OR (is_walk_in = 0 AND customer_id IS NOT NULL))');

        // Everything that hangs off a Sale inherits its customer, so a walk-in Sale's payments,
        // returns and refunds have no customer to point at either. Their own identity snapshots stay NOT NULL,
        // so each row still reads correctly on a receipt or a report without the join.
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->change();
        });

        Schema::table('sale_returns', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->change();
        });

        Schema::table('sale_refunds', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->change();
        });
    }

    /**
     * The business timezone's current offset from UTC, as MariaDB's `+HH:MM` literal.
     *
     * Africa/Lagos has no DST, so one offset describes its whole history. A zone that does observe
     * DST would need its per-row offset to be exact; that is called out here rather than silently
     * assumed, because this migration runs once over historical rows and cannot be re-run.
     */
    private function businessUtcOffset(): string
    {
        $timezone = new DateTimeZone((string) config('business.timezone', 'UTC'));
        $seconds = $timezone->getOffset(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $sign = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);

        return sprintf('%s%02d:%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    private function dropCheckIfExists(string $table, string $constraint): void
    {
        // No TABLE_NAME predicate: MariaDB's CHECK_CONSTRAINTS has no such column, and constraint
        // names are unique per schema here anyway.
        $exists = DB::selectOne(
            'SELECT 1 AS found FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = database() AND CONSTRAINT_NAME = ?',
            [$constraint],
        );

        if ($exists !== null) {
            DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT '.$constraint);
        }
    }

    public function down(): void
    {
        // DROP CONSTRAINT, not DROP CHECK: MariaDB rejects the MySQL-only spelling. Guarded on
        // presence because a reversal may run against a database where an earlier attempt got
        // partway, and MariaDB has no DROP CONSTRAINT IF EXISTS for CHECKs on every version.
        $this->dropCheckIfExists('sales', 'sales_walk_in_identity');

        // Walk-in sales have no customer to restore, so they are the one thing that blocks a clean
        // reversal; refusing loudly beats silently inventing a customer for them.
        if (DB::table('sales')->whereNull('customer_id')->exists()) {
            throw new RuntimeException('Cannot reverse: walk-in sales exist with no customer to restore.');
        }

        // MariaDB refuses to narrow a column back to NOT NULL while a foreign key references it
        // (error 1832), so each key comes off, the column is restored, and the key goes back exactly
        // as its original migration declared it. Widening to nullable in `up()` needs none of this;
        // only this direction is refused.
        foreach (['sale_refunds', 'sale_returns', 'sale_payments', 'sales'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign(['customer_id']));
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreignId('customer_id')->nullable(false)->change());
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete());
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->string('customer_phone_snapshot', 14)->nullable(false)->change();
            $table->dropIndex(['sale_date', 'id']);
            $table->dropColumn(['is_walk_in', 'sale_date']);
        });
    }
};
