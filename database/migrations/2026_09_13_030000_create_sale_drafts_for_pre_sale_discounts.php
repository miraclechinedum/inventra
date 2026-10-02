<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a discount be approved BEFORE the Sale exists.
 *
 * The existing discount workflow is post-hoc: a request points at a completed Sale. That is still
 * the right model for adjusting a recorded Sale, and it is left untouched. What it cannot express
 * is the counter case — a Sales Rep asking permission before taking the money — because there is no
 * Sale yet to point at.
 *
 * A `sale_draft` is the missing subject. It is deliberately NOT a sale:
 *   - it deducts no stock, writes no inventory movement and takes no payment;
 *   - it holds only what was in the cart, as a JSON snapshot plus a hash of it;
 *   - it is disposable, and never appears in any financial report.
 *
 * The hash is what makes approval safe. An Admin approves a specific cart, and `Record Sale` refuses
 * to spend that approval on any cart whose hash no longer matches — so changing a quantity after
 * approval invalidates the approval instead of silently discounting a different basket.
 *
 * `sale_discount_requests` gains a nullable `sale_draft_id` beside its existing `sale_id`. Exactly
 * one of the two is set, enforced by CHECK, so every request has precisely one subject and the
 * post-hoc flow keeps behaving exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_drafts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            // Who is building this cart. A draft belongs to one user's attempt at one sale.
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            // The sale context the approval is granted against. These are bound into `cart_hash`
            // as well as stored, so an approval cannot be spent on a different buyer or a different
            // trading day; they are kept as columns too so the decision screen can show the Admin
            // who and when they are approving for.
            $table->boolean('is_walk_in')->default(false);
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->date('sale_date');

            // The cart exactly as it stood when a decision was asked for. Read-only evidence: the
            // authoritative prices and stock are re-read from `products` when the Sale is recorded.
            $table->json('cart_snapshot');
            // SHA-256 of the canonical cart. Binds an approval to this basket and no other.
            $table->char('cart_hash', 64);
            $table->decimal('subtotal_snapshot', 15, 2);

            // Which Sale finally consumed this draft, so a draft can never be spent twice.
            $table->foreignId('consumed_by_sale_id')->nullable()->constrained('sales')->restrictOnDelete();
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();
            $table->index(['created_by', 'created_at']);
        });

        DB::statement('ALTER TABLE sale_drafts ADD CONSTRAINT sale_drafts_subtotal_non_negative CHECK (subtotal_snapshot >= 0)');
        // The same walk-in invariant the Sale itself carries, so a draft can never describe a buyer
        // the resulting Sale could not legally have.
        DB::statement('ALTER TABLE sale_drafts ADD CONSTRAINT sale_drafts_walk_in_identity CHECK ((is_walk_in = 1 AND customer_id IS NULL) OR (is_walk_in = 0 AND customer_id IS NOT NULL))');
        DB::statement('ALTER TABLE sale_drafts ADD CONSTRAINT sale_drafts_consumption_complete CHECK ((consumed_by_sale_id IS NULL AND consumed_at IS NULL) OR (consumed_by_sale_id IS NOT NULL AND consumed_at IS NOT NULL))');

        Schema::table('sale_discount_requests', function (Blueprint $table) {
            $table->foreignId('sale_draft_id')->nullable()->after('sale_id')
                ->constrained('sale_drafts')->restrictOnDelete();
            // Mirrors `pending_sale_guard`: carries the draft id only while pending, so a draft can
            // never accumulate two open requests. A unique index ignores NULLs, so decided requests
            // are unconstrained.
            $table->unsignedBigInteger('pending_draft_guard')->nullable()->unique();
        });

        // `sale_id` was NOT NULL; a draft request has no Sale yet.
        Schema::table('sale_discount_requests', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->change();
        });

        // Exactly one subject: a recorded Sale, or a draft cart. Never both, never neither.
        DB::statement('ALTER TABLE sale_discount_requests ADD CONSTRAINT sale_discount_requests_single_subject CHECK ((sale_id IS NOT NULL AND sale_draft_id IS NULL) OR (sale_id IS NULL AND sale_draft_id IS NOT NULL))');

        // The draft guard tracks status exactly as the sale guard already does.
        DB::statement("ALTER TABLE sale_discount_requests ADD CONSTRAINT sale_discount_requests_draft_guard_tracks_status CHECK ((status = 'pending' AND sale_draft_id IS NOT NULL AND pending_draft_guard = sale_draft_id) OR (sale_draft_id IS NULL AND pending_draft_guard IS NULL) OR (status <> 'pending' AND pending_draft_guard IS NULL))");

        // The original guard assumed sale_id was always present. Re-state it so it only applies to
        // sale-backed requests, leaving draft-backed ones to the draft guard above.
        $this->dropCheckIfExists('sale_discount_requests', 'sale_discount_requests_guard_tracks_status');
        DB::statement("ALTER TABLE sale_discount_requests ADD CONSTRAINT sale_discount_requests_guard_tracks_status CHECK ((sale_id IS NULL AND pending_sale_guard IS NULL) OR (status = 'pending' AND pending_sale_guard = sale_id) OR (status <> 'pending' AND pending_sale_guard IS NULL))");

        // Approval evidence is recorded for a sale-backed approval, where a Sale's figures move.
        // A draft approval has no Sale to move yet, so it records the approved amount only.
        $this->dropCheckIfExists('sale_discount_requests', 'sale_discount_requests_approval_evidence');
        DB::statement("ALTER TABLE sale_discount_requests ADD CONSTRAINT sale_discount_requests_approval_evidence CHECK (sale_draft_id IS NOT NULL OR (status = 'approved' AND discount_before IS NOT NULL AND discount_after IS NOT NULL AND total_before IS NOT NULL AND total_after IS NOT NULL AND total_after >= 0 AND balance_after IS NOT NULL AND refundable_credit_after IS NOT NULL) OR (status <> 'approved' AND discount_before IS NULL AND discount_after IS NULL AND total_before IS NULL AND total_after IS NULL AND balance_after IS NULL AND refundable_credit_after IS NULL))");
    }

    public function down(): void
    {
        // Dropped defensively: a reversal may run against a database where an earlier attempt got
        // partway, and MariaDB has no DROP CONSTRAINT IF EXISTS for CHECKs on every version.
        foreach ([
            'sale_discount_requests_approval_evidence',
            'sale_discount_requests_guard_tracks_status',
            'sale_discount_requests_draft_guard_tracks_status',
            'sale_discount_requests_single_subject',
        ] as $constraint) {
            $this->dropCheckIfExists('sale_discount_requests', $constraint);
        }

        if (DB::table('sale_discount_requests')->whereNull('sale_id')->exists()) {
            throw new RuntimeException('Cannot reverse: draft-backed discount requests exist.');
        }

        Schema::table('sale_discount_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_draft_id');
            $table->dropColumn('pending_draft_guard');
        });

        // MariaDB refuses to narrow a column to NOT NULL while a foreign key still references it
        // (error 1832), so the key comes off, the column is restored, and the key goes back exactly
        // as the original migration declared it. Widening to nullable in `up()` needs none of this —
        // only the narrowing direction is refused.
        Schema::table('sale_discount_requests', function (Blueprint $table) {
            $table->dropForeign(['sale_id']);
        });

        Schema::table('sale_discount_requests', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable(false)->change();
        });

        Schema::table('sale_discount_requests', function (Blueprint $table) {
            $table->foreign('sale_id')->references('id')->on('sales')->restrictOnDelete();
        });

        // Restore the original constraints exactly as they were.
        DB::statement("ALTER TABLE sale_discount_requests ADD CONSTRAINT sale_discount_requests_guard_tracks_status CHECK ((status = 'pending' AND pending_sale_guard = sale_id) OR (status <> 'pending' AND pending_sale_guard IS NULL))");
        DB::statement("ALTER TABLE sale_discount_requests ADD CONSTRAINT sale_discount_requests_approval_evidence CHECK ((status = 'approved' AND discount_before IS NOT NULL AND discount_after IS NOT NULL AND total_before IS NOT NULL AND total_after IS NOT NULL AND total_after >= 0 AND balance_after IS NOT NULL AND refundable_credit_after IS NOT NULL) OR (status <> 'approved' AND discount_before IS NULL AND discount_after IS NULL AND total_before IS NULL AND total_after IS NULL AND balance_after IS NULL AND refundable_credit_after IS NULL))");

        foreach (['sale_drafts_walk_in_identity', 'sale_drafts_consumption_complete', 'sale_drafts_subtotal_non_negative'] as $constraint) {
            $this->dropCheckIfExists('sale_drafts', $constraint);
        }

        Schema::dropIfExists('sale_drafts');
    }

    private function dropCheckIfExists(string $table, string $constraint): void
    {
        $exists = DB::selectOne(
            'SELECT 1 AS found FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = database() AND CONSTRAINT_NAME = ?',
            [$constraint],
        );

        if ($exists !== null) {
            DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT '.$constraint);
        }
    }
};
