<?php

use App\Enums\DiscountRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Post-hoc price adjustment, as an auditable request an Admin decides on — the answer to the UAT
 * ask for "sale editing" that does not require making a completed Sale mutable.
 *
 * A new table rather than columns on `sales`: the request is a separate business event with its own
 * author, reviewer, timestamps and outcome, and recording it beside the Sale would conflate the
 * evidence of the sale with the history of what was asked about it.
 *
 * `pending_sale_guard` enforces "at most one pending request per Sale" in the database. MySQL and
 * MariaDB have no partial unique index, so the column carries the sale_id only while the request is
 * pending and NULL once decided; because a unique index ignores NULLs, a Sale can accumulate any
 * number of decided requests but never two open ones. This mirrors `sale_payments.initial_sale_guard`,
 * which already solves the same problem the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_discount_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();

            // What was asked for, and why.
            $table->decimal('requested_amount', 15, 2);
            $table->string('reason', 500);
            $table->enum('status', array_column(DiscountRequestStatus::cases(), 'value'))
                ->default(DiscountRequestStatus::Pending->value);

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('requested_by_name_snapshot', 120);
            $table->timestamp('requested_at');

            // Who decided, when, and what they said about it.
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('decided_by_name_snapshot', 120)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();

            // Financial evidence captured at the moment of approval, so the effect of the decision
            // stays legible even after later returns, refunds or payments move the Sale on.
            $table->decimal('discount_before', 15, 2)->nullable();
            $table->decimal('discount_after', 15, 2)->nullable();
            $table->decimal('total_before', 15, 2)->nullable();
            $table->decimal('total_after', 15, 2)->nullable();
            $table->decimal('balance_after', 15, 2)->nullable();
            $table->decimal('refundable_credit_after', 15, 2)->nullable();

            // Only populated while pending; see the class comment.
            $table->unsignedBigInteger('pending_sale_guard')->nullable()->unique();

            $table->timestamps();
            $table->index(['status', 'requested_at']);
            $table->index(['sale_id', 'created_at']);
        });

        // A discount must actually discount something.
        DB::statement('ALTER TABLE sale_discount_requests ADD CONSTRAINT sale_discount_requests_amount_positive CHECK (requested_amount > 0)');

        // The one-pending-per-sale guard cannot be pointed at a different Sale, or left dangling
        // after a decision, without the database rejecting the write.
        DB::statement("ALTER TABLE sale_discount_requests ADD CONSTRAINT sale_discount_requests_guard_tracks_status CHECK ((status = 'pending' AND pending_sale_guard = sale_id) OR (status <> 'pending' AND pending_sale_guard IS NULL))");

        // A decided request must say who decided it and when; a pending one must not pretend to.
        DB::statement("ALTER TABLE sale_discount_requests ADD CONSTRAINT sale_discount_requests_decision_complete CHECK ((status = 'pending' AND decided_by IS NULL AND decided_at IS NULL) OR (status <> 'pending' AND decided_by IS NOT NULL AND decided_at IS NOT NULL))");

        // An approval must record the financial effect it had; a request that was not approved has
        // no effect to record.
        DB::statement("ALTER TABLE sale_discount_requests ADD CONSTRAINT sale_discount_requests_approval_evidence CHECK ((status = 'approved' AND discount_before IS NOT NULL AND discount_after IS NOT NULL AND total_before IS NOT NULL AND total_after IS NOT NULL AND total_after >= 0 AND balance_after IS NOT NULL AND refundable_credit_after IS NOT NULL) OR (status <> 'approved' AND discount_before IS NULL AND discount_after IS NULL AND total_before IS NULL AND total_after IS NULL AND balance_after IS NULL AND refundable_credit_after IS NULL))");
    }

    public function down(): void
    {
        // DROP CONSTRAINT, not DROP CHECK: MariaDB rejects the MySQL-only spelling.
        foreach ([
            'sale_discount_requests_approval_evidence',
            'sale_discount_requests_decision_complete',
            'sale_discount_requests_guard_tracks_status',
            'sale_discount_requests_amount_positive',
        ] as $constraint) {
            DB::statement('ALTER TABLE sale_discount_requests DROP CONSTRAINT '.$constraint);
        }

        Schema::dropIfExists('sale_discount_requests');
    }
};
