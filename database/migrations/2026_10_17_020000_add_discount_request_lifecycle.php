<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cancellation, expiry and the sole-Administrator approval exception for discount requests.
 *
 * Two terminal states join approved and declined:
 *
 *   cancelled  withdrawn before a decision, by the requester or an Administrator. Like a decision,
 *              it names who closed it and when.
 *   expired    recorded by the system once a pending request outlived its 24 hours, or a pre-sale
 *              approval outlived its business day unspent. A pending request that expires was
 *              never decided, so it carries no decider; an approval that expires keeps the
 *              decision it records. `expired_at` is set exactly for this state.
 *
 * Expiry itself is derived from `requested_at` and `decided_at` and enforced at the moment of use;
 * this state only records it. No existing row is rewritten.
 *
 * `self_approved` marks the one audited exception to the two-person rule: an Administrator who is
 * the Business's only Administrator approving their own request.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropCheckIfExists('sale_discount_requests_decision_complete');

        DB::statement("ALTER TABLE sale_discount_requests MODIFY status ENUM('pending','approved','declined','cancelled','expired') NOT NULL DEFAULT 'pending'");

        Schema::table('sale_discount_requests', function (Blueprint $table): void {
            $table->timestamp('expired_at')->nullable()->after('decision_note');
            $table->boolean('self_approved')->default(false)->after('expired_at');
        });

        DB::statement("ALTER TABLE sale_discount_requests ADD CONSTRAINT sale_discount_requests_decision_complete CHECK ((status = 'pending' AND decided_by IS NULL AND decided_at IS NULL AND expired_at IS NULL) OR (status IN ('approved', 'declined', 'cancelled') AND decided_by IS NOT NULL AND decided_at IS NOT NULL AND expired_at IS NULL) OR (status = 'expired' AND expired_at IS NOT NULL AND ((decided_by IS NULL AND decided_at IS NULL) OR (decided_by IS NOT NULL AND decided_at IS NOT NULL))))");
        DB::statement("ALTER TABLE sale_discount_requests ADD CONSTRAINT sale_discount_requests_self_approval_is_the_requester CHECK (self_approved = 0 OR (status IN ('approved', 'expired') AND decided_by IS NOT NULL AND decided_by = requested_by))");
    }

    public function down(): void
    {
        if (DB::table('sale_discount_requests')->whereIn('status', ['cancelled', 'expired'])->orWhere('self_approved', true)->exists()) {
            throw new RuntimeException('Cannot remove discount request cancellation and expiry while requests have used them.');
        }

        $this->dropCheckIfExists('sale_discount_requests_self_approval_is_the_requester');
        $this->dropCheckIfExists('sale_discount_requests_decision_complete');

        Schema::table('sale_discount_requests', function (Blueprint $table): void {
            $table->dropColumn(['expired_at', 'self_approved']);
        });

        DB::statement("ALTER TABLE sale_discount_requests MODIFY status ENUM('pending','approved','declined') NOT NULL DEFAULT 'pending'");
        DB::statement("ALTER TABLE sale_discount_requests ADD CONSTRAINT sale_discount_requests_decision_complete CHECK ((status = 'pending' AND decided_by IS NULL AND decided_at IS NULL) OR (status <> 'pending' AND decided_by IS NOT NULL AND decided_at IS NOT NULL))");
    }

    // DROP CONSTRAINT, not DROP CHECK: MariaDB rejects the MySQL-only spelling.
    private function dropCheckIfExists(string $constraint): void
    {
        $exists = DB::selectOne(
            'SELECT 1 AS found FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = database() AND CONSTRAINT_NAME = ?',
            [$constraint],
        );

        if ($exists !== null) {
            DB::statement('ALTER TABLE sale_discount_requests DROP CONSTRAINT '.$constraint);
        }
    }
};
